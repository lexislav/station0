<?php

declare(strict_types=1);

namespace Station0\Support;

/**
 * Cleans an uploaded SVG so it cannot run script or load anything when opened
 * on its own (not only through `/media`, whose CSP sandbox covers that route).
 *
 * Allow-list based: elements outside the SVG drawing vocabulary are dropped
 * with their content (`script`, `foreignObject`, `iframe`, editor metadata in
 * other namespaces …), and on the elements that stay every `on*` attribute,
 * every link that is not a `#fragment` or a raster `data:image/…` and every
 * `url(…)` / CSS pointing elsewhere is removed. A DOCTYPE with an internal
 * subset (entities) or a file that does not parse as an `<svg>` is rejected;
 * a plain `<!DOCTYPE svg PUBLIC …>` (never loaded) is dropped.
 *
 * A file that needs no change is returned byte-for-byte.
 */
final class SvgSanitizer
{
    private const SVG_NS   = 'http://www.w3.org/2000/svg';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XML_NS   = 'http://www.w3.org/XML/1998/namespace';

    /** SVG elements kept (case-sensitive local names, SVG namespace only). */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch', 'view', 'a',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath', 'image', 'marker', 'style',
        'clipPath', 'mask', 'pattern', 'linearGradient', 'radialGradient', 'stop',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
        'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
        'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
        'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset',
        'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence',
        'animate', 'animateMotion', 'animateTransform', 'mpath', 'set',
    ];

    /** Animation elements: dropped when they target a link or an event handler. */
    private const ANIMATIONS = ['animate', 'animateMotion', 'animateTransform', 'set'];

    /** The only link targets kept: same-document fragments and raster data URIs. */
    private const SAFE_REF = '~^(?:#[^\s]*|data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]*)$~i';

    /** Script-ish values anywhere in an attribute or stylesheet. */
    private const UNSAFE_VALUE = '~javascript:|vbscript:|data:text/html|expression\(|-moz-binding|behavior\s*:~i';

    /**
     * @return array{svg: string, removed: list<string>} `removed` names what
     *         was dropped (`<script>`, `@onload`, `<!DOCTYPE>` …), empty when
     *         `svg` is the input unchanged
     * @throws \RuntimeException when the file is not a usable SVG
     */
    public static function sanitize(string $svg): array
    {
        // Entity declarations are refused before libxml sees them (no
        // expansion bombs, no external entities, whatever the flags).
        if (preg_match('~<!DOCTYPE[^>\[]*\[|<!ENTITY~i', $svg)) {
            throw new \RuntimeException('SVG rejected: it declares a DOCTYPE with entities. Re-export it as plain SVG.');
        }

        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT (no entity substitution), no DTD loading, no network.
            $ok = $svg !== '' && $doc->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if (!$ok || $root === null || $root->namespaceURI !== self::SVG_NS || $root->localName !== 'svg') {
            throw new \RuntimeException('SVG rejected: the file is not a valid SVG image.');
        }

        $removed = [];
        if ($doc->doctype !== null) {
            $doc->removeChild($doc->doctype);
            $removed[] = '<!DOCTYPE>';
        }
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction) {
                $doc->removeChild($node);
                $removed[] = '<?' . $node->target . '?>';
            }
        }
        self::clean($root, $removed);

        return ['svg' => $removed === [] ? $svg : (string) $doc->saveXML(), 'removed' => $removed];
    }

    /** @param list<string> $removed */
    private static function clean(\DOMElement $el, array &$removed): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            /** @var \DOMAttr $attr */
            if (!self::attributeAllowed($attr)) {
                $el->removeAttributeNode($attr);
                $removed[] = '@' . $attr->nodeName;
            }
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (self::elementAllowed($child)) {
                    self::clean($child, $removed);
                } else {
                    $el->removeChild($child);
                    $removed[] = '<' . $child->nodeName . '>';
                }
            } elseif ($child instanceof \DOMProcessingInstruction) {
                $el->removeChild($child);
                $removed[] = '<?' . $child->target . '?>';
            }
        }
    }

    private static function elementAllowed(\DOMElement $el): bool
    {
        if ($el->namespaceURI !== self::SVG_NS || !in_array($el->localName, self::ELEMENTS, true)) {
            return false;
        }
        if (in_array($el->localName, self::ANIMATIONS, true)) {
            $target = strtolower(trim($el->getAttribute('attributeName')));
            if (str_ends_with($target, 'href') || str_starts_with($target, 'on')) {
                return false;
            }
        }
        if ($el->localName === 'style') {
            return !self::cssUnsafe((string) $el->textContent);
        }
        return true;
    }

    private static function attributeAllowed(\DOMAttr $attr): bool
    {
        $ns   = $attr->namespaceURI;
        $name = strtolower((string) $attr->localName);

        if ($ns === self::XML_NS) {
            return $name === 'space' || $name === 'lang';
        }
        if ($ns !== null && $ns !== self::XLINK_NS) {
            return false;   // inkscape:*, sodipodi:*, …
        }
        if ($name === 'href') {
            return preg_match(self::SAFE_REF, trim($attr->value)) === 1;
        }
        if ($ns === self::XLINK_NS || str_starts_with($name, 'on')) {
            return false;
        }
        return !self::cssUnsafe($attr->value);
    }

    /**
     * `@import`, script-ish values, a `url()` that is not a fragment / raster
     * data URI, or CSS hex escapes (which could spell either of those).
     */
    private static function cssUnsafe(string $css): bool
    {
        if (preg_match('~\\\\[0-9a-f]~i', $css)) {
            return true;
        }
        $css = self::squash($css);
        if (stripos($css, '@import') !== false || preg_match(self::UNSAFE_VALUE, $css)) {
            return true;
        }
        preg_match_all('~url\(\s*([\'"]?)(.*?)\1\s*\)~is', $css, $m);
        foreach ($m[2] as $ref) {
            if (preg_match(self::SAFE_REF, trim($ref)) !== 1) {
                return true;
            }
        }
        return false;
    }

    /** Drop whitespace / control characters and CSS escapes browsers ignore inside `java\script:`. */
    private static function squash(string $value): string
    {
        return (string) preg_replace('~[\x00-\x20\\\\]+~', '', $value);
    }
}
