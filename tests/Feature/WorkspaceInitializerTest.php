<?php

declare(strict_types=1);

use Snippet\Scaffolding\WorkspaceInitializer;
use Snippet\Tests\PublisherFaults;

mutates(WorkspaceInitializer::class);

beforeEach(function (): void {
    chdir($this->directory);
});

afterEach(function (): void {
    chdir(dirname(__DIR__, 2));
});

function workspaceScaffold(string $root): string
{
    $source = $root . '/engine';
    mkdir($source . '/site', 0777, true);
    mkdir($source . '/resources/templates', 0777, true);
    file_put_contents($source . '/site/config.php', "starter config\n");
    file_put_contents($source . '/resources/templates/layout.html', "<main>{{body}}</main>\n");
    file_put_contents($source . '/resources/templates/not-found.html', "<h1>Not found</h1>\n");
    file_put_contents($source . '/resources/preview-router.php', "engine preview support\n");

    return $source;
}

function emptyWorkspace(string $root): string
{
    $workspace = $root . '/workspace';
    mkdir($workspace);

    return $workspace;
}

it('synchronizes a deterministic scaffold into an empty workspace', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);

    expect(new WorkspaceInitializer($source, $workspace)->initialize())
        ->toBe([
            'created' => [
                'site/config.php',
                'resources/templates/layout.html',
                'resources/templates/not-found.html',
            ],
            'skipped' => [],
        ])
        ->and($workspace . '/content/articles')->toBeDirectory()
        ->and($workspace . '/content/pages')->toBeDirectory()
        ->and(file_get_contents($workspace . '/site/config.php'))->toBe("starter config\n")
        ->and(file_get_contents($workspace . '/resources/templates/layout.html'))->toBe("<main>{{body}}</main>\n")
        ->and(file_get_contents($workspace . '/resources/templates/not-found.html'))->toBe("<h1>Not found</h1>\n")
        ->and($workspace . '/resources/preview-router.php')->not->toBeFile()
        ->and(PublisherFaults::calls('scaffolding_fclose'))->toBe(6)
        ->and($workspace . '/public')->not->toBeDirectory();
});

it('merges idempotently while existing files and public output win', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    mkdir($workspace . '/site');
    file_put_contents($workspace . '/site/config.php', "custom config\n");
    mkdir($workspace . '/resources/templates', 0777, true);
    file_put_contents($workspace . '/resources/templates/layout.html', "custom layout\n");
    mkdir($workspace . '/public');
    file_put_contents($workspace . '/public/index.html', 'existing publication');
    $initializer = new WorkspaceInitializer($source, $workspace);

    expect($initializer->initialize())
        ->toBe([
            'created' => [
                'resources/templates/not-found.html',
            ],
            'skipped' => [
                'site/config.php',
                'resources/templates/layout.html',
            ],
        ])
        ->and($initializer->initialize())
        ->toBe([
            'created' => [],
            'skipped' => [
                'site/config.php',
                'resources/templates/layout.html',
                'resources/templates/not-found.html',
            ],
        ])
        ->and(file_get_contents($workspace . '/site/config.php'))->toBe("custom config\n")
        ->and(file_get_contents($workspace . '/resources/templates/layout.html'))->toBe("custom layout\n")
        ->and(file_get_contents($workspace . '/resources/templates/not-found.html'))->toBe("<h1>Not found</h1>\n")
        ->and(file_get_contents($workspace . '/public/index.html'))->toBe('existing publication');
});

it('requires a writable non-symlink workspace', function (string $fault): void {
    $source = workspaceScaffold($this->directory);
    $workspace = $this->directory . '/workspace';

    if ($fault === 'file') {
        file_put_contents($workspace, 'not a directory');
    } elseif ($fault === 'symlink') {
        symlink($this->directory, $workspace);
    } elseif ($fault === 'unwritable') {
        mkdir($workspace);
        chmod($workspace, 0555);
    }

    try {
        expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
            ->toThrow(RuntimeException::class, "Workspace '{$workspace}' must be a writable non-symlink directory.");
    } finally {
        if ($fault === 'unwritable') {
            chmod($workspace, 0755);
        }
    }
})->with(['missing', 'file', 'symlink', 'unwritable']);

it('rejects invalid canonical input directories', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    rename($source . '/site', $source . '/starter-site');

    if ($fault === 'file') {
        file_put_contents($source . '/site', 'not a directory');
    } elseif ($fault === 'symlink') {
        symlink($source . '/starter-site', $source . '/site');
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'missing' => ['missing', "Canonical input directory 'site' must be a non-symlink directory."],
    'file' => ['file', "Canonical input directory 'site' must be a non-symlink directory."],
    'symlink' => ['symlink', "Canonical input directory 'site' must be a non-symlink directory."],
]);

it('rejects unsafe canonical input entries', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    $entry = $source . '/site/unsafe';

    if ($fault === 'symlink') {
        symlink($source . '/site/config.php', $entry);
    } else {
        expect(posix_mkfifo($entry, 0600))->toBeTrue();
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'symlink' => ['symlink', "Canonical input entry 'site/unsafe' must not be a symbolic link."],
    'special file' => ['special', "Canonical input entry 'site/unsafe' must be a regular file."],
]);

it('reports an unreadable canonical input directory', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_scandir', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot read canonical input directory 'site'.");
});

it('rejects destination directory conflicts before creating files', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);

    if ($fault === 'symlink') {
        symlink($this->directory, $workspace . '/content');
    } else {
        file_put_contents($workspace . '/content', 'not a directory');
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message)
        ->and($workspace . '/resources')->not->toBeDirectory();
})->with([
    'symlink' => ['symlink', "Cannot initialize 'content': the destination is a symbolic link."],
    'file' => ['file', "Cannot initialize 'content': the destination is not a directory."],
]);

it('rejects destination file conflicts before creating files', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    mkdir($workspace . '/site');
    $destination = $workspace . '/site/config.php';

    if ($fault === 'symlink') {
        symlink($source . '/site/config.php', $destination);
    } else {
        mkdir($destination);
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message)
        ->and($workspace . '/content')->not->toBeDirectory();
})->with([
    'symlink' => ['symlink', "Cannot initialize 'site/config.php': the destination is a symbolic link."],
    'directory' => ['directory', "Cannot initialize 'site/config.php': the destination is not a regular file."],
]);

it('reports directory creation failures', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_mkdir', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot create directory 'content'.");
});

it('reports canonical input file read failures', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_fopen', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot read canonical input file 'site/config.php'.");
});

it('reports canonical input file creation failures', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_fopen', ['pass', 'fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot create file 'site/config.php'.")
        ->and(PublisherFaults::calls('scaffolding_fclose'))->toBe(1);
});

it('removes a partial destination after a canonical input copy failure', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_stream_copy', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot copy canonical input file 'site/config.php'.")
        ->and(PublisherFaults::calls('scaffolding_fclose'))->toBe(2)
        ->and($workspace . '/site/config.php')->not->toBeFile();
});
