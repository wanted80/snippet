<?php

declare(strict_types=1);

namespace Snippet\Rendering;

use DateTimeImmutable;
use Snippet\Content\Article;
use Snippet\Content\ArticleImage;
use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Content\Tag;
use Snippet\Content\TagUsage;
use Snippet\Site\Config;
use Snippet\Support\ApplicationVersion;

/** Renders complete semantic documents from validated site and content models. */
final readonly class HtmlRenderer
{
    private const string UPRIGHT_FONT_ASSET = 'fonts/atkinson-hyperlegible-next/atkinson-hyperlegible-next-variable.woff2';

    private const string WORDMARK_FONT_ASSET = 'fonts/snippet-logo/snippet-logo.woff2';

    /** @var list<Page> */
    private array $menuPages;

    private string $preloads;

    private string $siteStylesheet;

    private string $siteScript;

    public function __construct(
        private Config $config,
        private Catalog $catalog,
        private Templates $templates,
        private AssetPaths $assets,
    ) {
        $this->menuPages = $this->orderedMenuPages();
        $this->preloads = $this->renderPreloads();
        $this->siteStylesheet = $this->renderSiteStylesheet();
        $this->siteScript = $this->renderSiteScript();
    }

    public function home(): string
    {
        $latest = array_first($this->catalog->articles);
        $featuredArticle = '';
        $archiveSection = '';
        $tagSection = '';
        $emptyState = $this->emptyState('No articles have been published yet.');
        if ($latest instanceof Article) {
            $emptyState = '';
            $featuredArticle = $this->featuredArticle($latest);
            $remaining = array_slice($this->catalog->articles, 1, $this->config->homeArticles);
            if ($remaining !== []) {
                $items = '';
                foreach ($remaining as $article) {
                    $items .= $this->homeArticleSummary($article);
                }

                $archiveSection = $this->templates->render(Template::HomeCollection, [
                    'section_class' => 'home-archive',
                    'heading_id' => 'more-articles',
                    'eyebrow' => 'Archive',
                    'title' => 'More articles',
                    'list_class' => 'archive-list',
                    'items' => $items,
                    'index_link' => count($this->catalog->articles) - 1 > $this->config->homeArticles
                        ? $this->indexLink('/articles/', 'View all articles')
                        : '',
                ]);
            }

            $allUsages = $this->catalog->tagUsages();
            $usages = array_slice($allUsages, 0, $this->config->homeTags);
            if ($usages !== []) {
                $items = '';
                foreach ($usages as $usage) {
                    $items .= $this->tagUsage($usage);
                }

                $tagSection = $this->templates->render(Template::HomeCollection, [
                    'section_class' => 'home-tags',
                    'heading_id' => 'browse-tags',
                    'eyebrow' => 'Topics',
                    'title' => 'Browse by tag',
                    'list_class' => 'tag-grid',
                    'items' => $items,
                    'index_link' => count($allUsages) > $this->config->homeTags
                        ? $this->indexLink('/tags/', 'View all tags')
                        : '',
                ]);
            }
        }

        $hasSecondaryCollection = $archiveSection !== '' || $tagSection !== '';

        $body = $this->templates->render(Template::Home, [
            'site_title' => $this->escape($this->config->title),
            'featured_article' => $featuredArticle,
            'archive_section' => $archiveSection,
            'tag_section' => $tagSection,
            'empty_state' => $emptyState,
            'home_grid_class' => $hasSecondaryCollection ? 'home-grid' : 'home-grid home-grid-empty',
        ]);

        return $this->layout($this->config->title, $this->config->description, '/', $body, null);
    }

    public function notFound(): string
    {
        $body = $this->templates->render(Template::NotFound, [
            'home_url' => $this->browserPath('/'),
        ]);

        return $this->layout(
            'Page not found — ' . $this->config->title,
            'The requested page could not be found.',
            '/404.html',
            $body,
            null,
            noIndex: true,
        );
    }

    public function content(Article|Page $item): string
    {
        $metadata = '';
        if ($item instanceof Article) {
            $metadata = $this->date($item) . "\n" . $this->tagList($item->tags) . "\n";
        }

        $body = $this->templates->render(Template::ContentPage, [
            'content_class' => $item instanceof Article ? 'content-article' : 'content-page',
            'title' => $this->escape($item->title),
            'metadata' => $metadata,
            'figure' => $item instanceof Article ? $this->articleFigure($item) : '',
            'document' => MarkdownHtmlRenderer::render($item->document, basePath: $this->config->basePath),
        ]);

        return $this->layout(
            $item->title . ' — ' . $this->config->title,
            $item->description,
            $item->url(),
            $body,
            $item instanceof Page ? $item->slug : null,
            $item->title,
            $item instanceof Article ? 'article' : 'website',
            $item instanceof Article ? $item->image : null,
        );
    }

    public function pages(): string
    {
        $items = '';
        foreach ($this->catalog->pages as $page) {
            $items .= $this->contentSummary($page);
        }

        $body = $this->templates->render(Template::CollectionPage, [
            'eyebrow' => 'Directory',
            'title' => 'Pages',
            'introduction' => $this->escape('Every permanent page on ' . $this->config->title . '.'),
            'collection_label' => 'Pages',
            'list_class' => 'page-list',
            'items' => $items,
            'empty_state' => $items === '' ? $this->emptyState('No pages have been published yet.') : '',
        ]);

        return $this->layout('Pages — ' . $this->config->title, 'All pages on ' . $this->config->title . '.', '/pages/', $body, 'pages');
    }

    public function articles(): string
    {
        $items = '';
        foreach ($this->catalog->articles as $article) {
            $items .= $this->contentSummary($article);
        }

        $body = $this->templates->render(Template::CollectionPage, [
            'eyebrow' => 'Archive',
            'title' => 'Articles',
            'introduction' => $this->escape('Every article on ' . $this->config->title . '.'),
            'collection_label' => 'Articles',
            'list_class' => 'article-list',
            'items' => $items,
            'empty_state' => $items === '' ? $this->emptyState('No articles have been published yet.') : '',
        ]);

        return $this->layout('Articles — ' . $this->config->title, 'All articles on ' . $this->config->title . '.', '/articles/', $body, null);
    }

    public function tags(): string
    {
        $items = '';
        foreach ($this->catalog->tagUsages() as $usage) {
            $items .= $this->tagUsage($usage);
        }

        $body = $this->templates->render(Template::CollectionPage, [
            'eyebrow' => 'Topics',
            'title' => 'Tags',
            'introduction' => $this->escape('Every topic on ' . $this->config->title . '.'),
            'collection_label' => 'Tags',
            'list_class' => 'tag-grid tag-index',
            'items' => $items,
            'empty_state' => $items === '' ? $this->emptyState('No tags are available yet.') : '',
        ]);

        return $this->layout('Tags — ' . $this->config->title, 'All tags on ' . $this->config->title . '.', '/tags/', $body, null);
    }

    public function tag(Tag $tag): string
    {
        $items = '';
        foreach ($this->catalog->articlesForTag($tag) as $article) {
            $items .= $this->contentSummary($article);
        }

        $title = 'Tag: ' . $tag->label;
        $body = $this->templates->render(Template::CollectionPage, [
            'eyebrow' => 'Topic',
            'title' => $this->escape($tag->label),
            'introduction' => $this->escape('Articles tagged ' . $tag->label . '.'),
            'collection_label' => 'Articles',
            'list_class' => 'article-list',
            'items' => $items,
            'empty_state' => '',
        ]);

        return $this->layout($title . ' — ' . $this->config->title, 'Articles tagged ' . $tag->label . '.', $tag->url(), $body, null);
    }

    /** @return list<Page> */
    private function orderedMenuPages(): array
    {
        $menuPages = [];
        foreach ($this->catalog->pages as $page) {
            if ($page->menuOrder !== null) {
                $menuPages[] = $page;
            }
        }
        usort($menuPages, static function (Page $left, Page $right): int {
            $order = $left->menuOrder <=> $right->menuOrder;
            return $order !== 0 ? $order : strcmp($left->slug, $right->slug);
        });

        return $menuPages;
    }

    private function renderPreloads(): string
    {
        $preloads = '';
        if ($this->config->hasSiteStylesheet) {
            if (in_array(self::UPRIGHT_FONT_ASSET, $this->config->assets, true)) {
                $preloads .= '<link rel="preload" href="' . $this->browserPath('/assets/site/' . self::UPRIGHT_FONT_ASSET) . '" as="font" type="font/woff2" crossorigin>' . "\n"; // @pest-mutate-ignore: ConcatEqualToEqual
            }
            if (in_array(self::WORDMARK_FONT_ASSET, $this->config->assets, true)) {
                $preloads .= '<link rel="preload" href="' . $this->browserPath('/assets/site/' . self::WORDMARK_FONT_ASSET) . '" as="font" type="font/woff2" crossorigin>' . "\n";
            }
        }

        return $preloads;
    }

    private function renderSiteStylesheet(): string
    {
        return $this->assets->siteStylesheet !== null
            ? '<link rel="stylesheet" href="' . $this->browserPath($this->assets->siteStylesheet) . "\">\n"
            : '';
    }

    private function renderSiteScript(): string
    {
        return $this->assets->siteScript !== null
            ? '<script src="' . $this->browserPath($this->assets->siteScript) . '" defer></script>' . "\n"
            : '';
    }

    private function layout(
        string $title,
        string $description,
        string $route,
        string $body,
        ?string $currentPage,
        ?string $socialTitle = null,
        string $socialType = 'website',
        ?ArticleImage $socialImage = null,
        bool $noIndex = false,
    ): string {
        $navigation = $this->navigationLink('/articles/', 'Articles', str_starts_with($route, '/articles/'));
        $navigation .= "\n" . $this->navigationLink('/tags/', 'Tags', str_starts_with($route, '/tags/'));
        $navigation .= "\n" . $this->navigationLink('/pages/', 'Pages', $currentPage === 'pages');
        foreach ($this->menuPages as $page) {
            $navigation .= "\n" . $this->navigationLink($page->url(), $page->title, $currentPage === $page->slug);
        }
        $navigation .= "\n" . $this->navigationLink('/llms.txt', 'llms.txt', false);

        return $this->templates->render(Template::Layout, [
            'language' => $this->escape($this->config->language),
            'description' => $this->escape($description),
            'author' => $this->escape($this->config->author),
            'version' => ApplicationVersion::CURRENT,
            'title' => $this->escape($title),
            'canonical' => $this->escape($this->config->canonicalUrl($route)),
            'social_metadata' => ($noIndex ? '<meta name="robots" content="noindex, follow">' . "\n" : '')
                . $this->socialMetadata($socialTitle ?? $title, $description, $route, $socialType, $socialImage),
            'base_path' => $this->escape($this->config->basePath),
            'preloads' => $this->preloads,
            'theme_script' => '<script src="' . $this->browserPath($this->assets->themeScript) . '"></script>',
            'theme_stylesheet' => '<link rel="stylesheet" href="' . $this->browserPath($this->assets->themeStylesheet) . '">',
            'site_stylesheet' => $this->siteStylesheet,
            'site_script' => $this->siteScript,
            'sitename' => $this->escape($this->config->sitename),
            'navigation' => $navigation,
            'body' => $body,
        ]);
    }

    private function featuredArticle(Article $article): string
    {
        return $this->templates->render(Template::FeaturedArticle, [
            'url' => $this->browserPath($article->url()),
            'title' => $this->escape($article->title),
            'date' => $this->date($article),
            'tags' => $this->tagList($article->tags),
            'figure' => $this->articleFigure($article),
            'document' => MarkdownHtmlRenderer::render($article->document, 2, $this->config->publicPath($article->url()), $this->config->basePath),
        ]);
    }

    private function articleFigure(Article $article): string
    {
        if (!$article->image instanceof ArticleImage) {
            return '';
        }

        $path = implode('/', array_map(rawurlencode(...), explode('/', $article->image->path)));
        return $this->templates->render(Template::ArticleFigure, [
            'url' => $this->browserPath($article->url() . $path),
            'alt' => $this->escape($article->image->alt),
            'width' => (string) $article->image->width, // @pest-mutate-ignore: RemoveStringCast
            'height' => (string) $article->image->height, // @pest-mutate-ignore: RemoveStringCast
        ]);
    }

    private function socialMetadata(
        string $title,
        string $description,
        string $route,
        string $type,
        ?ArticleImage $image,
    ): string {
        $escapedTitle = $this->escape($title);
        $escapedDescription = $this->escape($description);
        $metadata = '<meta property="og:type" content="' . $type . "\">\n"
            . '<meta property="og:title" content="' . $escapedTitle . "\">\n"
            . '<meta property="og:description" content="' . $escapedDescription . "\">\n"
            . '<meta property="og:url" content="' . $this->escape($this->config->canonicalUrl($route)) . "\">\n"
            . '<meta property="og:site_name" content="' . $this->escape($this->config->sitename) . "\">\n";

        if ($image instanceof ArticleImage) {
            $imageUrl = $this->socialImageUrl($route, $image);
            $metadata .= '<meta property="og:image" content="' . $imageUrl . "\">\n"
                . '<meta property="og:image:type" content="' . $image->format->mediaType() . "\">\n"
                . '<meta property="og:image:width" content="' . $image->width . "\">\n"
                . '<meta property="og:image:height" content="' . $image->height . "\">\n";
            if ($image->alt !== '') {
                $metadata .= '<meta property="og:image:alt" content="' . $this->escape($image->alt) . "\">\n";
            }
        }

        $metadata .= '<meta name="twitter:card" content="' . ($image instanceof ArticleImage ? 'summary_large_image' : 'summary') . "\">\n"
            . '<meta name="twitter:title" content="' . $escapedTitle . "\">\n"
            . '<meta name="twitter:description" content="' . $escapedDescription . "\">\n";
        if ($image instanceof ArticleImage) {
            $metadata .= '<meta name="twitter:image" content="' . $this->socialImageUrl($route, $image) . "\">\n";
            if ($image->alt !== '') {
                $metadata .= '<meta name="twitter:image:alt" content="' . $this->escape($image->alt) . "\">\n";
            }
        }

        return $metadata;
    }

    private function socialImageUrl(string $route, ArticleImage $image): string
    {
        $path = implode('/', array_map(rawurlencode(...), explode('/', $image->path)));

        return $this->escape($this->config->canonicalUrl($route) . $path);
    }

    private function homeArticleSummary(Article $article): string
    {
        return $this->templates->render(Template::ArchiveItem, [
            'url' => $this->browserPath($article->url()),
            'title' => $this->escape($article->title),
            'date' => $this->date($article),
        ]);
    }

    private function contentSummary(Article|Page $item): string
    {
        $isArticle = $item instanceof Article;

        return $this->templates->render(Template::ContentSummary, [
            'url' => $this->browserPath($item->url()),
            'title' => $this->escape($item->title),
            'date' => $isArticle ? $this->date($item) : '',
            'description' => $this->escape($item->description),
            'tags' => $isArticle ? $this->tagList($item->tags) : '',
        ]);
    }

    private function date(Article $article): string
    {
        $date = new DateTimeImmutable($article->date);
        return '<time datetime="' . $article->date . '">' . $date->format('F j, Y') . '</time>';
    }

    private function tagUsage(TagUsage $usage): string
    {
        $countLabel = $usage->articles === 1 ? 'article' : 'articles';
        $accessibleLabel = $usage->tag->label . ', ' . $usage->articles . ' ' . $countLabel;

        return $this->templates->render(Template::TagUsage, [
            'url' => $this->browserPath($usage->tag->url()),
            'label' => $this->escape($usage->tag->label),
            'count' => (string) $usage->articles, // @pest-mutate-ignore: RemoveStringCast
            'accessible_label' => $this->escape($accessibleLabel),
        ]);
    }

    private function emptyState(string $message): string
    {
        return '<p class="empty-state">' . $this->escape($message) . '</p>';
    }

    private function indexLink(string $url, string $label): string
    {
        return '<p class="index-link"><a class="button-link" href="'
            . $this->browserPath($url)
            . '">' . $this->escape($label)
            . '<svg class="button-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6"></path></svg></a></p>';
    }

    private function navigationLink(string $url, string $label, bool $current): string
    {
        $escapedLabel = $this->escape($label);
        $ariaCurrent = $current ? ' aria-current="page"' : '';

        return '<a class="menu-link" href="' . $this->browserPath($url) . '"' . $ariaCurrent . '>' . $escapedLabel . '</a>';
    }

    private function tagItem(Tag $tag): string
    {
        return $this->templates->render(Template::TagItem, [
            'url' => $this->browserPath($tag->url()),
            'label' => $this->escape($tag->label),
        ]);
    }

    /** @param list<Tag> $tags */
    private function tagList(array $tags): string
    {
        if ($tags === []) {
            return '';
        }

        $items = '';
        foreach ($tags as $tag) {
            $items .= $this->tagItem($tag);
        }

        return $this->templates->render(Template::TagList, [
            'items' => $items,
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function browserPath(string $route): string
    {
        return $this->escape($this->config->publicPath($route));
    }
}
