<?php

declare(strict_types=1);

namespace Snippet\Content;

use Snippet\Support\Slug;

/** Supported content kinds. */
enum ContentType: string
{
    case Article = 'article';
    case Page = 'page';

    /** @return list<string> */
    public function metadataFields(): array
    {
        return match ($this) {
            self::Article => ['title', 'description', 'date', 'tags'],
            self::Page => ['title', 'description'],
        };
    }

    public function url(string $slug): string
    {
        $segment = Slug::toUriSegment($slug);

        return match ($this) {
            self::Article => '/articles/' . $segment . '/',
            self::Page => '/' . $segment . '/',
        };
    }
}
