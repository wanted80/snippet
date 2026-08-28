<?php

declare(strict_types=1);

namespace Snippet\Rendering;

/** Identifies every editable HTML template and its required placeholders. */
enum Template: string
{
    case Layout = 'layout.html';
    case Home = 'home.html';
    case FeaturedArticle = 'featured-article.html';
    case ArticleFigure = 'article-figure.html';
    case HomeCollection = 'home-collection.html';
    case ArchiveItem = 'archive-item.html';
    case TagItem = 'tag-item.html';
    case TagUsage = 'tag-usage.html';
    case ContentPage = 'content-page.html';
    case CollectionPage = 'collection-page.html';
    case ContentSummary = 'content-summary.html';
    case TagList = 'tag-list.html';

    /** @return list<non-empty-string> */
    public function placeholders(): array
    {
        return match ($this) {
            self::Layout => ['language', 'description', 'author', 'version', 'title', 'canonical', 'social_metadata', 'base_path', 'preloads', 'site_stylesheet', 'site_script', 'sitename', 'navigation', 'body'],
            self::Home => ['site_title', 'featured_article', 'archive_section', 'tag_section', 'empty_state', 'home_grid_class'],
            self::FeaturedArticle => ['url', 'title', 'date', 'tags', 'figure', 'document'],
            self::ArticleFigure => ['url', 'alt', 'width', 'height'],
            self::HomeCollection => ['section_class', 'heading_id', 'eyebrow', 'title', 'list_class', 'items', 'index_link'],
            self::ArchiveItem => ['url', 'title', 'date'],
            self::TagItem => ['url', 'label'],
            self::TagUsage => ['url', 'label', 'count', 'accessible_label'],
            self::ContentPage => ['content_class', 'title', 'metadata', 'figure', 'document'],
            self::CollectionPage => ['eyebrow', 'title', 'introduction', 'collection_label', 'list_class', 'items', 'empty_state'],
            self::ContentSummary => ['url', 'title', 'date', 'description', 'tags'],
            self::TagList => ['items'],
        };
    }
}
