<?php

declare(strict_types=1);

namespace Snippet\Content;

use DateTimeImmutable;
use NoDiscard;
use Snippet\Exception\ContentException;
use Snippet\Markdown\Parser;
use Snippet\Site\Limits;
use Snippet\Support\RegularFileInventory;
use Snippet\Support\Slug;

use function array_diff;
use function array_filter;
use function array_first;
use function array_keys;
use function checkdate;
use function file_get_contents;
use function getimagesize;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function mb_check_encoding;
use function mb_trim;
use function mb_ucfirst;
use function preg_match;
use function scandir;
use function strcmp;

/**
 * Discovers and validates content directories, then builds a deterministic catalog.
 *
 * This is the orchestration boundary for content loading: it inventories files,
 * delegates PHP metadata loading and Markdown parsing, validates type-specific
 * metadata, and orders the resulting immutable content items. It never writes to
 * the content tree or generates site output.
 */
final readonly class CatalogLoader
{
    public function __construct(
        private Parser $parser = new Parser(),
        private MetadataLoader $metadataLoader = new MetadataLoader(),
        private RegularFileInventory $fileInventory = new RegularFileInventory(),
        private Limits $limits = new Limits(),
    ) {}

    /**
     * Load every valid direct child of the given content directory.
     *
     * Non-directory entries at the catalog root are ignored. Invalid content,
     * unreadable files, unsupported filesystem entries, and symlinks cause the
     * entire load to fail rather than produce a partial catalog.
     *
     * @throws ContentException when the content tree is invalid or unreadable
     */
    #[NoDiscard('the loaded catalog should be consumed')]
    public function load(string $contentDirectory, ?Limits $limits = null): Catalog
    {
        if ($limits instanceof Limits) {
            return new self($this->parser, $this->metadataLoader, $this->fileInventory, $limits)->load($contentDirectory);
        }

        if (!is_dir($contentDirectory)) {
            throw new ContentException(sprintf("Content directory '%s' does not exist.", $contentDirectory));
        }

        if (is_link($contentDirectory)) {
            throw new ContentException(sprintf("Content directory '%s' must be a regular non-symlink directory.", $contentDirectory));
        }

        $budget = new CatalogBudget($this->limits);
        $pages = $this->loadPages($contentDirectory . '/' . ContentType::Page->collection(), $budget);
        $articles = $this->loadArticles($contentDirectory . '/' . ContentType::Article->collection(), $budget);

        usort($articles, static function (Article $left, Article $right): int {
            $dateOrder = strcmp($right->date, $left->date);
            return $dateOrder !== 0 ? $dateOrder : strcmp($left->slug, $right->slug);
        });
        usort($pages, static function (Page $left, Page $right): int {
            $titleOrder = strcmp($left->title, $right->title);
            return $titleOrder !== 0 ? $titleOrder : strcmp($left->slug, $right->slug);
        });

        $this->validateTagLabels($articles);
        $menuOrders = [];
        foreach ($pages as $page) {
            if ($page->menuOrder === null) {
                continue;
            }
            if (isset($menuOrders[$page->menuOrder])) {
                throw new ContentException(sprintf("Pages '%s' and '%s' use duplicate menu_order %d.", $menuOrders[$page->menuOrder], $page->slug, $page->menuOrder));
            }
            $menuOrders[$page->menuOrder] = $page->slug;
        }
        if (count($menuOrders) > $this->limits->menuPages) {
            throw new ContentException(sprintf('No more than %d pages may define menu_order.', $this->limits->menuPages));
        }

        return new Catalog($articles, $pages);
    }

    /** @return list<Page> */
    private function loadPages(string $directory, CatalogBudget $budget): array
    {
        $this->requireCollectionDirectory($directory, 'Page collection');
        $pages = [];
        $directories = $this->directories($directory, 'page collection');
        if (count($directories) > $this->limits->pages) {
            throw new ContentException(sprintf("Page collection exceeds the %d-page limit.", $this->limits->pages));
        }
        foreach ($directories as $slug) {
            $this->validateSlug($slug);
            $item = $this->loadItem($directory . '/' . $slug, $slug, ContentType::Page, $budget);
            /** @var Page $item */
            $pages[] = $item;
        }

        return $pages;
    }

    /** @return list<Article> */
    private function loadArticles(string $directory, CatalogBudget $budget): array
    {
        $this->requireCollectionDirectory($directory, 'Article collection');
        $articles = [];
        foreach ($this->directories($directory, 'article collection') as $year) {
            if (preg_match('/^\d{4}$/D', $year) !== 1) {
                throw new ContentException("Invalid article year directory '{$year}'; use four digits.");
            }

            $yearPath = $directory . '/' . $year;
            foreach ($this->directories($yearPath, "article year '{$year}'") as $month) {
                if (preg_match('/^(?:0[1-9]|1[0-2])$/D', $month) !== 1) {
                    throw new ContentException("Invalid article month directory '{$year}/{$month}'; use 01 through 12.");
                }

                $monthPath = $yearPath . '/' . $month;
                foreach ($this->directories($monthPath, "article month '{$year}/{$month}'") as $day) {
                    if (preg_match('/^\d{2}$/D', $day) !== 1 || !checkdate((int) $month, (int) $day, (int) $year)) {
                        throw new ContentException("Invalid article day directory '{$year}/{$month}/{$day}'; use a real calendar date.");
                    }

                    $date = "{$year}-{$month}-{$day}";
                    $dayPath = $monthPath . '/' . $day;
                    foreach ($this->directories($dayPath, "article date '{$date}'") as $slug) {
                        if (count($articles) >= $this->limits->articles) {
                            throw new ContentException(sprintf("Article collection exceeds the %d-article limit.", $this->limits->articles));
                        }
                        $this->validateSlug($slug);
                        $item = $this->loadItem($dayPath . '/' . $slug, $slug, ContentType::Article, $budget, $date);
                        /** @var Article $item */
                        $articles[] = $item;
                    }
                }
            }
        }

        return $articles;
    }

    private function requireCollectionDirectory(string $directory, string $subject): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new ContentException("{$subject} directory '{$directory}' does not exist or is not a regular non-symlink directory.");
        }
    }

    /** @return list<string> */
    private function directories(string $directory, string $subject): array
    {
        $directories = [];
        $entries = @scandir($directory, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            throw new ContentException(sprintf("Unable to read directory '%s'.", $directory));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_link($path)) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " entry '{$entry}' is a symlink; symlinks are not allowed.");
            }

            if (is_dir($path)) {
                $directories[] = $entry;
            }
        }

        return $directories;
    }

    /**
     * Validate and convert one content directory into its type-specific DTO.
     *
     * @throws ContentException when the item is invalid or unreadable
     */
    private function loadItem(
        string $path,
        string $slug,
        ContentType $expectedType,
        CatalogBudget $budget,
        ?string $directoryDate = null,
    ): Article|Page {
        $files = $this->fileInventory->files($path, "content item '{$slug}'");
        $sourceName = $expectedType->sourceFilename();
        $sourcePath = $path . '/' . $sourceName;
        $metadataPath = $path . '/meta.php';
        $sourceFiles = [$sourceName, 'meta.php'];
        foreach ($sourceFiles as $name) {
            if (!is_file($path . '/' . $name)) {
                throw new ContentException(sprintf("Content item '%s' is missing %s.", $slug, $name));
            }
        }

        $markdownSize = @filesize($sourcePath);
        if (!is_int($markdownSize) || $markdownSize > $this->limits->markdownBytes) {
            throw new ContentException(sprintf("Markdown for '%s' exceeds the %d-byte document limit.", $slug, $this->limits->markdownBytes));
        }
        $budget->addMarkdown($markdownSize);
        $markdown = @file_get_contents($sourcePath);
        if ($markdown === false) {
            throw new ContentException(sprintf("Unable to read %s for '%s' at '%s'.", $expectedType->value, $slug, $sourcePath));
        }

        if (!mb_check_encoding($markdown, 'UTF-8')) {
            throw new ContentException(sprintf("%s for '%s' is not valid UTF-8.", mb_ucfirst($expectedType->value, 'UTF-8'), $slug));
        }

        if (mb_trim($markdown) === '') {
            throw new ContentException(sprintf("%s for '%s' must not be empty.", mb_ucfirst($expectedType->value, 'UTF-8'), $slug));
        }

        $metadata = $this->metadataLoader->load($metadataPath, $slug, $this->limits->metadataBytes);

        $document = $this->parser->parse($markdown, $sourcePath, $this->limits->markdownDepth);
        $budget->addDocument($document, $slug);
        $assets = [];
        foreach ($files as $file) {
            if (!in_array($file, $sourceFiles, true)) {
                if ($file === 'index.html' || str_starts_with($file, 'index.html/')) {
                    throw new ContentException(sprintf("Content item '%s' contains asset path '%s', whose first component is reserved.", $slug, $file));
                }

                if (mb_substr_count($file, "/") + 1 > $this->limits->assetDepth) {
                    throw new ContentException(sprintf("Asset '%s' for '%s' exceeds directory depth %d.", $file, $slug, $this->limits->assetDepth));
                }
                $size = @filesize($path . "/" . $file);
                if (!is_int($size) || $size > $this->limits->assetBytes) {
                    throw new ContentException(sprintf("Asset '%s' for '%s' exceeds the %d-byte limit.", $file, $slug, $this->limits->assetBytes));
                }
                $budget->addAsset($size);
                $assets[] = new Asset($file);
                if (count($assets) > $this->limits->assetsPerItem) {
                    throw new ContentException(sprintf("Content item '%s' exceeds the %d-asset limit.", $slug, $this->limits->assetsPerItem));
                }
            }
        }

        $title = $this->requiredText($metadata, 'title', $slug);
        $description = $this->requiredText($metadata, 'description', $slug);
        $fields = $expectedType->metadataFields();
        if ($expectedType === ContentType::Article) {
            foreach (['cover', 'alt'] as $optionalField) {
                if (array_key_exists($optionalField, $metadata)) {
                    $fields[] = $optionalField;
                }
            }
        }
        if ($expectedType === ContentType::Page && array_key_exists("menu_order", $metadata)) {
            $fields[] = "menu_order";
        }
        $this->assertFields($metadata, $fields, $slug);

        if ($expectedType === ContentType::Article) {
            $date = $this->date($metadata, $slug);
            if ($date !== $directoryDate) {
                throw new ContentException(sprintf("Metadata date '%s' for '%s' does not match its directory date '%s'.", $date, $slug, $directoryDate));
            }

            return new Article(
                $slug,
                $title,
                $description,
                $date,
                $this->tags($metadata, $slug),
                $document,
                $assets,
                $this->cover($metadata, $slug, $path, $files),
            );
        }

        $menuOrder = $metadata["menu_order"] ?? null;
        if ($menuOrder !== null && (!is_int($menuOrder) || $menuOrder < 1)) {
            throw new ContentException(sprintf("Metadata field 'menu_order' for '%s' must be a positive integer.", $slug));
        }

        return new Page($slug, $title, $description, $document, $assets, $menuOrder);
    }

    private function validateSlug(string $slug): void
    {
        if (!Slug::isCanonicalAscii($slug)) {
            throw new ContentException(sprintf("Invalid content slug '%s'; use lowercase ASCII letters and numbers separated by single hyphens.", $slug));
        }

        if (Slug::isReservedContent($slug)) {
            throw new ContentException(sprintf("Content slug '%s' is reserved because it collides with a site route.", $slug));
        }
    }

    /**
     * Read a required, already-trimmed textual metadata field.
     *
     * @param array<string, mixed> $metadata
     */
    private function requiredText(array $metadata, string $field, string $slug): string
    {
        if (!isset($metadata[$field]) || !is_string($metadata[$field]) || mb_trim($metadata[$field]) === '') {
            throw new ContentException(sprintf("Metadata field '%s' for '%s' must be a non-empty string.", $field, $slug));
        }

        if ($metadata[$field] !== mb_trim($metadata[$field])) {
            throw new ContentException(sprintf("Metadata field '%s' for '%s' must not have surrounding whitespace.", $field, $slug));
        }

        $maximum = $field === "title" ? $this->limits->titleCharacters : $this->limits->descriptionCharacters;
        if (mb_strlen($metadata[$field]) > $maximum) {
            throw new ContentException(sprintf("Metadata field '%s' for '%s' exceeds the %d-character limit.", $field, $slug, $maximum));
        }

        return $metadata[$field];
    }

    /**
     * Require an exact metadata shape for the resolved content type.
     *
     * @param array<string, mixed> $metadata
     * @param list<string> $expected
     */
    private function assertFields(array $metadata, array $expected, string $slug): void
    {
        $missing = array_values(array_diff($expected, array_keys($metadata)));
        $unknown = array_values(array_diff(array_keys($metadata), $expected));
        if ($missing !== []) {
            throw new ContentException(sprintf("Metadata for '%s' is missing field(s): ", $slug) . implode(', ', $missing) . '.');
        }

        if ($unknown !== []) {
            throw new ContentException(sprintf("Metadata for '%s' has unknown field(s): ", $slug) . implode(', ', $unknown) . '.');
        }
    }

    /**
     * Resolve and validate an enabled article cover against its source bytes.
     *
     * @param array<string, mixed> $metadata
     * @param list<string> $files
     */
    private function cover(array $metadata, string $slug, string $directory, array $files): ?ArticleImage
    {
        $cover = array_key_exists('cover', $metadata) ? $metadata['cover'] : false;
        if (!is_bool($cover)) {
            throw new ContentException("Metadata field 'cover' for '{$slug}' must be a boolean.");
        }

        $hasAlt = array_key_exists('alt', $metadata);
        if (!$cover) {
            if ($hasAlt) {
                throw new ContentException("Metadata field 'alt' for '{$slug}' may only be used when cover is true.");
            }

            return null;
        }

        $alt = $hasAlt ? $this->altText($metadata['alt'], $slug) : '';
        $candidates = array_values(array_filter(
            CoverFormat::cases(),
            static fn(CoverFormat $format): bool => in_array($format->filename(), $files, true),
        ));

        if ($candidates === []) {
            throw new ContentException("Cover for article '{$slug}' is enabled, but no cover.jpg, cover.png, or cover.webp file exists in its directory.");
        }
        if (count($candidates) > 1) {
            throw new ContentException("Cover for article '{$slug}' is ambiguous; keep only one of cover.jpg, cover.png, or cover.webp.");
        }
        $expectedFormat = array_first($candidates);
        $path = $expectedFormat->filename();
        $detected = @getimagesize($directory . '/' . $path);
        if ($detected === false) {
            throw new ContentException("Article cover '{$path}' for '{$slug}' must be a readable PNG, JPEG, or WebP image.");
        }
        if ($detected[2] !== $expectedFormat->value) {
            throw new ContentException("Article cover '{$path}' for '{$slug}' has a detected format that does not match its file extension.");
        }
        if ($detected[0] > $this->limits->imageDimension || $detected[1] > $this->limits->imageDimension) {
            throw new ContentException("Article cover '{$path}' for '{$slug}' exceeds the {$this->limits->imageDimension}-pixel image dimension limit.");
        }

        return new ArticleImage($path, $alt, $detected[0], $detected[1], $expectedFormat);
    }

    private function altText(mixed $value, string $slug): string
    {
        if (!is_string($value) || mb_trim($value) === '') {
            throw new ContentException("Metadata field 'alt' for '{$slug}' must be a non-empty string.");
        }
        if ($value !== mb_trim($value)) {
            throw new ContentException("Metadata field 'alt' for '{$slug}' must not have surrounding whitespace.");
        }
        if (mb_strlen($value) > $this->limits->descriptionCharacters) {
            throw new ContentException("Metadata field 'alt' for '{$slug}' exceeds the {$this->limits->descriptionCharacters}-character limit.");
        }

        return $value;
    }

    /**
     * Validate and return a canonical calendar date.
     *
     * @param array<string, mixed> $metadata
     */
    private function date(array $metadata, string $slug): string
    {
        if (!isset($metadata['date']) || !is_string($metadata['date']) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $metadata['date']) !== 1) {
            throw new ContentException(sprintf("Metadata field 'date' for '%s' must be a real date in YYYY-MM-DD format.", $slug));
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $metadata['date']);
        if ($date === false || $date->format('Y-m-d') !== $metadata['date']) {
            throw new ContentException(sprintf("Metadata field 'date' for '%s' must be a real date in YYYY-MM-DD format.", $slug));
        }

        return $metadata['date'];
    }

    /**
     * Validate tags while preserving their source order.
     *
     * @param array<string, mixed> $metadata
     * @return list<Tag>
     */
    private function tags(array $metadata, string $slug): array
    {
        if (!isset($metadata['tags']) || !is_array($metadata['tags']) || !array_is_list($metadata['tags'])) {
            throw new ContentException(sprintf("Metadata field 'tags' for '%s' must be a list of tag label strings.", $slug));
        }

        if (count($metadata["tags"]) > $this->limits->tagsPerArticle) {
            throw new ContentException(sprintf("Metadata field 'tags' for '%s' exceeds the %d-tag limit.", $slug, $this->limits->tagsPerArticle));
        }

        $tags = [];
        foreach ($metadata['tags'] as $index => $label) {
            if (!is_string($label)) {
                throw new ContentException(sprintf("Metadata field 'tags' for '%s' must contain only strings; index %d is invalid.", $slug, $index));
            }

            if (mb_trim($label) === '') {
                throw new ContentException(sprintf("Metadata tag label for '%s' must be non-empty at index %d.", $slug, $index));
            }

            if (mb_strlen($label) > $this->limits->tagCharacters) {
                throw new ContentException(sprintf("Metadata tag label for '%s' exceeds the %d-character limit at index %d.", $slug, $this->limits->tagCharacters, $index));
            }

            if ($label !== mb_trim($label)) {
                throw new ContentException(sprintf("Metadata tag label for '%s' must not have surrounding whitespace at index %d.", $slug, $index));
            }

            $tagSlug = Slug::from($label);
            if ($tagSlug === '') {
                throw new ContentException(sprintf("Metadata tag label for '%s' must contain a Unicode letter or number at index %d.", $slug, $index));
            }

            if (array_any($tags, static fn(Tag $tag): bool => $tag->slug === $tagSlug)) {
                throw new ContentException(sprintf("Metadata field 'tags' for '%s' contains duplicate slug '%s'.", $slug, $tagSlug));
            }

            $tags[] = new Tag($label, $tagSlug);
        }

        return $tags;
    }

    /** @param list<Article> $articles */
    private function validateTagLabels(array $articles): void
    {
        $labels = [];
        foreach ($articles as $article) {
            foreach ($article->tags as $tag) {
                if (isset($labels[$tag->slug]) && $labels[$tag->slug] !== $tag->label) {
                    throw new ContentException(sprintf("Tag slug '%s' uses inconsistent labels '%s' and '%s'.", $tag->slug, $labels[$tag->slug], $tag->label));
                }

                $labels[$tag->slug] = $tag->label;
            }
        }
    }
}
