<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Generator;
use Snippet\Content\Catalog;
use Snippet\Site\Config;

/** Streams the site's compact, metadata-only Markdown index for language models. */
final readonly class LlmsTxtRenderer
{
    public function __construct(
        private Config $config,
        private Catalog $catalog,
    ) {}

    /** @return Generator<int, string> deterministic chunks bounded by validated metadata sizes */
    public function render(): Generator
    {
        yield '# ' . $this->text($this->config->title) . "\n\n";
        yield '> ' . $this->text($this->config->description) . "\n\n";
        yield 'Author: ' . $this->text($this->config->author) . "\n";

        if ($this->catalog->articles !== []) {
            yield "\n## Articles\n";
            foreach ($this->catalog->articles as $article) {
                yield sprintf(
                    "\n- [%s](%s): %s (Published: %s)",
                    $this->text($article->title),
                    $this->config->canonicalUrl($article->url()),
                    $this->text($article->description),
                    $article->date,
                );
            }

            yield "\n";
        }

        if ($this->catalog->pages !== []) {
            yield "\n## Pages\n";
            foreach ($this->catalog->pages as $page) {
                yield sprintf(
                    "\n- [%s](%s): %s",
                    $this->text($page->title),
                    $this->config->canonicalUrl($page->url()),
                    $this->text($page->description),
                );
            }

            yield "\n";
        }
    }

    private function text(string $value): string
    {
        $singleLine = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return strtr($singleLine, [
            '\\' => '\\\\',
            "\x60" => "\\\x60",
            '*' => '\\*',
            '_' => '\\_',
            '{' => '\\{',
            '}' => '\\}',
            '[' => '\\[',
            ']' => '\\]',
            '<' => '\\<',
            '>' => '\\>',
            '(' => '\\(',
            ')' => '\\)',
            '!' => '\\!',
            '|' => '\\|',
            '~' => '\\~',
        ]);
    }
}
