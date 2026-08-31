<?php

declare(strict_types=1);

namespace Snippet\Content;

/** A supported article-cover format with its validated browser media type. */
enum CoverFormat: int
{
    case Jpeg = IMAGETYPE_JPEG;
    case Png = IMAGETYPE_PNG;
    case Webp = IMAGETYPE_WEBP;

    public function filename(): string
    {
        return match ($this) {
            self::Jpeg => 'cover.jpg',
            self::Png => 'cover.png',
            self::Webp => 'cover.webp',
        };
    }

    public function mediaType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Webp => 'image/webp',
        };
    }
}
