<?php

declare(strict_types=1);

namespace Snippet\Rendering;

use LogicException;
use Snippet\Markdown\CodeBlock;
use Snippet\Markdown\Document;
use Snippet\Markdown\FlatList;
use Snippet\Markdown\Heading;
use Snippet\Markdown\InlineCode;
use Snippet\Markdown\InlineMarker;
use Snippet\Markdown\Link;
use Snippet\Markdown\ListItem;
use Snippet\Markdown\Paragraph;
use Snippet\Markdown\Text;
use Snippet\Markdown\ThematicBreak;

/** Serializes a validated Markdown document to escaped HTML. */
final class MarkdownHtmlRenderer
{
    /**
     * Render a validated document with escaped text and context-aware relative links.
     *
     * @throws LogicException when the document contains an unsupported node
     */
    public static function render(Document $document, int $headingOffset = 1, ?string $relativeLinkBase = null, string $basePath = ''): string
    {
        $html = '';
        foreach ($document->blocks as $block) {
            $html .= match (true) {
                $block instanceof Paragraph => '<p>' . self::inlines($document, $block, $relativeLinkBase, $basePath) . '</p>',
                $block instanceof Heading => '<h' . ($block->level + $headingOffset) . '>' . self::inlines($document, $block, $relativeLinkBase, $basePath) . '</h' . ($block->level + $headingOffset) . '>',
                $block instanceof FlatList => self::list($document, $block, $relativeLinkBase, $basePath),
                $block instanceof ThematicBreak => '<hr>',
                $block instanceof CodeBlock => '<pre><code' . ($block->language === null ? '' : ' class="language-' . self::escape($block->language) . '"') . '>' . self::escape($document->text($block)) . '</code></pre>',
                default => throw new LogicException('Unsupported Markdown block.'),
            } . "\n";
        }

        return $html;
    }

    private static function list(Document $document, FlatList $list, ?string $relativeLinkBase, string $basePath): string
    {
        $element = $list->ordered ? 'ol' : 'ul';
        $html = '<' . $element . ">\n";
        foreach ($document->items($list) as $item) {
            $html .= '<li>' . self::inlines($document, $item, $relativeLinkBase, $basePath) . "</li>\n";
        }

        return $html . '</' . $element . '>';
    }

    private static function inlines(Document $document, Paragraph|Heading|ListItem $owner, ?string $relativeLinkBase, string $basePath): string
    {
        $html = '';
        foreach ($document->inlines($owner) as $inline) {
            $html .= match (true) {
                $inline instanceof Text => self::escape($document->text($inline)),
                $inline instanceof InlineCode => '<code>' . self::escape($document->text($inline)) . '</code>',
                $inline instanceof Link => self::linkStart($document, $inline, $relativeLinkBase, $basePath),
                $inline === InlineMarker::LinkEnd => '</a>',
                $inline === InlineMarker::EmphasisStart => '<em>',
                $inline === InlineMarker::EmphasisEnd => '</em>',
                $inline === InlineMarker::StrongStart => '<strong>',
                $inline === InlineMarker::StrongEnd => '</strong>',
                $inline === InlineMarker::StrikethroughStart => '<s>',
                $inline === InlineMarker::StrikethroughEnd => '</s>',
                default => throw new LogicException('Unsupported Markdown inline.'),
            };
        }

        return $html;
    }

    private static function linkStart(Document $document, Link $link, ?string $relativeBase, string $basePath): string
    {
        $target = $document->text($link);
        if (str_starts_with($target, '/')) {
            $target = $basePath . $target;
        } elseif ($relativeBase !== null && !str_starts_with($target, '#') && preg_match('#^https?://#i', $target) !== 1) {
            $target = $relativeBase . $target;
        }

        return '<a href="' . self::escape($target) . '">';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
