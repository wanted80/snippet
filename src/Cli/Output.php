<?php

declare(strict_types=1);

namespace Snippet\Cli;

use Snippet\Support\ApplicationVersion;
use SplFileObject;

/** Emits one compact JSON result or preserves the normal human diagnostic channel. */
final readonly class Output
{
    public function __construct(
        public bool $json,
        private SplFileObject $stdout,
        private SplFileObject $stderr,
        private ErrorReporter $reporter,
        private string $usage,
        private ?string $root,
    ) {}

    /** @param array<string, mixed> $result */
    public function result(array $result): void
    {
        $this->stdout->fwrite(json_encode([
            'schema' => 'snippet.agent/v1',
            'snippet_version' => ApplicationVersion::CURRENT,
            ...$result,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    public function usageError(string $message): null
    {
        if ($this->json) {
            $this->result(['error' => ['code' => 'cli.invalid_arguments', 'message' => $message]]);
        } else {
            $this->reporter->usageError($this->stderr, $message, $this->usage);
        }
        return null;
    }

    public function failure(Command $command, string $message): void
    {
        if ($this->json) {
            $this->result(['error' => ['code' => $command->value . '.failed', 'message' => $message]]);
        } else {
            $this->reporter->failure($this->stderr, $command->operation(), $message, $this->root);
        }
    }
}
