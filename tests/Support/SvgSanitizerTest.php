<?php

declare(strict_types=1);

namespace Station0\Tests\Support;

use PHPUnit\Framework\TestCase;
use Station0\Support\SvgSanitizer;

final class SvgSanitizerTest extends TestCase
{
    private const OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';

    private static function clean(string $inner): string
    {
        return SvgSanitizer::sanitize(self::OPEN . $inner . '</svg>')['svg'];
    }

    public function testHarmlessIconPassesThroughByteForByte(): void
    {
        $icon = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- Generator: test -->\n" . self::OPEN . "\n"
            . '  <defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient>'
            . '<clipPath id="c"><rect width="10" height="10"/></clipPath></defs>' . "\n"
            . '  <g clip-path="url(#c)" style="fill: url( \'#g\' ); opacity:.5" xml:space="preserve">'
            . '<path d="M0 0h10v10z" fill="url(#g)"/><use href="#p" xlink:href="#p"/>'
            . '<text x="1" y="9" font-family="Arial">A &amp; B</text></g>' . "\n"
            . '  <image width="1" height="1" href="data:image/png;base64,iVBORw0KGgo="/>' . "\n"
            . '  <style>.a{fill:#000}</style><animate attributeName="opacity" from="0" to="1" dur="1s"/>' . "\n"
            . "</svg>\n";

        $result = SvgSanitizer::sanitize($icon);

        self::assertSame([], $result['removed']);
        self::assertSame($icon, $result['svg']);
    }

    public function testScriptAndEventHandlersAreRemoved(): void
    {
        $result = SvgSanitizer::sanitize(self::OPEN . '<script>alert(1)</script>'
            . '<rect width="5" height="5" onclick="alert(1)" ONLOAD="x()"/><g onmouseover="x()"><circle r="1"/></g></svg>');
        $out = $result['svg'];

        self::assertSame(['<script>', '@onclick', '@ONLOAD', '@onmouseover'], $result['removed']);
        self::assertStringNotContainsString('alert', $out);
        self::assertStringContainsString('<rect width="5" height="5"/>', $out);
        self::assertStringContainsString('<g><circle r="1"/></g>', $out);
    }

    public function testRootEventHandlerIsRemoved(): void
    {
        $result = SvgSanitizer::sanitize('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>');

        self::assertSame(['@onload'], $result['removed']);
        self::assertStringNotContainsString('onload', $result['svg']);
    }

    public function testForeignObjectAndEmbedsAreRemovedWithTheirContent(): void
    {
        $out = self::clean('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="https://x.test"/></body></foreignObject>'
            . '<iframe/><embed src="x.swf"/><object data="x"/><rect width="1" height="1"/>');

        self::assertStringNotContainsString('foreignObject', $out);
        self::assertStringNotContainsString('iframe', $out);
        self::assertStringNotContainsString('embed', $out);
        self::assertStringNotContainsString('object', $out);
        self::assertStringContainsString('<rect width="1" height="1"/>', $out);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeLinks(): iterable
    {
        yield 'javascript href'          => ['<a href="javascript:alert(1)"><rect width="1" height="1"/></a>'];
        yield 'javascript, obfuscated'   => ["<a href=\" jav&#x09;ascript:alert(1)\"><rect width=\"1\" height=\"1\"/></a>"];
        yield 'xlink javascript'         => ['<a xlink:href="javascript:alert(1)"><rect width="1" height="1"/></a>'];
        yield 'external use'             => ['<use href="https://evil.test/sprite.svg#x"/>'];
        yield 'external xlink image'     => ['<image xlink:href="//evil.test/x.png"/>'];
        yield 'relative image'           => ['<image href="other.png"/>'];
        yield 'svg data uri'             => ['<image href="data:image/svg+xml;base64,PHN2Zz4="/>'];
        yield 'html data uri'            => ['<a href="data:text/html,&lt;script&gt;"><rect width="1" height="1"/></a>'];
    }

    /** @dataProvider unsafeLinks */
    public function testUnsafeLinksAreRemoved(string $inner): void
    {
        $result = SvgSanitizer::sanitize(self::OPEN . $inner . '</svg>');

        self::assertNotSame([], $result['removed']);
        self::assertDoesNotMatchRegularExpression('~href=~', $result['svg']);
    }

    public function testExternalCssReferencesAreRemoved(): void
    {
        $out = self::clean('<style>@import url(https://evil.test/a.css);</style>'
            . '<style>.a{background:url(https://evil.test/track.png)}</style>'
            . '<style>.b{fill:u\72l(https://evil.test/x)}</style>'
            . '<rect width="1" height="1" fill="url(https://evil.test/p#g)"/>'
            . '<rect width="2" height="2" style="background-image:url(//evil.test/x.png)"/>');

        self::assertStringNotContainsString('evil.test', $out);
        self::assertStringNotContainsString('<style', $out);
        self::assertStringContainsString('<rect width="1" height="1"/>', $out);
        self::assertStringContainsString('<rect width="2" height="2"/>', $out);
    }

    public function testAnimationThatRewritesALinkIsRemoved(): void
    {
        $out = self::clean('<a href="#x"><set attributeName="href" to="javascript:alert(1)"/>'
            . '<animate attributeName="xlink:href" values="javascript:alert(1)"/>'
            . '<animate attributeName="onbegin" to="x"/><rect width="1" height="1"/></a>');

        self::assertStringNotContainsString('javascript', $out);
        self::assertStringNotContainsString('<set', $out);
        self::assertStringNotContainsString('<animate', $out);
        self::assertStringContainsString('<a href="#x"><rect width="1" height="1"/></a>', $out);
    }

    public function testEditorNamespacesAndProcessingInstructionsAreDropped(): void
    {
        $svg = '<?xml version="1.0"?><?xml-stylesheet href="https://evil.test/a.css"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"'
            . ' inkscape:version="1.3"><inkscape:grid/><path d="M0 0" inkscape:label="x"/></svg>';

        $result = SvgSanitizer::sanitize($svg);

        self::assertSame(['<?xml-stylesheet?>', '@inkscape:version', '<inkscape:grid>', '@inkscape:label'], $result['removed']);
        self::assertStringNotContainsString('evil.test', $result['svg']);
        self::assertStringContainsString('<path d="M0 0"/>', $result['svg']);
    }

    public function testDoctypeWithEntitiesIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DOCTYPE with entities');

        SvgSanitizer::sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>');
    }

    public function testEntityBombIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        SvgSanitizer::sanitize('<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>');
    }

    public function testPlainDoctypeIsDropped(): void
    {
        $result = SvgSanitizer::sanitize('<?xml version="1.0"?>' . "\n"
            . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');

        self::assertSame(['<!DOCTYPE>'], $result['removed']);
        self::assertStringNotContainsString('DOCTYPE', $result['svg']);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>', $result['svg']);
    }

    /** @return iterable<string, array{string}> */
    public static function notSvg(): iterable
    {
        yield 'empty'               => [''];
        yield 'broken xml'          => ['<svg xmlns="http://www.w3.org/2000/svg"><path></svg>'];
        yield 'html'                => ['<html><body><script>alert(1)</script></body></html>'];
        yield 'svg without xmlns'   => ['<svg><script>alert(1)</script></svg>'];
        yield 'xhtml root with svg' => ['<html xmlns="http://www.w3.org/1999/xhtml"><svg xmlns="http://www.w3.org/2000/svg"/></html>'];
    }

    /** @dataProvider notSvg */
    public function testNonSvgIsRejected(string $input): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid SVG');

        SvgSanitizer::sanitize($input);
    }
}
