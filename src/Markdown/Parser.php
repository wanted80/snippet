<?php

declare(strict_types=1);

namespace Snippet\Markdown;

use NoDiscard;
use Snippet\Exception\ContentException;

use function count;
use function filter_var;
use function mb_strlen;
use function mb_substr;
use function mb_substr_count;
use function mb_trim;
use function preg_match;
use function preg_replace;
use function strcspn;

/** Parses the supported Markdown subset into compact source-backed node arenas. */
final class Parser
{
    /**
     * Parse an article while retaining its path for actionable syntax errors.
     *
     * Unsupported Markdown remains literal text; unsafe links and unclosed code
     * fences fail the complete parse.
     *
     * @throws ContentException when supported syntax is malformed or unsafe
     */
    #[NoDiscard('the parsed document should be consumed')]
    public function parse(string $source, string $path, int $maximumDepth = 16): Document
    {
        $source = $this->normalize($source, $path);
        $length = mb_strlen($source, '8bit');
        $blocks = [];
        $listItems = [];
        $inlineNodes = new InlineBuilder();
        $lineStart = 0;
        $lineNumber = 1;
        $previousHeadingLevel = null;

        while ($lineStart < $length) {
            $lineEnd = $this->lineEnd($source, $lineStart, $length);
            if ($lineStart === $lineEnd) {
                $lineStart = $this->nextLineStart($lineEnd, $length);
                ++$lineNumber;
                continue;
            }

            $fence = $this->openingFence($source, $lineStart, $lineEnd);
            if ($fence !== null) {
                $openingLine = $lineNumber;
                $codeStart = $this->nextLineStart($lineEnd, $length);
                $closingStart = $codeStart;

                while ($closingStart < $length) {
                    $closingEnd = $this->lineEnd($source, $closingStart, $length);
                    if ($this->isClosingFence($source, $closingStart, $closingEnd)) {
                        break;
                    }

                    $closingStart = $this->nextLineStart($closingEnd, $length);
                    ++$lineNumber;
                }

                if ($closingStart === $length) {
                    throw new ContentException(sprintf("Unclosed code fence in '%s' at line %d.", $path, $openingLine));
                }

                $codeEnd = $closingStart;
                if ($codeEnd > $codeStart && $source[$codeEnd - 1] === "\n") {
                    --$codeEnd;
                }

                $blocks[] = new CodeBlock($codeStart, $codeEnd - $codeStart, $fence[0]);
                $closingEnd = $this->lineEnd($source, $closingStart, $length);
                $lineStart = $this->nextLineStart($closingEnd, $length);
                $lineNumber += 2;
                continue;
            }

            if ($this->isThematicBreak($source, $lineStart, $lineEnd)) {
                $blocks[] = new ThematicBreak();
                $lineStart = $this->nextLineStart($lineEnd, $length);
                ++$lineNumber;
                continue;
            }

            $heading = $this->heading($source, $lineStart, $lineEnd);
            if ($heading !== null) {
                if ($previousHeadingLevel === null && $heading[0] !== 1) {
                    throw new ContentException(sprintf("First heading in '%s' must be level 1 at line %d.", $path, $lineNumber));
                }
                if ($previousHeadingLevel !== null && $heading[0] > $previousHeadingLevel + 1) {
                    throw new ContentException(sprintf("Heading level in '%s' skips from %d to %d at line %d.", $path, $previousHeadingLevel, $heading[0], $lineNumber));
                }
                $previousHeadingLevel = $heading[0];
                $inlineOffset = $inlineNodes->count();
                $this->parseInline($source, $heading[1], $heading[1] + $heading[2], $path, $lineNumber, $inlineNodes, 0, $maximumDepth);
                $blocks[] = new Heading($heading[0], $inlineOffset, $inlineNodes->count() - $inlineOffset);
                $lineStart = $this->nextLineStart($lineEnd, $length);
                ++$lineNumber;
                continue;
            }

            $list = $this->listMarker($source, $lineStart, $lineEnd);
            if ($list !== null) {
                $ordered = $list[0];
                $itemOffset = count($listItems);

                while ($list !== null && $list[0] === $ordered) {
                    $inlineOffset = $inlineNodes->count();
                    $this->parseInline($source, $list[1], $list[1] + $list[2], $path, $lineNumber, $inlineNodes, 0, $maximumDepth);
                    $listItems[] = new ListItem($inlineOffset, $inlineNodes->count() - $inlineOffset);
                    $lineStart = $this->nextLineStart($lineEnd, $length);
                    ++$lineNumber;

                    if ($lineStart >= $length) {
                        break;
                    }

                    $lineEnd = $this->lineEnd($source, $lineStart, $length);
                    $list = $this->listMarker($source, $lineStart, $lineEnd);
                }

                $blocks[] = new FlatList($ordered, $itemOffset, count($listItems) - $itemOffset);
                continue;
            }

            $paragraphStart = $lineStart;
            $paragraphEnd = $lineEnd;
            $paragraphLine = $lineNumber;

            while (true) {
                $nextStart = $this->nextLineStart($lineEnd, $length);
                if ($nextStart >= $length) { // @pest-mutate-ignore: GreaterOrEqualToGreater
                    $lineStart = $length;
                    break;
                }

                $nextEnd = $this->lineEnd($source, $nextStart, $length);
                if ($nextStart === $nextEnd || $this->startsBlock($source, $nextStart, $nextEnd)) {
                    $lineStart = $nextStart;
                    ++$lineNumber;
                    break;
                }

                $lineStart = $nextStart;
                $lineEnd = $nextEnd;
                $paragraphEnd = $nextEnd;
                ++$lineNumber;
            }

            $inlineOffset = $inlineNodes->count();
            $this->parseInline($source, $paragraphStart, $paragraphEnd, $path, $paragraphLine, $inlineNodes, 0, $maximumDepth);
            $blocks[] = new Paragraph($inlineOffset, $inlineNodes->count() - $inlineOffset);
        }

        return new Document($source, $blocks, $listItems, $inlineNodes->finish());
    }

    private function normalize(string $source, string $path): string
    {
        if (preg_match('//u', $source) !== 1) {
            throw new ContentException(sprintf("Article '%s' is not valid UTF-8.", $path));
        }

        if (preg_match('/[\r\x0B\x0C\x{0085}\x{2028}\x{2029}]/u', $source) !== 1) {
            return $source; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $normalized = preg_replace('/\r\n|[\r\x0B\x0C\x{0085}\x{2028}\x{2029}]/u', "\n", $source);

        return (string) $normalized; // @pest-mutate-ignore: RemoveStringCast
    }

    private function lineEnd(string $source, int $start, int $length): int
    {
        return $this->findCharacter($source, "\n", $start, $length) ?? $length;
    }

    private function nextLineStart(int $lineEnd, int $length): int
    {
        return $lineEnd < $length ? $lineEnd + 1 : $length;
    }

    /** @return array{?string}|null */
    private function openingFence(string $source, int $start, int $end): ?array
    {
        if (mb_substr($source, $start, 3, '8bit') !== '```') {
            return null;
        }

        $cursor = $start + 3;
        $languageStart = $cursor;
        while ($cursor < $end && $this->isLanguageCharacter($source[$cursor])) {
            ++$cursor;
        }

        $language = $cursor === $languageStart ? null : mb_substr($source, $languageStart, $cursor - $languageStart, '8bit');
        while ($cursor < $end && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
            ++$cursor;
        }

        return $cursor === $end ? [$language] : null;
    }

    private function isThematicBreak(string $source, int $start, int $end): bool
    {
        return preg_match('/^(?:-{3,}|\*{3,})[ \t]*$/D', mb_substr($source, $start, $end - $start, '8bit')) === 1;
    }

    private function isClosingFence(string $source, int $start, int $end): bool
    {
        if (mb_substr($source, $start, 3, '8bit') !== '```') {
            return false;
        }

        $cursor = $start + 3;
        while ($cursor < $end && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
            ++$cursor;
        }

        return $cursor === $end;
    }

    private function isLanguageCharacter(string $character): bool
    {
        return ($character >= 'A' && $character <= 'Z')
            || ($character >= 'a' && $character <= 'z')
            || ($character >= '0' && $character <= '9')
            || $character === '_'
            || $character === '-';
    }

    /** @return array{int, int, int}|null */
    private function heading(string $source, int $start, int $end): ?array
    {
        $cursor = $start;
        while ($cursor < $end && $source[$cursor] === '#') { // @pest-mutate-ignore: SmallerToSmallerOrEqual
            ++$cursor;
        }

        $level = $cursor - $start;
        if ($level < 1 || $level > 3 || $cursor === $end || ($source[$cursor] !== ' ' && $source[$cursor] !== "\t")) {
            return null;
        }

        while ($cursor + 1 < $end && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
            ++$cursor;
        }

        return [$level, $cursor, $end - $cursor];
    }

    /** @return array{bool, int, int}|null */
    private function listMarker(string $source, int $start, int $end): ?array
    {
        $first = $source[$start];
        $cursor = $start;
        $ordered = false;

        if ($first === '-' || $first === '*') {
            ++$cursor;
        } elseif ($first >= '0' && $first <= '9') {
            $ordered = true;
            while ($cursor < $end && $source[$cursor] >= '0' && $source[$cursor] <= '9') { // @pest-mutate-ignore: SmallerToSmallerOrEqual
                ++$cursor;
            }

            if ($cursor === $end || $source[$cursor] !== '.') {
                return null;
            }

            ++$cursor;
        } else {
            return null;
        }

        if ($cursor === $end || ($source[$cursor] !== ' ' && $source[$cursor] !== "\t")) {
            return null;
        }

        while ($cursor + 1 < $end && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
            ++$cursor;
        }

        return [$ordered, $cursor, $end - $cursor];
    }

    private function startsBlock(string $source, int $start, int $end): bool
    {
        $first = $source[$start];
        if ($first === '`' && $this->openingFence($source, $start, $end) !== null) {
            return true;
        }
        if ($first === '#' && $this->heading($source, $start, $end) !== null) {
            return true;
        }
        if (($first === '-' || $first === '*') && $this->isThematicBreak($source, $start, $end)) {
            return true;
        }
        return $this->listMarker($source, $start, $end) !== null;
    }

    private function parseInline(
        string $source,
        int $start,
        int $end,
        string $path,
        int $line,
        InlineBuilder $nodes,
        int $depth,
        int $maximumDepth,
    ): void {
        if ($depth > $maximumDepth) {
            throw new ContentException(sprintf("Markdown '%s' exceeds nested inline depth %d at line %d.", $path, $maximumDepth, $line));
        }

        $offset = $start;
        $plainStart = $start;
        $lineOffset = $start;

        while ($offset < $end) { // @pest-mutate-ignore: SmallerToSmallerOrEqual
            $offset += strcspn($source, '`[*~', $offset, $end - $offset);
            $line += $this->lineBreaks($source, $lineOffset, $offset);
            $lineOffset = $offset;
            if ($offset === $end) {
                break; // @pest-mutate-ignore: BreakToContinue
            }

            /** @var '`'|'['|'*'|'~' $character */
            $character = $source[$offset];
            $next = match ($character) {
                '`' => $this->inlineCode($source, $offset, $end, $nodes, $plainStart),
                '[' => $this->link($source, $offset, $end, $path, $line, $nodes, $plainStart, $depth, $maximumDepth),
                '*' => $this->styleAt($source, $offset, $end, $path, $line, $nodes, $plainStart, $depth, $maximumDepth),
                '~' => $this->strikeAt($source, $offset, $end, $path, $line, $nodes, $plainStart, $depth, $maximumDepth),
            };

            if ($next !== null) {
                $line += $this->lineBreaks($source, $lineOffset, $next);
                $lineOffset = $next;
                $offset = $next;
                $plainStart = $next;
                continue;
            }

            ++$offset;
        }

        $this->appendText($plainStart, $end, $nodes);
    }

    private function lineBreaks(string $source, int $start, int $end): int
    {
        return $start === $end ? 0 : mb_substr_count(mb_substr($source, $start, $end - $start, '8bit'), "\n");
    }

    private function strikeAt(
        string $source,
        int $offset,
        int $end,
        string $path,
        int $line,
        InlineBuilder $nodes,
        int $plainStart,
        int $depth,
        int $maximumDepth,
    ): ?int {
        if (($source[$offset + 1] ?? null) !== "~") {
            return null;
        }
        $closing = $this->findDelimiter($source, '~~', $offset + 2, $end);
        if ($closing === null || $closing === $offset + 2) {
            return null;
        }
        $this->appendText($plainStart, $offset, $nodes);
        $nodes->marker(InlineMarker::StrikethroughStart);
        $this->parseInline($source, $offset + 2, $closing, $path, $line, $nodes, $depth + 1, $maximumDepth);
        $nodes->marker(InlineMarker::StrikethroughEnd);
        return $closing + 2;
    }

    private function styleAt(
        string $source,
        int $offset,
        int $end,
        string $path,
        int $line,
        InlineBuilder $nodes,
        int $plainStart,
        int $depth,
        int $maximumDepth,
    ): ?int {
        if (($source[$offset + 1] ?? null) === '*') {
            $strong = $this->style(
                $source,
                $offset,
                $end,
                '**',
                InlineMarker::StrongStart,
                InlineMarker::StrongEnd,
                $path,
                $line,
                $nodes,
                $plainStart,
                $depth,
                $maximumDepth,
            );

            if ($strong !== null) {
                return $strong;
            }
        }

        return $this->style(
            $source,
            $offset,
            $end,
            '*',
            InlineMarker::EmphasisStart,
            InlineMarker::EmphasisEnd,
            $path,
            $line,
            $nodes,
            $plainStart,
            $depth,
            $maximumDepth,
        );
    }

    private function inlineCode(string $source, int $offset, int $end, InlineBuilder $nodes, int $plainStart): ?int
    {
        $closing = $this->findCharacter($source, '`', $offset + 1, $end);
        if ($closing === null || $closing === $offset + 1) {
            return null;
        }

        if (strcspn($source, "\n", $offset + 1, $closing - $offset - 1) !== $closing - $offset - 1) {
            return null;
        }

        $this->appendText($plainStart, $offset, $nodes);
        $nodes->code($offset + 1, $closing - $offset - 1);

        return $closing + 1;
    }

    private function link(
        string $source,
        int $offset,
        int $end,
        string $path,
        int $line,
        InlineBuilder $nodes,
        int $plainStart,
        int $depth,
        int $maximumDepth,
    ): ?int {
        if ($source[$offset] !== '[' || ($offset > 0 && $source[$offset - 1] === '!')) {
            return null;
        }

        $labelEnd = $this->findCharacter($source, ']', $offset + 1, $end); // @pest-mutate-ignore: DecrementInteger
        if (
            $labelEnd === null
            || ($source[$labelEnd + 1] ?? null) !== '('
            || strcspn($source, "\n", $offset + 1, $labelEnd - $offset - 1) !== $labelEnd - $offset - 1
        ) {
            return null;
        }

        $targetStart = $labelEnd + 2;
        $targetEnd = $this->findCharacter($source, ')', $targetStart, $end);
        if (
            $targetEnd === null
            || $targetEnd === $targetStart
            || strcspn($source, "\n", $targetStart, $targetEnd - $targetStart) !== $targetEnd - $targetStart
        ) {
            return null;
        }

        $label = mb_substr($source, $offset + 1, $labelEnd - $offset - 1, '8bit');
        if (mb_trim($label) === '') {
            throw new ContentException(sprintf("Link label in '%s' must not be blank at line %d.", $path, $line));
        }

        $target = mb_substr($source, $targetStart, $targetEnd - $targetStart, '8bit');
        $this->validateLink($target, $path, $line);
        $this->appendText($plainStart, $offset, $nodes);
        $nodes->link($targetStart, $targetEnd - $targetStart);
        $this->parseInline($source, $offset + 1, $labelEnd, $path, $line, $nodes, $depth + 1, $maximumDepth);
        $nodes->marker(InlineMarker::LinkEnd);

        return $targetEnd + 1;
    }

    private function style(
        string $source,
        int $offset,
        int $end,
        string $delimiter,
        InlineMarker $open,
        InlineMarker $close,
        string $path,
        int $line,
        InlineBuilder $nodes,
        int $plainStart,
        int $depth,
        int $maximumDepth,
    ): ?int {
        $delimiterLength = $delimiter === '**' ? 2 : 1;
        $contentStart = $offset + $delimiterLength;
        if ($contentStart >= $end || !$this->isNonWhitespaceAt($source, $contentStart)) {
            return null;
        }

        $closing = $this->findDelimiter($source, $delimiter, $contentStart, $end);
        while ($closing !== null) {
            $secondCharacter = $this->nextCharacterOffset($source, $contentStart);
            $lastCharacter = $this->previousCharacterOffset($source, $closing);
            if ($secondCharacter < $closing && $this->isNonWhitespaceAt($source, $lastCharacter)) {
                $this->appendText($plainStart, $offset, $nodes);
                $nodes->marker($open);
                $this->parseInline($source, $contentStart, $closing, $path, $line, $nodes, $depth + 1, $maximumDepth);
                $nodes->marker($close);

                return $closing + $delimiterLength;
            }

            $closing = $this->findDelimiter($source, $delimiter, $closing + 1, $end);
        }

        return null;
    }

    private function findCharacter(string $source, string $character, int $start, int $end): ?int
    {
        $distance = strcspn($source, $character, $start, $end - $start);

        return $distance === $end - $start ? null : $start + $distance;
    }

    private function findDelimiter(string $source, string $delimiter, int $start, int $end): ?int
    {
        // Every supported one- or two-byte delimiter repeats the same marker byte.
        $position = $this->findCharacter($source, $delimiter[0], $start, $end); // @pest-mutate-ignore: DecrementInteger
        if (!isset($delimiter[1])) {
            return $position;
        }

        while ($position !== null && ($source[$position + 1] ?? null) !== $delimiter[1]) { // @pest-mutate-ignore: DecrementInteger
            $position = $this->findCharacter($source, $delimiter[0], $position + 1, $end); // @pest-mutate-ignore: IncrementInteger
        }

        return $position;
    }

    private function nextCharacterOffset(string $source, int $offset): int
    {
        ++$offset;
        while (isset($source[$offset]) && (ord($source[$offset]) & 0xC0) === 0x80) {
            ++$offset;
        }

        return $offset;
    }

    private function previousCharacterOffset(string $source, int $offset): int
    {
        --$offset;
        // A valid UTF-8 continuation byte cannot occur at source byte zero.
        while ($offset > 0 && (ord($source[$offset]) & 0xC0) === 0x80) { // @pest-mutate-ignore: GreaterToGreaterOrEqual,DecrementInteger,IncrementInteger
            --$offset;
        }

        return $offset;
    }

    private function isNonWhitespaceAt(string $source, int $offset): bool
    {
        // Valid UTF-8 starts with ASCII below 0x80 or a multibyte lead byte of at least 0xC2.
        if (ord($source[$offset]) < 0x80) { // @pest-mutate-ignore: SmallerToSmallerOrEqual,DecrementInteger,IncrementInteger
            return match ($source[$offset]) { // @pest-mutate-ignore: RemoveEarlyReturn
                ' ', "\t", "\n", "\r", "\v", "\f" => false,
                default => true,
            };
        }

        return preg_match('/\G\S/u', $source, $match, 0, $offset) === 1;
    }

    private function appendText(int $start, int $end, InlineBuilder $nodes): void
    {
        if ($start < $end) {
            $nodes->text($start, $end - $start);
        }
    }

    private function validateLink(string $target, string $path, int $line): void
    {
        $valid = preg_match('/[\x00-\x20\x7f]/', $target) !== 1
            && !str_starts_with($target, '//')
            && (
                (preg_match('#^https?://#i', $target) === 1 && filter_var($target, FILTER_VALIDATE_URL) !== false)
                || str_starts_with($target, '/') // @pest-mutate-ignore: StrStartsWithToStrEndsWith
                || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target) !== 1
            );

        if (!$valid) {
            throw new ContentException(sprintf("Unsafe link target '%s' in '%s' at line %d.", $target, $path, $line));
        }
    }
}
