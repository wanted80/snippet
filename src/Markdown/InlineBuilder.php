<?php

declare(strict_types=1);

namespace Snippet\Markdown;

use Countable;
use Override;

use function pack;

/** Mutable parse-time writer for the immutable fixed-width inline arena. */
final class InlineBuilder implements Countable
{
    private string $data = '';

    /** @var int<0, max> */
    private int $nodeCount = 0;

    public function text(int $offset, int $length): void
    {
        $this->append(InlineType::Text, $offset, $length);
    }

    public function code(int $offset, int $length): void
    {
        $this->append(InlineType::Code, $offset, $length);
    }

    public function link(int $offset, int $length): void
    {
        $this->append(InlineType::Link, $offset, $length);
    }

    public function marker(InlineMarker $marker): void
    {
        $this->append(InlineType::fromMarker($marker), 0, 0);
    }

    #[Override]
    /** @return int<0, max> */
    public function count(): int
    {
        return $this->nodeCount;
    }

    public function finish(): InlineArena
    {
        return new InlineArena($this->data, $this->nodeCount);
    }

    private function append(InlineType $type, int $offset, int $length): void
    {
        $this->data .= pack('aNN', $type->value, $offset, $length);
        ++$this->nodeCount;
    }
}
