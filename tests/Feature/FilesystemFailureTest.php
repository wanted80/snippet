<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Site\Config;
use Snippet\Site\ConfigLoader;

/**
 * Run an operation without PHPUnit converting its intentionally suppressed
 * filesystem warning into a test warning.
 *
 * @template T
 * @param Closure():T $operation
 * @return T
 */
function withoutFilesystemErrorHandler(Closure $operation): mixed
{
    set_error_handler(null);
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

it('reports unreadable files', function (string $file, string $message): void {
    $path = $this->item('post', ['title' => 'T', 'description' => 'D']);
    chmod($path . '/' . $file, 0000);
    try {
        expect(fn(): mixed => withoutFilesystemErrorHandler(fn() => $this->catalog()))->toThrow(ContentException::class, $message);
    } finally {
        chmod($path . '/' . $file, 0644);
    }
})->with([
    'page' => ['page.md', 'Unable to read page'],
    'metadata' => ['meta.php', 'Unable to read metadata'],
]);

it('reports an unreadable directory', function (): void {
    $this->content();
    chmod($this->directory . '/content/pages', 0000);
    try {
        expect(fn(): mixed => withoutFilesystemErrorHandler(fn() => $this->catalog()))->toThrow(ContentException::class, 'Unable to read directory');
    } finally {
        chmod($this->directory . '/content/pages', 0755);
    }
});

it('reports an unreadable site configuration', function (): void {
    chmod($this->directory . '/site/config.php', 0000);
    try {
        expect(fn(): mixed => withoutFilesystemErrorHandler(fn(): Config => new ConfigLoader()->load($this->directory . '/site')))
            ->toThrow(ContentException::class, 'cannot be read');
    } finally {
        chmod($this->directory . '/site/config.php', 0644);
    }
});

it('reports an unreadable site asset directory', function (): void {
    mkdir($this->directory . '/site/assets');
    chmod($this->directory . '/site/assets', 0000);
    try {
        expect(fn(): mixed => withoutFilesystemErrorHandler(fn(): Config => new ConfigLoader()->load($this->directory . '/site')))
            ->toThrow(ContentException::class, 'Unable to read site assets directory');
    } finally {
        chmod($this->directory . '/site/assets', 0755);
    }
});
