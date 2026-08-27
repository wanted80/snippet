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
    mkdir($source . '/content/nested', 0777, true);
    mkdir($source . '/site');
    mkdir($source . '/resources/templates', 0777, true);
    file_put_contents($source . '/content/nested/post.md', "Starter post.\n");
    file_put_contents($source . '/site/config.php', "starter config\n");
    file_put_contents($source . '/resources/templates/layout.html', "<main>{{body}}</main>\n");

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
                'content/nested/post.md',
                'site/config.php',
                'resources/templates/layout.html',
            ],
            'skipped' => [],
        ])
        ->and(file_get_contents($workspace . '/content/nested/post.md'))->toBe("Starter post.\n")
        ->and(file_get_contents($workspace . '/site/config.php'))->toBe("starter config\n")
        ->and(file_get_contents($workspace . '/resources/templates/layout.html'))->toBe("<main>{{body}}</main>\n")
        ->and(PublisherFaults::calls('scaffolding_fclose'))->toBe(6)
        ->and($workspace . '/public')->not->toBeDirectory();
});

it('merges idempotently while existing files and public output win', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    mkdir($workspace . '/site');
    file_put_contents($workspace . '/site/config.php', "custom config\n");
    mkdir($workspace . '/public');
    file_put_contents($workspace . '/public/index.html', 'existing publication');
    $initializer = new WorkspaceInitializer($source, $workspace);

    expect($initializer->initialize())
        ->toBe([
            'created' => [
                'content/nested/post.md',
                'resources/templates/layout.html',
            ],
            'skipped' => ['site/config.php'],
        ])
        ->and($initializer->initialize())
        ->toBe([
            'created' => [],
            'skipped' => [
                'content/nested/post.md',
                'site/config.php',
                'resources/templates/layout.html',
            ],
        ])
        ->and(file_get_contents($workspace . '/site/config.php'))->toBe("custom config\n")
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

it('rejects invalid bundled scaffold directories', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    rename($source . '/content', $source . '/starter-content');

    if ($fault === 'file') {
        file_put_contents($source . '/content', 'not a directory');
    } elseif ($fault === 'symlink') {
        symlink($source . '/starter-content', $source . '/content');
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'missing' => ['missing', "Bundled scaffold directory 'content' must be a non-symlink directory."],
    'file' => ['file', "Bundled scaffold directory 'content' must be a non-symlink directory."],
    'symlink' => ['symlink', "Bundled scaffold directory 'content' must be a non-symlink directory."],
]);

it('rejects unsafe bundled scaffold entries', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    $entry = $source . '/content/unsafe';

    if ($fault === 'symlink') {
        symlink($source . '/site/config.php', $entry);
    } else {
        expect(posix_mkfifo($entry, 0600))->toBeTrue();
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message);
})->with([
    'symlink' => ['symlink', "Bundled scaffold entry 'content/unsafe' must not be a symbolic link."],
    'special file' => ['special', "Bundled scaffold entry 'content/unsafe' must be a regular file."],
]);

it('reports an unreadable bundled scaffold directory', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_scandir', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot read bundled scaffold directory 'content'.");
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
        ->and($workspace . '/site')->not->toBeDirectory();
})->with([
    'symlink' => ['symlink', "Cannot initialize 'content': the destination is a symbolic link."],
    'file' => ['file', "Cannot initialize 'content': the destination is not a directory."],
]);

it('rejects destination file conflicts before creating files', function (string $fault, string $message): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    mkdir($workspace . '/content/nested', 0777, true);
    $destination = $workspace . '/content/nested/post.md';

    if ($fault === 'symlink') {
        symlink($source . '/content/nested/post.md', $destination);
    } else {
        mkdir($destination);
    }

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, $message)
        ->and($workspace . '/site')->not->toBeDirectory();
})->with([
    'symlink' => ['symlink', "Cannot initialize 'content/nested/post.md': the destination is a symbolic link."],
    'directory' => ['directory', "Cannot initialize 'content/nested/post.md': the destination is not a regular file."],
]);

it('reports directory creation failures', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_mkdir', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot create directory 'content'.");
});

it('reports scaffold file read failures', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_fopen', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot read bundled scaffold file 'content/nested/post.md'.");
});

it('reports scaffold file creation failures', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_fopen', ['pass', 'fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot create file 'content/nested/post.md'.")
        ->and(PublisherFaults::calls('scaffolding_fclose'))->toBe(1);
});

it('removes a partial destination after a scaffold copy failure', function (): void {
    $source = workspaceScaffold($this->directory);
    $workspace = emptyWorkspace($this->directory);
    PublisherFaults::set('scaffolding_stream_copy', ['fail']);

    expect(fn(): array => new WorkspaceInitializer($source, $workspace)->initialize())
        ->toThrow(RuntimeException::class, "Cannot copy scaffold file 'content/nested/post.md'.")
        ->and(PublisherFaults::calls('scaffolding_fclose'))->toBe(2)
        ->and($workspace . '/content/nested/post.md')->not->toBeFile();
});
