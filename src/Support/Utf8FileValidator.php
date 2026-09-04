<?php

declare(strict_types=1);

namespace Snippet\Support;

/** Validates UTF-8 files in fixed-size chunks, including split code points. */
final readonly class Utf8FileValidator
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

        $pending = '';
        try {
            while (true) {
                $chunk = @fread($stream, self::BUFFER_BYTES);
                if ($chunk === false) {
                    return false;
                }

                if ($chunk === '') {
                    return $pending === '';
                }

                if (!$validateEncoding) {
                    continue;
                }

                $chunk = $pending . $chunk;
                if (mb_check_encoding($chunk, 'UTF-8')) {
                    $pending = '';
                    continue;
                }

                // Only a split final code point may remain; PHP validates every complete prefix.
                preg_match('/[\xC2-\xF4][\x80-\xBF]{0,2}\z/', $chunk, $tail);
                $pending = $tail[0] ?? '';
                if ($pending === '' || !mb_check_encoding(mb_substr($chunk, 0, -mb_strlen($pending, '8bit'), '8bit'), 'UTF-8')) {
                    return false;
                }
            }
        } finally {
            fclose($stream);
        }
    }
}
