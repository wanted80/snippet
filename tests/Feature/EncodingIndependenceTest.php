<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Exception\ContentException;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\Publisher;

it('validates and publishes identical UTF-8 inputs without changing the process encoding', function (string $encoding): void {
    $path = $this->article('nihongo', [
        'title' => str_repeat('日', 120),
        'description' => str_repeat('é', 320),
        'date' => '2026-09-01',
        'tags' => [str_repeat('é', 48), '日本語'],
        'cover' => true,
        'alt' => str_repeat('界', 320),
    ], "# 日本語\n\nCafé **中文** and [this article](https://example.test/publication/articles/nihongo/).\n");
    $this->image($path . '/cover.webp');
    $this->site(['title' => '日本語', 'url' => 'https://example.test/publication', 'build' => ['minify' => true]]);
    file_put_contents($this->directory . '/site/site.js', 'document.documentElement.dataset.custom = "日本語";');

    $build = function (): array {
        $inputs = new PublicationInputLoader()->load($this->directory);
        new Publisher()->publish($this->directory, $inputs->config, $inputs->catalog, $inputs->limits, $inputs->templates, $inputs->assets);
        $files = [];
        $public = $this->directory . '/public/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            $path = $file->getPathname();
            $hash = hash_file('sha256', $path);
            assert(is_string($hash));
            $files[mb_substr($path, mb_strlen($public, '8bit'), null, '8bit')] = $hash;
        }
        ksort($files, SORT_STRING);

        return [serialize($inputs), $files];
    };

    $previous = mb_internal_encoding();
    try {
        mb_internal_encoding('UTF-8');
        $expected = $build();
        mb_internal_encoding($encoding);
        $actual = $build();
        $afterBuild = mb_internal_encoding();
    } finally {
        mb_internal_encoding($previous);
    }

    expect($actual)->toBe($expected)
        ->and($afterBuild)->toBe($encoding);
})->with(['ASCII', 'ISO-8859-1', 'UTF-16BE']);

it('rejects Unicode whitespace consistently with a different process encoding', function (string $field): void {
    $this->item('post', ['title' => 'Title', 'description' => 'Description.'], match ($field) {
        'markdown' => "\u{2003}",
        'link label' => "[\u{2003}](/)",
        default => 'Text.',
    });
    if ($field === 'site title') {
        $this->site(['title' => "\u{3000}Title"]);
    }

    $failure = function (): ?string {
        try {
            (void) new PublicationInputLoader()->load($this->directory);
        } catch (ContentException $contentException) {
            return $contentException->getMessage();
        }

        return null;
    };
    $previous = mb_internal_encoding();
    try {
        mb_internal_encoding('UTF-8');
        $expected = $failure();
        mb_internal_encoding('ASCII');
        $actual = $failure();
    } finally {
        mb_internal_encoding($previous);
    }

    expect($expected)->toBeString()
        ->and($actual)->toBe($expected);
})->with(['markdown', 'link label', 'site title']);

it('parses CLI date options by bytes independently of process encoding', function (): void {
    $this->content();
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $previous = mb_internal_encoding();
    try {
        mb_internal_encoding('UTF-16BE');
        $status = new Application($this->directory)->run(['bin/snippet', 'new', 'article', 'post', '--date=2026-09-01'], $stdout, $stderr);
    } finally {
        mb_internal_encoding($previous);
    }

    expect($status)->toBe(0)
        ->and($this->directory . '/content/articles/2026/09/01/post/article.md')->toBeFile();
});
