<?php

declare(strict_types=1);

namespace Snippet\Cli;

/** Commands accepted by the shared CLI, with their grammar and diagnostic labels. */
enum Command: string
{
    case Init = 'init';
    case Inspect = 'inspect';
    case Version = '--version';
    case Validate = 'validate';
    case Build = 'build';
    case Preview = 'preview';
    case NewContent = 'new';

    public function acceptsArguments(): bool
    {
        return match ($this) {
            self::Init, self::Version, self::Validate, self::Build => false,
            self::Inspect, self::Preview, self::NewContent => true,
        };
    }

    /** Canonical command syntax for capability inspection. */
    public function syntax(): string
    {
        return match ($this) {
            self::Version => '--version [--json]',
            self::Init => 'init [--json]',
            self::Validate => 'validate [--json]',
            self::Build => 'build [--json]',
            self::Inspect => 'inspect <capabilities|theme|config|content> --json',
            self::Preview => 'preview [--host=<host>] [--port=<port>]',
            self::NewContent => 'new <page|article> <slug> [--date=YYYY-MM-DD] [--json] (date: articles only)',
        };
    }

    public function operation(): string
    {
        return match ($this) {
            self::Init => 'Workspace initialization',
            self::Inspect => 'Inspection',
            self::Version => 'Version reporting',
            self::Validate => 'Validation',
            self::Build => 'Build',
            self::Preview => 'Preview',
            self::NewContent => 'Draft creation',
        };
    }
}
