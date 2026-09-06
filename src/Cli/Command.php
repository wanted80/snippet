<?php

declare(strict_types=1);

namespace Snippet\Cli;

/** Commands accepted by the shared CLI, with their grammar and diagnostic labels. */
enum Command: string
{
    case Version = '--version';
    case Validate = 'validate';
    case Build = 'build';
    case Preview = 'preview';
    case NewContent = 'new';

    public function acceptsArguments(): bool
    {
        return match ($this) {
            self::Version, self::Validate, self::Build => false,
            self::Preview, self::NewContent => true,
        };
    }

    public function operation(): string
    {
        return match ($this) {
            self::Version => 'Version reporting',
            self::Validate => 'Validation',
            self::Build => 'Build',
            self::Preview => 'Preview',
            self::NewContent => 'Draft creation',
        };
    }
}
