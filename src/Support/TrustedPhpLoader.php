<?php

declare(strict_types=1);

namespace Snippet\Support;

use NoDiscard;
use ParseError;
use Snippet\Exception\ContentException;

/** Reads declarative PHP arrays without executing author-controlled code. */
final class TrustedPhpLoader
{
    /** @var list<array{int|null, string}> */
    private array $tokens = [];

    private int $cursor = 0;

    private string $path = '';

    /** @return array<string, mixed> */
    #[NoDiscard('the loaded configuration should be validated and consumed')]
    public function load(string $path, string $subject, int $maximumBytes = 16_384): array
    {
        $displaySubject = mb_ucfirst($subject, 'UTF-8');
        if (!is_file($path) || is_link($path)) {
            throw new ContentException("{$displaySubject} '{$path}' does not exist or is not a regular non-symlink file.");
        }
        $size = @filesize($path);
        if (!is_int($size) || $size > $maximumBytes) {
            throw new ContentException("{$displaySubject} at '{$path}' exceeds the {$maximumBytes}-byte file limit.");
        }
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new ContentException("Unable to read {$subject} at '{$path}'; it cannot be read.");
        }
        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $parseError) {
            throw new ContentException("Unable to parse {$subject} at '{$path}': {$parseError->getMessage()}", 0, $parseError);
        }
        $this->tokens = [];
        foreach ($rawTokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $this->tokens[] = [$token[0], $token[1]];
            } else {
                $this->tokens[] = [null, $token];
            }
        }
        $this->cursor = 0;
        $this->path = $path;
        $this->expect(T_OPEN_TAG);
        $this->expect(T_DECLARE);
        $this->expectText('(');
        $strictTypes = $this->take();
        if ($strictTypes[0] !== T_STRING || $strictTypes[1] !== 'strict_types') {
            $this->invalid();
        }
        $this->expectText('=');
        $one = $this->take();
        if ($one[0] !== T_LNUMBER || $one[1] !== '1') {
            $this->invalid();
        }
        $this->expectText(')');
        $this->expectText(';');
        $this->expect(T_RETURN);
        $value = $this->array();
        $this->expectText(';');
        if (($this->tokens[$this->cursor][0] ?? null) === T_CLOSE_TAG) {
            ++$this->cursor;
        }
        if ($this->cursor !== count($this->tokens) || !array_all(array_keys($value), static fn(int|string $key): bool => is_string($key))) {
            $this->invalid();
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<mixed, mixed> */
    private function array(): array
    {
        $opening = $this->take();
        if ($opening[1] === "[") {
            $closing = "]";
        } elseif ($opening[0] === T_ARRAY) {
            $this->expectText("(");
            $closing = ")";
        } else {
            $this->invalid();
        }
        $result = [];
        $maximumIntegerKey = null;
        while (($this->tokens[$this->cursor][1] ?? null) !== $closing) {
            $first = $this->value();
            if (($this->tokens[$this->cursor][0] ?? null) === T_DOUBLE_ARROW) {
                ++$this->cursor;
                if (!is_string($first) && !is_int($first)) {
                    $this->invalid();
                }
                if (array_key_exists($first, $result)) {
                    throw new ContentException("Declarative PHP file '{$this->path}' contains duplicate array key '{$first}'.");
                }
                $result[$first] = $this->value();
                $key = array_key_last($result);
                if (is_int($key) && ($maximumIntegerKey === null || $key > $maximumIntegerKey)) {
                    $maximumIntegerKey = $key;
                }
            } else {
                if ($maximumIntegerKey === PHP_INT_MAX) {
                    $this->invalid();
                }

                $maximumIntegerKey = $maximumIntegerKey === null ? 0 : $maximumIntegerKey + 1;
                $result[$maximumIntegerKey] = $first;
            }

            if (($this->tokens[$this->cursor][1] ?? null) !== ',') {
                break;
            }
            ++$this->cursor;
            if (($this->tokens[$this->cursor][1] ?? null) === $closing) {
                break;
            }
        }
        $this->expectText($closing);
        return $result;
    }

    private function value(): mixed
    {
        $token = $this->tokens[$this->cursor];
        if ($token[1] === "[" || $token[0] === T_ARRAY) {
            return $this->array();
        }
        ++$this->cursor;
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $contents = mb_substr($token[1], 1, -1);
            return $token[1][0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $contents) : $this->decodeDoubleQuotedString($contents);
        }
        if ($token[0] === T_LNUMBER && preg_match('/^(?:0|[1-9][0-9]*)$/D', $token[1]) === 1) {
            return (int) $token[1];
        }
        if ($token[0] === T_STRING) {
            return match (mb_strtolower($token[1])) {
                'null' => null,
                'true' => true,
                'false' => false,
                default => $this->invalid(),
            };
        }
        $this->invalid();
    }

    private function decodeDoubleQuotedString(string $contents): string
    {
        $decoded = preg_replace_callback(
            '/\\\\(?:[nrtvef\\\\$"]|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
            fn(array $matches): string => $this->decodeEscape($matches[0]),
            $contents,
        );

        return $decoded ?? $this->invalid();
    }

    private function decodeEscape(string $sequence): string
    {
        $escape = mb_substr($sequence, 1, null, '8bit');
        $literal = match ($escape) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'v' => "\v",
            'e' => "\e",
            'f' => "\f",
            '\\', '$', '"' => $escape,
            default => null,
        };
        if ($literal !== null) {
            return $literal;
        }
        if ($escape[0] >= '0' && $escape[0] <= '7') {
            return chr(octdec($escape) & 0xFF);
        }
        if ($escape[0] === 'x') {
            return chr(hexdec(mb_substr($escape, 1, null, '8bit')) & 0xFF);
        }

        $codePoint = (int) hexdec(mb_substr($escape, 2, -1, '8bit'));
        if ($codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            $this->invalid();
        }

        return mb_chr($codePoint, 'UTF-8');
    }

    private function expect(int $id): void
    {
        if ($this->take()[0] !== $id) {
            $this->invalid();
        }
    }

    private function expectText(string $text): void
    {
        if ($this->take()[1] !== $text) {
            $this->invalid();
        }
    }

    /** @return array{int|null, string} */
    private function take(): array
    {
        return $this->tokens[$this->cursor++] ?? $this->invalid();
    }

    private function invalid(): never
    {
        throw new ContentException("Declarative PHP file '{$this->path}' may contain only declare(strict_types=1) and one returned literal array; executable code, expressions, variables, interpolation, includes, and output are forbidden.");
    }
}
