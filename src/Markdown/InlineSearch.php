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

    /** @var array<string, array{start: int, end: int, position: ?int}> */
    private array $closings = [];

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

    /**
     * Find a closer outside complete code spans and, for formatting, links.
     * Starts must be parser boundaries, never inside a previously skipped span.
     * Unlike literal matches, these results depend on the enclosing range end.
     *
     * @param '*'|'**'|'~~'|']' $delimiter
     */
    public function closing(string $delimiter, int $start, int $end): ?int
    {
        $afterText = $delimiter === '*' || $delimiter === '**';
        $minimum = $afterText ? $start + 1 : $start;
        $match = $this->closings[$delimiter] ?? null;
        if ($match === null || $match['end'] !== $end || $start < $match['start'] || ($match['position'] !== null && $minimum > $match['position'])) {
            $position = $this->closingOutsideSpans($delimiter, $start, $minimum, $end, $afterText);
            $match = $this->closings[$delimiter] = ['start' => $start, 'end' => $end, 'position' => $position];
        }

        return $match['position'];
    }

    /** Find a non-empty, single-line code span; the offset must identify its opening backtick. */
    public function codeEnd(int $offset, int $end): ?int
    {
        $closing = $this->find('`', $offset + 1, $end);
        if ($closing === null || $closing === $offset + 1 || $this->find("\n", $offset, $closing) !== null) {
            return null;
        }

        return $closing;
    }

    /**
     * Find a syntactically complete link; the parser still validates its label and URL.
     * The offset must identify an opening bracket.
     *
     * @return array{int, int}|null Closing label bracket and target parenthesis.
     */
    public function linkEnd(int $offset, int $end): ?array
    {
        if ($offset > 0 && $this->source[$offset - 1] === '!') {
            return null;
        }

        // Including the opening bracket in this search cannot change its result.
        $labelEnd = $this->closing(']', $offset + 1, $end); // @pest-mutate-ignore: DecrementInteger
        if ($labelEnd === null || ($this->source[$labelEnd + 1] ?? null) !== '(' || $this->find("\n", $offset, $labelEnd) !== null) {
            return null;
        }

        $targetStart = $labelEnd + 2;
        $targetEnd = $this->find(')', $targetStart, $end);
        if ($targetEnd === null || $targetEnd === $targetStart || $this->find("\n", $targetStart, $targetEnd) !== null) {
            return null;
        }

        return [$labelEnd, $targetEnd];
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

    /** @param '*'|'**'|'~~'|']' $delimiter */
    private function closingOutsideSpans(string $delimiter, int $cursor, int $minimum, int $end, bool $afterText): ?int
    {
        $position = $this->search($delimiter, $minimum, $end, $afterText);
        while ($position !== null) {
            $cursor += strcspn($this->source, $delimiter === ']' ? '`' : '`[', $cursor, $position - $cursor);
            if ($cursor === $position) {
                return $position;
            }

            $spanEnd = $this->source[$cursor] === '`'
                ? $this->codeEnd($cursor, $end)
                : ($this->linkEnd($cursor, $end)[1] ?? null);
            $cursor = ($spanEnd ?? $cursor) + 1;
            $position = $this->search($delimiter, $cursor, $end, $afterText);
        }

        return null;
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
