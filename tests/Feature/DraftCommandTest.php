<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Authoring\DraftCreator;
use Snippet\Tests\PublisherFaults;

/**
 * @param list<string> $arguments
 * @return array{int, string, string}
 */
function runDraftApplication(string $root, array $arguments, ?DateTimeImmutable $clock = null): array
{
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $application = $clock instanceof DateTimeImmutable
        ? new Application($root, draftCreator: new DraftCreator($clock))
        : new Application($root);
    $status = $application->run($arguments, $stdout, $stderr);
    $stdout->rewind();
    $stderr->rewind();
    $output = $stdout->fread(8192);
    $error = $stderr->fread(8192);
    assert(is_string($output));
    assert(is_string($error));

    return [$status, $output, $error];
}

it('creates the exact incomplete page skeleton without loading the catalog or changing public', function (): void {
    mkdir($this->directory . '/content/pages', 0777, true);
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'published');

    expect(runDraftApplication($this->directory, ['bin/snippet', 'new', 'page', 'contact']))
        ->toBe([
            0,
            "Created incomplete draft: content/pages/contact\nComplete content/pages/contact/page.md and content/pages/contact/meta.php before validating or building.\n",
            '',
        ])
        ->and(file_get_contents($this->directory . '/content/pages/contact/page.md'))->toBe('')
        ->and(file_get_contents($this->directory . '/content/pages/contact/meta.php'))->toBe(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'title' => '',
                'description' => '',
            ];
            PHP . "\n")
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe('published')
        ->and($this->directory . '/content/articles')->not->toBeDirectory();
});

it('uses the UTC date from the injected command clock for an article draft', function (): void {
    $this->content();
    $clock = new DateTimeImmutable('2026-08-18 00:30:00+02:00');

    expect(runDraftApplication($this->directory, ['bin/snippet', 'new', 'article', 'first-post'], $clock))
        ->toBe([
            0,
            "Created incomplete draft: content/articles/2026/08/17/first-post\nComplete content/articles/2026/08/17/first-post/article.md and content/articles/2026/08/17/first-post/meta.php before validating or building.\n",
            '',
        ]);

    $path = $this->directory . '/content/articles/2026/08/17/first-post';
    expect(file_get_contents($path . '/article.md'))->toBe('')
        ->and(file_get_contents($path . '/meta.php'))->toBe(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'title' => '',
                'description' => '',
                'date' => '2026-08-17',
                'tags' => [],
            ];
            PHP . "\n");
});

it('uses an explicit valid article date instead of the injected clock date', function (): void {
    $this->content();
    $clock = new DateTimeImmutable('2026-08-17T12:00:00Z');

    expect(runDraftApplication(
        $this->directory,
        ['bin/snippet', 'new', 'article', 'older-note', '--date=2024-02-29'],
        $clock,
    ))->toBe([
        0,
        "Created incomplete draft: content/articles/2024/02/29/older-note\nComplete content/articles/2024/02/29/older-note/article.md and content/articles/2024/02/29/older-note/meta.php before validating or building.\n",
        '',
    ])
        ->and(file_get_contents($this->directory . '/content/articles/2024/02/29/older-note/meta.php'))->toContain("'date' => '2024-02-29'");
});

it('creates drafts that fail while incomplete and validate after only required content is filled', function (string $type, string $relative, string $markdown, string $metadata): void {
    $this->content();
    $this->resources();
    $clock = new DateTimeImmutable('2026-08-17T12:00:00Z');

    expect(runDraftApplication($this->directory, ['bin/snippet', 'new', $type, 'new-item'], $clock)[0])->toBe(0)
        ->and(runDraftApplication($this->directory, ['bin/snippet', 'validate'])[0])->toBe(1);

    file_put_contents($this->directory . '/' . $relative . '/' . $markdown, 'Finished content.');
    file_put_contents($this->directory . '/' . $relative . '/meta.php', $metadata);

    expect(runDraftApplication($this->directory, ['bin/snippet', 'validate']))
        ->toBe([0, 'Valid site: ' . ($type === 'article' ? '1 article, 0 pages' : '0 articles, 1 page') . ", 0 tags, 3 assets.\n", '']);
})->with([
    'page' => [
        'page',
        'content/pages/new-item',
        'page.md',
        "<?php\ndeclare(strict_types=1);\n\nreturn ['title' => 'New page', 'description' => 'Description.'];\n",
    ],
    'article with empty tags' => [
        'article',
        'content/articles/2026/08/17/new-item',
        'article.md',
        "<?php\ndeclare(strict_types=1);\n\nreturn ['title' => 'New article', 'description' => 'Description.', 'date' => '2026-08-17', 'tags' => []];\n",
    ],
]);

it('reports actionable new-command usage errors without changing content or public', function (array $arguments, string $message): void {
    /** @var list<string> $arguments */
    $this->content();
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'published');

    [$status, $output, $error] = runDraftApplication($this->directory, $arguments);

    expect($status)->toBe(2)
        ->and($output)->toBeEmpty()
        ->and($error)->toStartWith("Error: {$message}\n\nUsage:\n")
        ->and(scandir($this->directory . '/content/pages'))->toBe(['.', '..'])
        ->and(scandir($this->directory . '/content/articles'))->toBe(['.', '..'])
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe('published');
})->with([
    'missing type and slug' => [['bin/snippet', 'new'], 'New command requires a content type and slug.'],
    'missing slug' => [['bin/snippet', 'new', 'page'], 'New command requires a content type and slug.'],
    'invalid type' => [['bin/snippet', 'new', 'note', 'entry'], "New content type 'note' is invalid; use 'page' or 'article'."],
    'malformed slug' => [['bin/snippet', 'new', 'page', 'Bad_slug'], "Invalid content slug 'Bad_slug'; use lowercase ASCII letters and numbers separated by single hyphens."],
    'reserved slug' => [['bin/snippet', 'new', 'page', 'assets'], "Content slug 'assets' is reserved because it collides with a site route."],
    'invalid date format' => [['bin/snippet', 'new', 'article', 'entry', '--date=2026-8-1'], "Article date '2026-8-1' must be a real date in YYYY-MM-DD format."],
    'invalid calendar date' => [['bin/snippet', 'new', 'article', 'entry', '--date=2026-02-30'], "Article date '2026-02-30' must be a real date in YYYY-MM-DD format."],
    'page date' => [['bin/snippet', 'new', 'page', 'entry', '--date=2026-08-17'], 'New page does not accept --date.'],
    'duplicate date' => [['bin/snippet', 'new', 'article', 'entry', '--date=2026-08-17', '--date=2026-08-18'], 'New article option --date may be provided only once.'],
    'malformed date option' => [['bin/snippet', 'new', 'article', 'entry', '--date'], 'Article date option must use --date=YYYY-MM-DD.'],
    'malformed prefixed date option' => [['bin/snippet', 'new', 'article', 'entry', '--date-old'], 'Article date option must use --date=YYYY-MM-DD.'],
    'unknown option' => [['bin/snippet', 'new', 'article', 'entry', '--when=2026-08-17'], "Unknown new option '--when=2026-08-17'."],
    'extra argument' => [['bin/snippet', 'new', 'page', 'entry', 'extra'], "Unexpected new argument 'extra'."],
    'misplaced option' => [['bin/snippet', 'new', 'article', '--date=2026-08-17', 'entry'], 'New command requires the slug before any options.'],
]);

it('rejects missing and symlinked collection paths as content errors', function (string $fault, array $arguments, string $message): void {
    /** @var list<string> $arguments */
    mkdir($this->directory . '/content');
    if ($fault === 'missing') {
        mkdir($this->directory . '/content/articles');
    } elseif ($fault === 'collection-symlink') {
        mkdir($this->directory . '/content/real-pages');
        symlink($this->directory . '/content/real-pages', $this->directory . '/content/pages');
    } else {
        rmdir($this->directory . '/content');
        mkdir($this->directory . '/outside/pages', 0777, true);
        symlink($this->directory . '/outside', $this->directory . '/content');
    }

    [$status, $output, $error] = runDraftApplication($this->directory, $arguments);

    expect($status)->toBe(1)
        ->and($output)->toBeEmpty()
        ->and($error)->toContain($message);
})->with([
    'missing page collection' => ['missing', ['bin/snippet', 'new', 'page', 'entry'], 'Page collection directory'],
    'symlinked page collection' => ['collection-symlink', ['bin/snippet', 'new', 'page', 'entry'], 'regular non-symlink directory'],
    'symlinked content root' => ['content-symlink', ['bin/snippet', 'new', 'page', 'entry'], 'regular non-symlink directory'],
]);

it('rejects symlinks and files in article date paths', function (string $fault): void {
    $this->content();
    mkdir($this->directory . '/outside');
    if ($fault === 'symlink') {
        symlink($this->directory . '/outside', $this->directory . '/content/articles/2026');
    } else {
        file_put_contents($this->directory . '/content/articles/2026', 'not a directory');
    }

    [$status, $output, $error] = runDraftApplication(
        $this->directory,
        ['bin/snippet', 'new', 'article', 'entry', '--date=2026-08-17'],
    );

    expect($status)->toBe(1)
        ->and($output)->toBeEmpty()
        ->and($error)->toContain("Draft parent 'content/articles/2026' must be a regular non-symlink directory.")
        ->and(scandir($this->directory . '/outside'))->toBe(['.', '..']);
})->with(['symlink', 'file']);

it('atomically refuses an existing destination and preserves its files and public', function (string $type, string $destination): void {
    $this->content();
    $path = $this->directory . '/' . $destination;
    mkdir($path, 0777, true);
    file_put_contents($path . '/keep.txt', 'keep');
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'published');
    $arguments = ['bin/snippet', 'new', $type, 'existing'];
    if ($type === 'article') {
        $arguments[] = '--date=2026-08-17';
    }

    [$status, $output, $error] = runDraftApplication($this->directory, $arguments);

    expect($status)->toBe(1)
        ->and($output)->toBeEmpty()
        ->and($error)->toBe("Draft creation failed: Draft destination '{$destination}' already exists.\n")
        ->and(file_get_contents($path . '/keep.txt'))->toBe('keep')
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe('published');
})->with([
    'page' => ['page', 'content/pages/existing'],
    'article' => ['article', 'content/articles/2026/08/17/existing'],
]);

it('cleans up a partial article draft after recoverable file failures', function (string $operation): void {
    $this->content();
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'published');
    PublisherFaults::set($operation, ['fail']);

    [$status, $output, $error] = runDraftApplication(
        $this->directory,
        ['bin/snippet', 'new', 'article', 'failed', '--date=2026-08-17'],
    );

    expect($status)->toBe(1)
        ->and($output)->toBeEmpty()
        ->and($error)->toContain('Unable to write draft file')
        ->and(file_exists($this->directory . '/content/articles/2026'))->toBeFalse()
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe('published');
})->with(['draft_fopen', 'draft_fwrite', 'draft_fclose']);

it('cleans up article date directories after a directory creation failure', function (array $outcomes, string $relative): void {
    /** @var list<'fail'|'pass'|'throw'> $outcomes */
    $this->content();
    PublisherFaults::set('draft_mkdir', $outcomes);

    [$status, $output, $error] = runDraftApplication(
        $this->directory,
        ['bin/snippet', 'new', 'article', 'failed', '--date=2026-08-17'],
    );

    expect($status)->toBe(1)
        ->and($output)->toBeEmpty()
        ->and($error)->toBe("Draft creation failed: Unable to create draft directory '{$relative}'.\n")
        ->and(file_exists($this->directory . '/content/articles/2026'))->toBeFalse();
})->with([
    'first date directory' => [['fail'], 'content/articles/2026'],
    'destination directory' => [['pass', 'pass', 'pass', 'fail'], 'content/articles/2026/08/17/failed'],
]);
