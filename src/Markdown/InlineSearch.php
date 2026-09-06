<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/**
 * Reuses lookahead while parsing one validated UTF-8 source, keeping one cached
 * match per delimiter instead of storing every delimiter position.
 *
 * Ranges use byte offsets and constrain the delimiter's first byte. A cached
 * match may lie beyond a nested range and become usable in its parent range.
 */
final class InlineSearch
{
    /** @var array<string, array{start: int, position: int|false}> */
    private array $matches = [];

    public function __construct(private readonly string $source) {}

    /** @param non-empty-string $delimiter */
    public function find(string $delimiter, int $start, int $end): ?int
    {
        return $this->search($delimiter, $start, $end, false);
    }

    /** @param non-empty-string $delimiter */
    public function styleEnd(string $delimiter, int $start, int $end): ?int
    {
        return $this->search($delimiter, $start, $end, true);
    }

    /** The offset must identify a character boundary within the source. */
    public function isNonWhitespaceAt(int $offset): bool
    {
        // Valid UTF-8 starts with ASCII below 0x80 or a multibyte lead byte of at least 0xC2.
        if (ord($this->source[$offset]) < 0x80) { // @pest-mutate-ignore: SmallerToSmallerOrEqual,DecrementInteger,IncrementInteger
            return match ($this->source[$offset]) { // @pest-mutate-ignore: RemoveEarlyReturn
                ' ', "\t", "\n", "\r", "\v", "\f" => false,
                default => true,
            };
        }

        return preg_match('/\G\S/u', $this->source, $match, 0, $offset) === 1;
    }

    /** @param non-empty-string $delimiter */
    private function search(string $delimiter, int $start, int $end, bool $afterText): ?int
    {
        if ($start >= $end) {
            return null;
        }

        // Only separation of the two modes matters; key spelling and order are private.
        $key = ($afterText ? 'style:' : 'literal:') . $delimiter; // @pest-mutate-ignore: TernaryNegated,ConcatSwitchSides
        $match = $this->matches[$key] ?? null;
        if ($match === null || $start < $match['start'] || ($match['position'] !== false && $start > $match['position'])) {
            $position = $this->position($delimiter, $start);
            if ($afterText) {
                while ($position !== false && ($position === 0 || !$this->isNonWhitespaceAt($this->previousCharacterOffset($position)))) {
                    $position = $this->position($delimiter, $position + 1);
                }
            }

            $match = $this->matches[$key] = ['start' => $start, 'position' => $position];
        }

        return $match['position'] !== false && $match['position'] < $end ? $match['position'] : null;
    }

    /** @param non-empty-string $delimiter */
    private function position(string $delimiter, int $start): int|false
    {
        // Supported delimiters contain one marker byte or two identical bytes.
        // strcspn searches bytes without converting the remaining UTF-8 source.
        $first = $delimiter[0]; // @pest-mutate-ignore: DecrementInteger
        $second = $delimiter[1] ?? null;
        $length = mb_strlen($this->source, '8bit');
        $position = $start + strcspn($this->source, $first, $start);
        while ($position < $length && $second !== null && ($this->source[$position + 1] ?? null) !== $second) {
            $position += 1 + strcspn($this->source, $first, $position + 1);
        }

        return $position === $length ? false : $position;
    }

    private function previousCharacterOffset(int $offset): int
    {
        --$offset;
        // A valid UTF-8 continuation byte cannot occur at source byte zero.
        while ($offset > 0 && (ord($this->source[$offset]) & 0xC0) === 0x80) { // @pest-mutate-ignore: GreaterToGreaterOrEqual,DecrementInteger,IncrementInteger
            --$offset;
        }

        return $offset;
    }
}
