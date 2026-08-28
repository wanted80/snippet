<?php

declare(strict_types=1);

use Snippet\Content\ContentType;
use Snippet\Content\CoverFormat;

mutates(ContentType::class, CoverFormat::class);

it('keeps each supported cover filename and media type with its format', function (CoverFormat $format, string $filename, string $mediaType): void {
    expect($format->filename())->toBe($filename)
        ->and($format->mediaType())->toBe($mediaType);
})->with([
    'JPEG' => [CoverFormat::Jpeg, 'cover.jpg', 'image/jpeg'],
    'PNG' => [CoverFormat::Png, 'cover.png', 'image/png'],
    'WebP' => [CoverFormat::Webp, 'cover.webp', 'image/webp'],
]);

it('keeps each content source layout with its content type', function (ContentType $type, string $collection, string $sourceFilename): void {
    expect($type->collection())->toBe($collection)
        ->and($type->sourceFilename())->toBe($sourceFilename);
})->with([
    'article' => [ContentType::Article, 'articles', 'article.md'],
    'page' => [ContentType::Page, 'pages', 'page.md'],
]);
