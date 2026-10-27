<?php

declare(strict_types=1);

namespace Station0\Tests\Plugin;

use PHPUnit\Framework\TestCase;

/** The `markdown` Twig filter as Bootstrap registers it (issue #27). */
final class MarkdownFilterAppTest extends TestCase
{
    use BootsApp;

    public function testBlockAndPageTemplatesRenderMarkdown(): void
    {
        $app = $this->boot([], null, '{{ page.extra.intro|markdown }}|{{ content|raw }}', function (TestSite $site): void {
            $site->write('site/templates/blocks/callout/schema.yaml', "label: Callout\nfields:\n  body:\n    type: textarea\n");
            $site->write('site/templates/blocks/callout/template.twig', '<aside>{{ block.body|markdown }}</aside>');
            $site->write('site/templates/blocks/legacy/schema.yaml', "label: Legacy\nfields:\n  body:\n    type: textarea\n");
            $site->write('site/templates/blocks/legacy/template.twig', '{{ block.body|markdown_to_html }}');
            $site->write('site/content/pages/about/page.txt', "Title: About\nStatus: published\nIntro: Hi ![a](a.png)\n---\n"
                . "- type: callout\n  body: \"**Bold** ![t](team.jpg)\"\n- type: legacy\n  body: '*em*'\n");
        });

        $html = (string) $this->get($app, '/about')->getBody();

        self::assertStringContainsString('<p>Hi <img src="/media/about/a.png" alt="a" /></p>', $html);
        self::assertStringContainsString('<aside><p><strong>Bold</strong> <img src="/media/about/team.jpg" alt="t" /></p>', $html);
        self::assertStringContainsString('<p><em>em</em></p>', $html);
    }
}
