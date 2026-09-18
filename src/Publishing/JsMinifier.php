<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use NoDiscard;

/** Conservative byte scanner; uncertain lexical syntax leaves the entire asset unchanged. */
final readonly class JsMinifier
{
    /**
     * Collapse horizontal whitespace and remove ordinary comments, preserving all
     * token boundaries and line breaks. No parsing or expression rewriting occurs.
     * Repeated passes preserve the same bytes. Work is linear and temporary
     * storage is proportional to the input size.
     */
    #[NoDiscard('the minified JavaScript should be written or otherwise consumed')]
    public function minify(string $source): string
    {
        $length = mb_strlen($source, '8bit');
        $output = '';
        for ($offset = 0; $offset < $length; ++$offset) {
            $byte = $source[$offset];
            if (str_contains(" \t\v\f", $byte)) {
                if (!str_ends_with($output, ' ')) {
                    $output .= ' ';
                }
                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $start = $offset++;
                for (; $offset < $length; ++$offset) {
                    $current = $source[$offset];
                    if ($current === $byte) {
                        break;
                    }
                    if ($current === "\n" || $current === "\r") {
                        return $source;
                    }
                    if ($current === '\\') {
                        ++$offset;
                        if (($source[$offset] ?? null) === "\r" && ($source[$offset + 1] ?? null) === "\n") {
                            ++$offset;
                        }
                    }
                }
                if ($offset >= $length) {
                    return $source;
                }
                $output .= mb_substr($source, $start, $offset - $start + 1, '8bit');
                continue;
            }

            if ($byte === '/') {
                $kind = $source[$offset + 1] ?? null;
                if ($kind !== '/' && $kind !== '*') {
                    return $source;
                }
                $start = $offset;
                $offset += 2;
                $preserve = ($source[$offset] ?? null) === '!';
                $replacement = str_ends_with($output, ' ') ? '' : ' ';
                $closed = $kind !== '*';
                for (; $offset < $length; ++$offset) {
                    $current = $source[$offset];
                    if ($kind === '/' && ($current === "\r" || $current === "\n")) {
                        break;
                    }
                    if ($kind === '*' && $current === '*' && ($source[$offset + 1] ?? null) === '/') {
                        $offset += 2;
                        $closed = true;
                        break;
                    }
                    if (substr_compare($source, 'sourceMappingURL', $offset, 16) === 0
                        || substr_compare($source, 'sourceURL', $offset, 9) === 0
                        || substr_compare($source, "\u{2028}", $offset, 3) === 0
                        || substr_compare($source, "\u{2029}", $offset, 3) === 0) {
                        return $source;
                    }
                    if (substr_compare($source, '@license', $offset, 8) === 0 || substr_compare($source, '@preserve', $offset, 9) === 0) {
                        $preserve = true;
                    }
                    if ($current === "\n" || $current === "\r") {
                        $replacement .= $current;
                    }
                }
                if (!$closed) {
                    return $source;
                }
                $output .= $preserve ? mb_substr($source, $start, $offset - $start, '8bit') : $replacement;
                --$offset;
                continue;
            }

            if (($byte < '!' || $byte > '~') && $byte !== "\n" && $byte !== "\r"
                || str_contains('`\\#', $byte)
                || substr_compare($source, '<!--', $offset, 4) === 0
                || substr_compare($source, '-->', $offset, 3) === 0) {
                return $source;
            }
            $output .= $byte;
        }

        return $output;
    }
}
