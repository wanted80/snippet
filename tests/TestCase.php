<?php

declare(strict_types=1);

namespace Snippet\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Snippet\Content\Catalog;
use Snippet\Content\CatalogLoader;
use Snippet\Content\ContentType;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function scandir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

abstract class TestCase extends BaseTestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $temporary = tempnam(sys_get_temp_dir(), 'snippet-test-');
        self::assertNotFalse($temporary);
        unlink($temporary);
        mkdir($temporary);
        $this->directory = $temporary;
        PublisherFaults::reset();
        $this->site();
        copy(dirname(__DIR__) . '/site/favicon.svg', $this->directory . '/site/favicon.svg');
    }

    protected function tearDown(): void
    {
        PublisherFaults::reset();
        $this->remove($this->directory);
        parent::tearDown();
    }

    /** @param array<string, mixed> $metadata */
    protected function item(string $slug, array $metadata, string $markdown = 'Article.', ContentType $type = ContentType::Page): string
    {
        $this->content();
        $isArticle = $type === ContentType::Article;
        $date = $metadata['date'] ?? null;
        $validDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        $directoryDate = $validDate !== false && $validDate->format('Y-m-d') === $date ? $date : '2026-01-01';
        $path = $isArticle
            ? $this->directory . '/content/articles/' . str_replace('-', '/', $directoryDate) . '/' . $slug
            : $this->directory . '/content/pages/' . $slug;
        mkdir($path, 0777, true);
        file_put_contents($path . '/' . ($isArticle ? 'article.md' : 'page.md'), $markdown);
        file_put_contents($path . '/meta.php', "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($metadata, true) . ";\n");
        return $path;
    }

    /** @param array<string, mixed> $metadata */
    protected function article(string $slug, array $metadata, string $markdown = 'Article.'): string
    {
        return $this->item($slug, $metadata, $markdown, ContentType::Article);
    }

    protected function content(): void
    {
        if (!is_dir($this->directory . '/content/pages')) {
            mkdir($this->directory . '/content/pages', 0777, true);
        }
        if (!is_dir($this->directory . '/content/articles')) {
            mkdir($this->directory . '/content/articles', 0777, true);
        }
    }

    protected function catalog(): Catalog
    {
        return new CatalogLoader()->load($this->directory . '/content');
    }

    protected function image(string $path, string $format = 'webp'): void
    {
        $images = [
            'jpeg' => '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9k=',
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==',
            'png-wide' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAQAAABeK7cBAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'png-tall' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAACCAQAAABeK7cBAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ];
        $bytes = base64_decode($images[$format], true);
        self::assertIsString($bytes);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $bytes);
    }

    /** @param array<string, mixed> $overrides */
    protected function site(array $overrides = []): void
    {
        $site = [
            'title' => 'Test Site',
            'sitename' => 'Test Site',
            'author' => 'Test Author',
            'description' => 'A test site.',
            'url' => 'https://example.test',
            'language' => 'en',
            'home' => [
                'articles' => 10,
                'tags' => 20,
            ],
            'build' => [
                'minify' => false,
            ],
            ...$overrides,
        ];
        if (!is_dir($this->directory . '/site')) {
            mkdir($this->directory . '/site', 0777, true);
        }

        file_put_contents($this->directory . '/site/config.php', "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($site, true) . ";\n");
    }

    protected function resources(): void
    {
        $source = dirname(__DIR__) . '/resources';
        mkdir($this->directory . '/resources', 0777, true);
        copy($source . '/site.css', $this->directory . '/resources/site.css');
        copy($source . '/theme.js', $this->directory . '/resources/theme.js');
        copy($source . '/preview-router.php', $this->directory . '/resources/preview-router.php');
        mkdir($this->directory . '/resources/templates');
        $templates = scandir($source . '/templates');
        self::assertNotFalse($templates);
        foreach (array_diff($templates, ['.', '..']) as $template) {
            copy($source . '/templates/' . $template, $this->directory . '/resources/templates/' . $template);
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }

            return;
        }

        $entries = scandir($path);
        self::assertNotFalse($entries);
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
