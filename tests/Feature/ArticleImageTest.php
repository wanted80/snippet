<?php

declare(strict_types=1);

use Snippet\Content\ArticleImage;
use Snippet\Content\Catalog;
use Snippet\Content\CatalogLoader;
use Snippet\Exception\ContentException;
use Snippet\Publishing\Publisher;
use Snippet\Site\ConfigLoader;
use Snippet\Site\Limits;

mutates(CatalogLoader::class);

it('discovers configured covers in each supported format and derives their metadata', function (string $extension, string $format, ?string $alt, int $width, int $height): void {
    $metadata = [
        'title' => 'Post',
        'description' => 'Description.',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ];
    if ($alt !== null) {
        $metadata['alt'] = $alt;
    }
    $path = $this->article('post', $metadata);
    $this->image($path . "/cover.{$extension}", $format);

    $first = $this->catalog()->articles[0]->image;
    $second = $this->catalog()->articles[0]->image;

    if (!$first instanceof ArticleImage || !$second instanceof ArticleImage) {
        throw new LogicException('Expected the configured article cover to be discovered.');
    }

    expect($first->path)->toBe("cover.{$extension}")
        ->and($first->alt)->toBe($alt ?? '')
        ->and($first->width)->toBe($width)
        ->and($first->height)->toBe($height)
        ->and(serialize($first))->toBe(serialize($second));
})->with([
    'JPEG with alt text' => ['jpg', 'jpeg', 'Descriptive alternative text.', 1, 1],
    'PNG without alt text' => ['png', 'png', null, 1, 1],
    'non-square PNG' => ['png', 'png-wide', null, 2, 1],
    'WebP with alt text' => ['webp', 'webp', 'Descriptive alternative text.', 1, 1],
]);

it('defaults cover to false and leaves unconfigured cover files as ordinary assets', function (bool $explicit): void {
    $metadata = [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ];
    if ($explicit) {
        $metadata['cover'] = false;
    }
    $path = $this->article('post', $metadata);
    $this->image($path . '/cover.webp');

    $article = $this->catalog()->articles[0];

    expect($article->image)->toBeNull()
        ->and($article->assets[0]->path)->toBe('cover.webp');
})->with([
    'omitted' => [false],
    'explicit false' => [true],
]);

it('rejects invalid cover metadata', function (string $field, mixed $value, ?bool $cover, string $message): void {
    $metadata = [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ];
    if ($cover !== null) {
        $metadata['cover'] = $cover;
    }
    $metadata[$field] = $value;
    $path = $this->article('post', $metadata);

    if (($metadata['cover'] ?? null) === true) {
        $this->image($path . '/cover.webp');
    }

    expect(fn() => $this->catalog())
        ->toThrow(ContentException::class, $message);
})->with([
    'string cover' => ['cover', 'true', null, "Metadata field 'cover' for 'post' must be a boolean."],
    'integer cover' => ['cover', 1, null, "Metadata field 'cover' for 'post' must be a boolean."],
    'null cover' => ['cover', null, null, "Metadata field 'cover' for 'post' must be a boolean."],
    'alt without cover' => ['alt', 'Alt.', null, "Metadata field 'alt' for 'post' may only be used when cover is true."],
    'alt with disabled cover' => ['alt', 'Alt.', false, "Metadata field 'alt' for 'post' may only be used when cover is true."],
    'null alt' => ['alt', null, true, "Metadata field 'alt' for 'post' must be a non-empty string."],
    'non-string alt' => ['alt', 1, true, "Metadata field 'alt' for 'post' must be a non-empty string."],
    'empty alt' => ['alt', '', true, "Metadata field 'alt' for 'post' must be a non-empty string."],
    'blank alt' => ['alt', ' ', true, "Metadata field 'alt' for 'post' must be a non-empty string."],
    'padded alt' => ['alt', ' Alt.', true, "Metadata field 'alt' for 'post' must not have surrounding whitespace."],
]);

it('rejects cover metadata on pages', function (string $field, mixed $value): void {
    $this->item('page', [
        'title' => 'Page',
        'description' => 'D',
        $field => $value,
    ]);

    expect(fn() => $this->catalog())
        ->toThrow(ContentException::class, "unknown field(s): {$field}");
})->with([
    'cover' => ['cover', true],
    'alt' => ['alt', 'Alt.'],
]);

it('requires one supported cover filename at the article root when enabled', function (?string $asset, ?string $format): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ]);
    if ($asset !== null) {
        if ($format === null) {
            file_put_contents($path . '/' . $asset, '<svg/>');
        } else {
            $this->image($path . '/' . $asset, $format);
        }
    }

    expect(fn() => $this->catalog())
        ->toThrow(ContentException::class, "Cover for article 'post' is enabled, but no cover.jpg, cover.png, or cover.webp file exists in its directory.");
})->with([
    'missing' => [null, null],
    'JPEG extension alias' => ['cover.jpeg', 'jpeg'],
    'SVG' => ['cover.svg', null],
    'nested file' => ['media/cover.webp', 'webp'],
    'case variant' => ['Cover.webp', 'webp'],
]);

it('rejects ambiguous cover files', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ]);
    $this->image($path . '/cover.jpg', 'jpeg');
    $this->image($path . '/cover.webp');

    expect(fn() => $this->catalog())
        ->toThrow(ContentException::class, "Cover for article 'post' is ambiguous; keep only one of cover.jpg, cover.png, or cover.webp.");
});

it('rejects corrupt images and detected-format mismatches', function (string $kind, string $message): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ]);
    if ($kind === 'corrupt') {
        file_put_contents($path . '/cover.webp', 'not an image');
    } else {
        $this->image($path . '/cover.webp', 'png');
    }

    expect(fn() => $this->catalog())
        ->toThrow(ContentException::class, $message);
})->with([
    'corrupt data' => ['corrupt', "Article cover 'cover.webp' for 'post' must be a readable PNG, JPEG, or WebP image."],
    'format mismatch' => ['format', "Article cover 'cover.webp' for 'post' has a detected format that does not match its file extension."],
]);

it('enforces cover alt-text and derived-dimension limits', function (string $kind, string $message): void {
    $metadata = [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ];
    $extension = $kind === 'alt' ? 'webp' : 'png';
    if ($kind === 'alt') {
        $metadata['alt'] = 'Long';
    }
    $path = $this->article('post', $metadata);
    $format = match ($kind) {
        'width' => 'png-wide',
        'height' => 'png-tall',
        default => 'webp',
    };
    $this->image($path . '/cover.' . $extension, $format);

    expect(fn(): Catalog => new CatalogLoader()->load(
        $this->directory . '/content',
        new Limits(descriptionCharacters: 3, imageDimension: 1),
    ))->toThrow(ContentException::class, $message);
})->with([
    'alt text' => ['alt', "Metadata field 'alt' for 'post' exceeds the 3-character limit."],
    'derived width' => ['width', "Article cover 'cover.png' for 'post' exceeds the 1-pixel image dimension limit."],
    'derived height' => ['height', "Article cover 'cover.png' for 'post' exceeds the 1-pixel image dimension limit."],
]);

it('allows detected cover dimensions exactly at the configured limit', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
        'alt' => 'Alt',
    ]);
    $this->image($path . '/cover.png', 'png');

    $image = new CatalogLoader()->load(
        $this->directory . '/content',
        new Limits(descriptionCharacters: 3, imageDimension: 1),
    )->articles[0]->image;

    expect($image?->width)->toBe(1)
        ->and($image?->height)->toBe(1)
        ->and($image?->alt)->toBe('Alt');
});

it('renders the semantic article figure only in the canonical and featured article contexts', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => ['Images'],
        'cover' => true,
        'alt' => 'Landscape <& "view".',
    ], 'Body.');
    $this->image($path . '/cover.webp');
    $original = file_get_contents($path . '/cover.webp');
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $article = file_get_contents($this->directory . '/public/articles/post/index.html');
    $home = file_get_contents($this->directory . '/public/index.html');
    $archive = file_get_contents($this->directory . '/public/articles/index.html');
    $tag = file_get_contents($this->directory . '/public/tags/images/index.html');
    assert(is_string($article));
    assert(is_string($home));
    assert(is_string($archive));
    assert(is_string($tag));
    expect([$article, $home])->each->toContain('<figure class="article-figure">', '<img src="/articles/post/cover.webp" alt="Landscape &lt;&amp; &quot;view&quot;." width="1" height="1">')->and([$article, $home])->each->not->toContain('<figcaption>', 'loading=', 'srcset=')
        ->and($archive)->not->toContain('article-figure', 'cover.webp')
        ->and($tag)->not->toContain('article-figure', 'cover.webp')
        ->and(file_get_contents($this->directory . '/public/articles/post/cover.webp'))->toBe($original);
});

it('renders empty alternative text when alt is omitted', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ]);
    $this->image($path . '/cover.png', 'png');
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $article = file_get_contents($this->directory . '/public/articles/post/index.html');
    assert(is_string($article));

    expect($article)->toContain(
        '<figure class="article-figure">',
        '<img src="/articles/post/cover.png" alt="" width="1" height="1">',
    )->not->toContain('<figcaption>');
});
