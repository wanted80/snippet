<?php

declare(strict_types=1);

namespace Snippet\Support;

/** Validates UTF-8 files in fixed-size chunks, including split code points. */
final class Utf8FileValidator
{
    private const int BUFFER_BYTES = 8_192;

    public function isValid(string $path, bool $validateEncoding = true): bool
    {
        // The fopen() guard below returns the same result; this avoids an avoidable failed open.
        if (!is_file($path)) {
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            return false;
        }

        $continuations = 0;
        $minimum = 0x80;
        $maximum = 0xBF;
        try {
            while (true) {
                $chunk = @fread($stream, self::BUFFER_BYTES);
                if ($chunk === false) {
                    return false;
                }

                if ($chunk === '') {
                    return !$validateEncoding || $continuations === 0;
                }

                if (!$validateEncoding) {
                    continue;
                }

                $length = mb_strlen($chunk, '8bit');
                for ($offset = 0; $offset < $length; ++$offset) {
                    $byte = ord($chunk[$offset]);
                    if ($continuations > 0) {
                        if ($byte < $minimum || $byte > $maximum) {
                            return false;
                        }

                        --$continuations;
                        $minimum = 0x80;
                        $maximum = 0xBF;
                        continue;
                    }

                    if ($byte <= 0x7F) {
                        continue;
                    }

                    if ($byte >= 0xC2 && $byte <= 0xDF) {
                        $continuations = 1;
                    } elseif (($byte >= 0xE1 && $byte <= 0xEC) || ($byte >= 0xEE && $byte <= 0xEF)) {
                        $continuations = 2;
                    } elseif ($byte === 0xE0) {
                        $continuations = 2;
                        $minimum = 0xA0;
                    } elseif ($byte === 0xED) {
                        $continuations = 2;
                        $maximum = 0x9F;
                    } elseif ($byte >= 0xF1 && $byte <= 0xF3) {
                        $continuations = 3;
                    } elseif ($byte === 0xF0) {
                        $continuations = 3;
                        $minimum = 0x90;
                    } elseif ($byte === 0xF4) {
                        $continuations = 3;
                        $maximum = 0x8F;
                    } else {
                        return false;
                    }
                }
            }
        } finally {
            fclose($stream);
        }
    }
}
