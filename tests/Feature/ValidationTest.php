<?php

declare(strict_types=1);

use Snippet\Content\CatalogLoader;
use Snippet\Exception\ContentException;

mutates(CatalogLoader::class);

it('requires a content directory', function (): void {
    (void) new CatalogLoader()->load($this->directory . '/missing');
})->throws(ContentException::class, 'does not exist');

it('rejects a symlinked content directory', function (): void {
    mkdir($this->directory . '/source-content/pages', 0777, true);
    mkdir($this->directory . '/source-content/articles');
    expect(symlink($this->directory . '/source-content', $this->directory . '/content'))->toBeTrue();

    $this->catalog();
})->throws(ContentException::class, 'regular non-symlink directory');

it('requires the page and article collection directories', function (string $missing): void {
    mkdir($this->directory . '/content');
    mkdir($this->directory . '/content/' . ($missing === 'pages' ? 'articles' : 'pages'));
    $this->catalog();
})->throws(ContentException::class, 'does not exist')->with(['pages', 'articles']);

it('ignores non-directory entries directly under content collections', function (): void {
    $this->content();
    file_put_contents($this->directory . '/content/pages/note.txt', 'ignored');
    file_put_contents($this->directory . '/content/articles/note.txt', 'ignored');
    expect($this->catalog()->count())->toBe(0);
});

it('rejects invalid and reserved slugs', function (string $slug, string $message): void {
    $this->content();
    mkdir($this->directory . '/content/pages/' . $slug, 0777, true);
    expect(fn() => $this->catalog())->toThrow(ContentException::class, $message);
})->with([
    ['Bad_slug', 'Invalid'],
    ['double--hyphen', 'Invalid'],
    ['café', 'Invalid'],
    ['日本語', 'Invalid'],
    ['assets', 'reserved'],
    ['articles', 'reserved'],
    ['tags', 'reserved'],
]);

it('requires both page content files', function (string $present, string $missing): void {
    $this->content();
    $path = $this->directory . '/content/pages/post';
    mkdir($path, 0777, true);
    file_put_contents($path . '/' . $present, $present === 'page.md' ? 'Text' : "<?php declare(strict_types=1); return [];\n");
    $this->catalog();
})->throws(ContentException::class)->with([
    ['page.md', 'meta.php'],
    ['meta.php', 'page.md'],
]);

it('rejects malformed article date directories', function (string $directory, string $message): void {
    $this->content();
    mkdir($this->directory . '/content/articles/' . $directory, 0777, true);
    $this->catalog();
})->throws(ContentException::class)->with([
    ['26', 'year'],
    ['2026/13', 'month'],
    ['2026/02/30', 'day'],
]);

it('requires an article metadata date to match its directory', function (): void {
    $path = $this->article('post', ['title' => 'T', 'description' => 'D', 'date' => '2026-01-02', 'tags' => []]);
    $moved = $this->directory . '/content/articles/2026/01/01/post';
    mkdir(dirname($moved), 0777, true);
    rename($path, $moved);
    $this->catalog();
})->throws(ContentException::class, 'does not match its directory date');

it('rejects a metadata type discriminator', function (mixed $type): void {
    $this->item('post', ['type' => $type, 'title' => 'T', 'description' => 'D']);
    $this->catalog();
})->throws(ContentException::class, 'unknown field(s): type')->with(['page', 'article', 2]);

it('rejects empty and invalid UTF-8 pages', function (string $page, string $message): void {
    $this->item('post', ['title' => 'T', 'description' => 'D'], $page);
    $this->catalog();
})->throws(ContentException::class)->with([
    'empty page' => [" \n\t", 'empty'],
    'invalid UTF-8 page' => ["bad\xFF", 'UTF-8'],
]);

it('rejects special filesystem entries in content', function (): void {
    $path = $this->item('post', ['title' => 'T', 'description' => 'D']);
    expect(posix_mkfifo($path . '/pipe', 0600))->toBeTrue();
    $this->catalog();
})->throws(ContentException::class, 'unsupported filesystem entry');

it('rejects content symlinks at every depth', function (string $position): void {
    $this->content();
    if ($position === 'root') {
        symlink($this->directory, $this->directory . '/content/pages/post');
    } else {
        $path = $this->item('post', ['title' => 'T', 'description' => 'D']);
        symlink($path . '/page.md', $path . '/asset-link');
    }

    $this->catalog();
})->throws(ContentException::class, 'symlink')->with(['root', 'nested']);
it('rejects a content asset whose first path component is index.html', function (): void {
    $path = $this->item('post', ['title' => 'T', 'description' => 'D']);
    file_put_contents($path . '/index.html', 'collision');
    $this->catalog();
})->throws(ContentException::class, 'first component is reserved');

it('rejects metadata output and invalid loading', function (string $body, string $message): void {
    $path = $this->item('post', ['title' => 'T', 'description' => 'D']);
    file_put_contents($path . '/meta.php', $body);
    $this->catalog();
})->throws(ContentException::class)->with([
    ["<?php\ndeclare(strict_types=1); echo 'x'; return [];", 'output'],
    ["<?php\ndeclare(strict_types=1); throw new RuntimeException('broken');", 'broken'],
    ["<?php return [];", 'strict_types'],
    ["<?php\ndeclare(strict_types=1); return 'no';", 'associative'],
    ["<?php\ndeclare(strict_types=1); return [0 => 'no'];", 'string keys'],
]);

it('rejects invalid text fields', function (array $metadata, string $message): void {
    /** @var array<string, mixed> $metadata */
    $this->item('post', $metadata);
    $this->catalog();
})->throws(ContentException::class)->with([
    [['description' => 'D'], 'title'],
    [['title' => ' ', 'description' => 'D'], 'non-empty'],
    [['title' => ' T', 'description' => 'D'], 'whitespace'],
    [['title' => 'T', 'description' => 2], 'description'],
]);

it('enforces exact page fields', function (array $metadata, string $message): void {
    /** @var array<string, mixed> $metadata */
    $this->item('post', $metadata);
    $this->catalog();
})->throws(ContentException::class)->with([
    [['title' => 'T'], 'missing'],
    [['title' => 'T', 'description' => 'D', 'date' => '2026-01-01'], 'unknown'],
    [['title' => 'T', 'description' => 'D', 'extra' => true], 'unknown'],
]);

it('requires article-specific fields', function (): void {
    $this->article('post', ['title' => 'T', 'description' => 'D']);
    $this->catalog();
})->throws(ContentException::class, 'missing field(s): date, tags');

it('validates real article dates', function (mixed $date): void {
    $this->article('post', ['title' => 'T', 'description' => 'D', 'date' => $date, 'tags' => []]);
    $this->catalog();
})->throws(ContentException::class, 'real date')->with([null, 20260101, '2026-1-01', '2026-02-30']);

it('validates article tags', function (mixed $tags, string $message): void {
    $this->article('post', ['title' => 'T', 'description' => 'D', 'date' => '2026-01-01', 'tags' => $tags]);
    $this->catalog();
})->throws(ContentException::class)->with([
    [null, 'list'],
    [['name' => 'php'], 'list'],
    [[2], 'strings'],
    [[['label' => 'PHP']], 'strings'],
    [[''], 'non-empty'],
    [[' PHP'], 'surrounding'],
    [['---'], 'Unicode'],
    [['PHP', 'php'], 'duplicate'],
]);

it('generates canonical tag slugs from ordered labels', function (): void {
    $this->article('post', ['title' => 'T', 'description' => 'D', 'date' => '2026-01-01', 'tags' => [
        'PHP & Web',
        'PHP 8.5',
        '日本語',
    ]]);

    $tags = $this->catalog()->articles[0]->tags;
    expect($tags[0]->label)->toBe('PHP & Web')
        ->and($tags[0]->slug)->toBe('php-web')
        ->and($tags[1]->label)->toBe('PHP 8.5')
        ->and($tags[1]->slug)->toBe('php-8-5')
        ->and($tags[2]->slug)->toBe('日本語');
});

it('requires consistent labels for a tag slug across the catalog', function (): void {
    $this->article('one', ['title' => 'One', 'description' => 'D', 'date' => '2026-01-01', 'tags' => ['PHP']]);
    $this->article('two', ['title' => 'Two', 'description' => 'D', 'date' => '2026-01-02', 'tags' => ['Php']]);
    $this->catalog();
})->throws(ContentException::class, 'inconsistent labels');
