<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Exception\ContentException;

/**
 * Conservatively minifies CSS with fixed-size buffers and falls back to the
 * original bytes whenever lexical structure is incomplete or uncertain.
 */
final class CssMinifier
{
    private const int BUFFER_BYTES = 8_192;

    private const int MAX_DELIMITER_DEPTH = 256;

    /** @var resource */
    private mixed $input;

    /** @var resource */
    private mixed $output;

    private string $inputBuffer = '';

    private int $inputOffset = 0;

    private ?string $pushedByte = null;

    private string $outputBuffer = '';

    private int $outputBufferBytes = 0;

    private int $outputBytes = 0;

    private ?string $lastOutputByte = null;

    private bool $pendingWhitespace = false;

    private string $delimiters = '';

    /**
     * Minify one readable stream into one empty writable stream.
     *
     * @param resource $input
     * @param resource $output
     *
     * @throws ContentException when either stream cannot be processed
     */
    public function minify(mixed $input, mixed $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->reset();

        if (!$this->scan()) {
            return $this->copyOriginal();
        }

        $this->flush();
        return $this->outputBytes;
    }

    private function scan(): bool
    {
        $slash = false;
        while (($byte = $this->read()) !== null) {
            if ($slash) {
                $slash = false;
                if ($byte === '*') {
                    $this->emitByte('/');
                    $this->emitByte('*');
                    if (!$this->comment()) {
                        return false;
                    }

                    continue;
                }

                $this->emitByte('/');
            }

            if ($this->isWhitespace($byte)) {
                $this->pendingWhitespace = true;
                continue;
            }

            if ($byte === '/') {
                $this->flushWhitespace('/');
                $slash = true;
                continue;
            }

            $this->flushWhitespace($byte);
            if ($byte === '"' || $byte === "'") {
                $this->emitByte($byte);
                if (!$this->string($byte)) {
                    return false;
                }

                continue;
            }

            if ($byte === '\\') {
                $this->emitByte($byte);
                if (!$this->escape()) {
                    return false;
                }

                continue;
            }

            if (str_contains('{[(', $byte)) {
                if (isset($this->delimiters[self::MAX_DELIMITER_DEPTH - 1])) {
                    return false;
                }

                $this->delimiters .= $byte;
            } elseif (str_contains('}])', $byte)) {
                $opener = strtr($byte, [
                    '}' => '{',
                    ']' => '[',
                    ')' => '(',
                ]);
                if ($this->delimiters === '' || $this->delimiters[-1] !== $opener) {
                    return false;
                }

                $this->delimiters = mb_substr($this->delimiters, 0, -1, '8bit');
            }

            $this->emitByte($byte);
        }

        if ($slash) {
            $this->emitByte('/');
        }

        return $this->delimiters === '';
    }

    private function comment(): bool
    {
        $previousWasAsterisk = false;
        while (($byte = $this->read()) !== null) {
            $this->emitByte($byte);
            if ($previousWasAsterisk && $byte === '/') {
                return true;
            }

            $previousWasAsterisk = $byte === '*';
        }

        return false;
    }

    private function string(string $quote): bool
    {
        while (($byte = $this->read()) !== null) {
            if (in_array($byte, ["\n", "\r", "\f"], true)) {
                return false;
            }

            $this->emitByte($byte);
            if ($byte === $quote) {
                return true;
            }

            if ($byte !== '\\') {
                continue;
            }

            $escaped = $this->read();
            if ($escaped === null) {
                return false;
            }

            $this->emitByte($escaped);
            if ($escaped === "\r") {
                $this->emitOptionalLineFeed();
            }
        }

        return false;
    }

    private function escape(): bool
    {
        $byte = $this->read();
        if (in_array($byte, [null, "\n", "\r", "\f"], true)) {
            return false;
        }

        $this->emitByte($byte);
        if (!$this->isHexadecimal($byte)) {
            return true;
        }

        $following = null;
        for ($digits = 1; $digits < 6; ++$digits) {
            $next = $this->read();
            if ($next === null) {
                return true;
            }

            if (!$this->isHexadecimal($next)) {
                $following = $next;
                break;
            }

            $this->emitByte($next);
        }

        if ($following === null) {
            $following = $this->read();
            if ($following === null) {
                return true;
            }
        }

        if ($this->isWhitespace($following)) {
            $this->emitByte($following);
            if ($following === "\r") {
                $this->emitOptionalLineFeed();
            }

            return true;
        }

        $this->push($following);
        return true;
    }

    private function emitOptionalLineFeed(): void
    {
        $next = $this->read();
        if ($next === "\n") {
            $this->emitByte($next);
        } elseif ($next !== null) {
            $this->push($next);
        }
    }

    private function flushWhitespace(string $current): void
    {
        if (!$this->pendingWhitespace) {
            return;
        }

        if (!$this->isSeparator($current) && ($this->lastOutputByte === null || !$this->isSeparator($this->lastOutputByte))) {
            $this->emitByte(' ');
        }

        $this->pendingWhitespace = false;
    }

    private function emitByte(string $byte): void
    {
        $this->outputBuffer .= $byte;
        ++$this->outputBufferBytes;
        ++$this->outputBytes;
        $this->lastOutputByte = $byte;
        if ($this->outputBufferBytes >= self::BUFFER_BYTES) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        $this->write($this->outputBuffer);
        $this->outputBuffer = '';
        $this->outputBufferBytes = 0;
    }

    private function read(): ?string
    {
        if ($this->pushedByte !== null) {
            $byte = $this->pushedByte;
            $this->pushedByte = null;
            return $byte;
        }

        if (!isset($this->inputBuffer[$this->inputOffset])) {
            $chunk = fread($this->input, self::BUFFER_BYTES);
            if ($chunk === false) {
                throw new ContentException('Unable to read stylesheet while minifying it.');
            }

            if ($chunk === '') {
                return null;
            }

            $this->inputBuffer = $chunk;
            $this->inputOffset = 0;
        }

        return $this->inputBuffer[$this->inputOffset++];
    }

    private function push(string $byte): void
    {
        $this->pushedByte = $byte;
    }

    private function copyOriginal(): int
    {
        if (!rewind($this->input) || !rewind($this->output)) {
            throw new ContentException('Unable to rewind stylesheet streams after malformed CSS.');
        }

        $bytes = 0;
        while (true) {
            $chunk = fread($this->input, self::BUFFER_BYTES);
            if ($chunk === false) {
                throw new ContentException('Unable to read the original stylesheet after malformed CSS.');
            }

            if ($chunk === '') {
                return $bytes;
            }

            $this->write($chunk);
            $bytes += mb_strlen($chunk, '8bit');
        }
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        $length = mb_strlen($bytes, '8bit');
        while ($offset < $length) {
            $written = fwrite($this->output, mb_substr($bytes, $offset, null, '8bit'));
            if (!is_int($written)) {
                throw new ContentException('Unable to write stylesheet while minifying it.');
            }
            if ($written < 1) {
                throw new ContentException('Unable to write stylesheet while minifying it.');
            }

            $offset += $written;
        }
    }

    private function reset(): void
    {
        $this->inputBuffer = '';
        $this->pushedByte = null;
        $this->outputBuffer = '';
        $this->outputBufferBytes = 0;
        $this->outputBytes = 0;
        $this->lastOutputByte = null;
        $this->pendingWhitespace = false;
        $this->delimiters = '';
    }

    private function isWhitespace(string $byte): bool
    {
        return str_contains(" \n\r\t\f", $byte);
    }

    private function isSeparator(string $byte): bool
    {
        return str_contains('{};,', $byte);
    }

    private function isHexadecimal(string $byte): bool
    {
        return ($byte >= '0' && $byte <= '9')
            || ($byte >= 'A' && $byte <= 'F')
            || ($byte >= 'a' && $byte <= 'f');
    }
}
