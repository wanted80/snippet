<?php

declare(strict_types=1);

namespace Snippet\Markdown;

use Countable;
use Generator;
use Override;

use function ord;

/** Compact fixed-width storage decoded into typed inline views on demand. */
final readonly class InlineArena implements Countable
{
    private const int RECORD_BYTES = 9;

    /** @param int<0, max> $nodeCount */
    public function __construct(
        private string $data,
        private int $nodeCount,
    ) {}

    #[Override]
    /** @return int<0, max> */
    public function count(): int
    {
        return $this->nodeCount;
    }

    /** @return Generator<int, Inline> */
    public function range(int $offset, int $count): Generator
    {
        $end = $offset + $count;

        for ($index = $offset; $index < $end; ++$index) {
            $position = $index * self::RECORD_BYTES;
            $type = InlineType::from($this->data[$position]);
            $sourceOffset = $this->unsignedInt($position + 1);
            $length = $this->unsignedInt($position + 5);
            yield $type->decode($sourceOffset, $length);
        }
    }

    private function unsignedInt(int $position): int
    {
        return (ord($this->data[$position]) << 24)
            | (ord($this->data[$position + 1]) << 16)
            | (ord($this->data[$position + 2]) << 8)
            | ord($this->data[$position + 3]);
    }
}
