<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use NoDiscard;

/**
 * Conservatively compacts inter-tag whitespace without rewriting HTML tokens.
 *
 * Any uncertain or unterminated construct causes the original document to be
 * returned, making malformed input safer than a partial transformation.
 */
final readonly class HtmlMinifier
{
    private const array RAW_ELEMENTS = ['pre', 'code', 'textarea', 'script', 'style'];

    private const string CHUNK_PATTERN = <<<'REGEX'
~\G(?:[^<]+|<!--(?:(?!-->)[\s\S])*-->|(?!<!--)<(?:[^"'<>]|"[^"]*"|'[^']*')*>)~A
REGEX;

    #[NoDiscard('the minified HTML should be written or otherwise consumed')]
    public function minify(string $html): string
    {
        $length = mb_strlen($html, '8bit');
        $offset = 0;
        $output = '';
        $previousWasTag = false;
        while ($offset < $length) {
            if (preg_match(self::CHUNK_PATTERN, $html, $match, 0, $offset) !== 1) {
                return $html;
            }

            $chunk = $match[0];
            $offset += mb_strlen($chunk, '8bit');
            if ($chunk[0] !== '<') {
                $output .= $previousWasTag && $offset < $length && $this->whitespaceOnly($chunk) ? ' ' : $chunk;
                continue;
            }

            $output .= $chunk;
            $name = $this->openingTagName($chunk);
            if (in_array($name, self::RAW_ELEMENTS, true) && !str_ends_with(mb_rtrim($chunk), '/>')) {
                $pattern = '~\G(?:(?!</' . preg_quote($name, '~') . '(?=[\s>]))[\s\S])*</' . preg_quote($name, '~') . '\s*>~Ai';
                if (preg_match($pattern, $html, $match, 0, $offset) !== 1) {
                    return $html;
                }

                $raw = $match[0];
                $output .= $raw;
                $offset += mb_strlen($raw, '8bit');
            }

            $previousWasTag = true;
        }

        return $output;
    }

    private function whitespaceOnly(string $text): bool
    {
        return preg_match('/\A[ \t\n\r\f]+\z/D', $text) === 1;
    }

    private function openingTagName(string $token): ?string
    {
        // preg_match() returns only 1, 0, or false, so neighbouring integer comparisons are equivalent.
        if (preg_match('/\A<([A-Za-z][A-Za-z0-9:-]*)(?:\s|\/?>)/', $token, $match) !== 1) { // @pest-mutate-ignore: DecrementInteger,IncrementInteger
            return null;
        }

        return mb_strtolower($match[1]);
    }
}
