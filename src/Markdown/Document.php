<?php

declare(strict_types=1);

namespace Snippet\Markdown;

use Generator;

use function count;
use function mb_substr;

/** A compact parsed document backed by one source string and flat node arenas. */
final readonly class Document
{
    /**
     * @param list<Block> $blocks
     * @param list<ListItem> $listItems
     */
    public function __construct(
        public string $source,
        public array $blocks,
        private array $listItems,
        private InlineArena $inlineNodes,
    ) {}

    /** @return Generator<int, Inline> */
    public function inlines(Paragraph|Heading|ListItem $owner): Generator
    {
        return $this->inlineNodes->range($owner->inlineOffset, $owner->inlineCount);
    }

    /** @return Generator<int, ListItem> */
    public function items(FlatList $list): Generator
    {
        $end = $list->itemOffset + $list->itemCount;

        for ($index = $list->itemOffset; $index < $end; ++$index) {
            yield $this->listItems[$index];
        }
    }

    /** @return Generator<int, Link> authored links in source order */
    public function links(): Generator
    {
        foreach ($this->inlineNodes->range(0, $this->inlineNodes->count()) as $inline) {
            if ($inline instanceof Link) {
                yield $inline;
            }
        }
    }

    /** Count every block, list item, and inline node retained by this document. */
    public function nodeCount(): int
    {
        return count($this->blocks) + count($this->listItems) + $this->inlineNodes->count();
    }

    /** Resolve a source-backed node without storing a duplicate string in it. */
    public function text(Text|InlineCode|Link|CodeBlock $node): string
    {
        return mb_substr($this->source, $node->offset, $node->length, '8bit');
    }
}
