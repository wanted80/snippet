<?php

declare(strict_types=1);

namespace Snippet\Cli;

use SplFileObject;

use function mb_rtrim;
use function mb_strlen;
use function mb_substr;
use function ord;
use function sprintf;
use function str_replace;

/** Renders safe, concise failure messages for command-line users. */
final readonly class ErrorReporter
{
    private const string BOLD = "\e[1m";

    private const string RED = "\e[1;31m";

    private const string RESET = "\e[0m";

    private const string YELLOW = "\e[1;33m";

    public function __construct(private bool $decorated = false) {}

    /** Report a fatal operation failure. */
    public function failure(
        SplFileObject $stderr,
        string $operation,
        string $message,
        ?string $root = null,
    ): void {
        $this->write($stderr, "{$operation} failed:", $message, self::RED, $root);
    }

    /** Report a recoverable operation failure. */
    public function warning(
        SplFileObject $stderr,
        string $operation,
        string $message,
        ?string $root = null,
    ): void {
        $this->write($stderr, "{$operation} failed:", $message, self::YELLOW, $root);
    }

    /** Report a fatal error without a more specific operation. */
    public function error(SplFileObject $stderr, string $message, ?string $root = null): void
    {
        $this->write($stderr, 'Error:', $message, self::RED, $root);
    }

    /** Report invalid command syntax followed by the trusted usage block. */
    public function usageError(SplFileObject $stderr, string $message, string $usage): void
    {
        $this->error($stderr, $message);
        if ($this->decorated) {
            $usage = self::BOLD . 'Usage:' . self::RESET . mb_substr($usage, mb_strlen('Usage:'));
        }

        $stderr->fwrite("\n{$usage}");
    }

    private function write(
        SplFileObject $stderr,
        string $label,
        string $message,
        string $colour,
        ?string $root,
    ): void {
        if ($this->decorated) {
            $label = $colour . $label . self::RESET;
        }

        $stderr->fwrite($label . ' ' . $this->message($message, $root) . "\n");
    }

    private function message(string $message, ?string $root): string
    {
        if ($root !== null) {
            $prefix = mb_rtrim($root, '/');
            if ($prefix !== '' && $prefix !== '/') {
                $message = str_replace($prefix . '/', '', $message);
            }
        }

        $safe = '';
        $length = mb_strlen($message, '8bit');
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $message[$offset];
            $byte = ord($character);
            $safe .= $byte < 32 || $byte === 127
                ? sprintf('\\x%02X', $byte)
                : $character;
        }

        return $safe;
    }
}
