<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use NoDiscard;
use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Exception\ContentException;
use Snippet\Rendering\HtmlRenderer;
use Snippet\Rendering\TemplateLoader;
use Snippet\Rendering\Templates;
use Snippet\Site\Config;
use Snippet\Site\Limits;
use Snippet\Support\Utf8FileValidator;
use Throwable;

/** Builds a complete temporary tree and transactionally promotes it to public/. */
final readonly class Publisher
{
    public function __construct(
        private TemplateLoader $templateLoader = new TemplateLoader(),
        private HtmlMinifier $htmlMinifier = new HtmlMinifier(),
        private CssMinifier $cssMinifier = new CssMinifier(),
        private Utf8FileValidator $utf8FileValidator = new Utf8FileValidator(),
    ) {}

    /** Validate and load every non-content file required for publication. */
    #[NoDiscard('the validated templates should be reused by publication')]
    public function validate(string $root, Config $config, ?Limits $limits = null): Templates
    {
        $limits ??= new Limits();
        $templates = $this->templateLoader->load($root . '/resources/templates', $limits);
        $this->validateAsset($root . '/resources/site.css', $limits, true);
        $this->validateAsset($root . '/resources/theme.js', $limits, true);
        $this->validateAsset($root . '/site/favicon.svg', $limits, true);

        if ($config->hasTheme) {
            $this->validateAsset($root . '/site/theme.css', $limits, true);
        }

        foreach ($config->assets as $asset) {
            $this->validateAsset($root . '/site/assets/' . $asset, $limits, false);
        }

        return $templates;
    }

    /** @throws ContentException when rendering, copying, or publication fails */
    public function publish(
        string $root,
        Config $config,
        Catalog $catalog,
        ?Limits $limits = null,
        ?Templates $templates = null,
        ?string $previewVersion = null,
    ): void {
        $public = $root . '/public';
        if (is_link($public) || (file_exists($public) && !is_dir($public))) {
            throw new ContentException("Publication target '{$public}' must be a regular directory or absent.");
        }

        $limits ??= new Limits();
        $templates ??= $this->validate($root, $config, $limits);
        $budget = new BuildBudget($limits);
        $suffix = bin2hex(random_bytes(8));
        $temporary = $root . '/.snippet-build-' . $suffix;
        $backup = $root . '/.snippet-backup-' . $suffix;

        try {
            $this->directory($temporary);
            $this->buildTree($root, $temporary, $config, $catalog, $templates, $budget, $previewVersion);
            $this->promote($temporary, $public, $backup);
        } catch (ContentException $contentException) {
            $this->removeIfPresent($temporary);
            throw $contentException;
        } catch (Throwable $throwable) {
            $this->removeIfPresent($temporary);
            throw new ContentException('Unable to publish site: ' . $throwable->getMessage(), 0, $throwable);
        }
    }

    private function buildTree(
        string $root,
        string $output,
        Config $config,
        Catalog $catalog,
        Templates $templates,
        BuildBudget $budget,
        ?string $previewVersion,
    ): void {
        $renderer = new HtmlRenderer($config, $catalog, $templates);
        $this->writeHtml($output . '/index.html', $renderer->home(), $config->minify, $budget);

        $this->writeHtml($output . "/pages/index.html", $renderer->pages(), $config->minify, $budget);
        $this->writeHtml($output . "/articles/index.html", $renderer->articles(), $config->minify, $budget);
        $this->writeHtml($output . "/tags/index.html", $renderer->tags(), $config->minify, $budget);

        foreach ($catalog->articles as $article) {
            $this->publishItem($root, $output, $renderer, $config, $article, $budget);
        }

        foreach ($catalog->pages as $page) {
            $this->publishItem($root, $output, $renderer, $config, $page, $budget);
        }

        foreach ($catalog->tags() as $tag) {
            $this->writeHtml($output . '/tags/' . $tag->slug . '/index.html', $renderer->tag($tag), $config->minify, $budget);
        }

        $this->writeLlms($output . '/llms.txt', new LlmsTxtRenderer($config, $catalog), $budget);

        if ($previewVersion !== null) {
            $contents = $previewVersion . "\n";
            $path = $output . '/.snippet-preview-version';
            $budget->addAsset(mb_strlen($contents, '8bit'), $path);
            $this->writeFile($path, $contents);
        }

        $this->publishCss($root . '/resources/site.css', $output . '/assets/site.css', $config->minify, $budget);
        $this->copy($root . '/resources/theme.js', $output . '/assets/theme.js', $budget);
        $this->copy($root . '/site/favicon.svg', $output . '/favicon.svg', $budget);

        if ($config->hasTheme) {
            $this->publishCss($root . '/site/theme.css', $output . '/assets/theme.css', $config->minify, $budget);
        }

        foreach ($config->assets as $asset) {
            $this->copy($root . '/site/assets/' . $asset, $output . '/assets/site/' . $asset, $budget);
        }
    }

    private function publishItem(
        string $root,
        string $output,
        HtmlRenderer $renderer,
        Config $config,
        Article|Page $item,
        BuildBudget $budget,
    ): void {
        $routePrefix = $item instanceof Article ? '/articles/' : '/';
        $directory = $output . $routePrefix . $item->slug . '/';
        $sourceDirectory = $item instanceof Article
            ? $root . '/content/articles/' . str_replace('-', '/', $item->date) . '/' . $item->slug
            : $root . '/content/pages/' . $item->slug;
        $this->writeHtml($directory . 'index.html', $renderer->content($item), $config->minify, $budget);
        foreach ($item->assets as $asset) {
            $this->copy($sourceDirectory . '/' . $asset->path, $directory . $asset->path, $budget);
        }
    }

    private function promote(string $temporary, string $public, string $backup): void
    {
        $hadPublic = is_dir($public);
        if ($hadPublic && !@rename($public, $backup)) {
            throw new ContentException("Unable to move existing publication '{$public}' to a backup.");
        }

        if (@rename($temporary, $public)) {
            if ($hadPublic) {
                $this->remove($backup);
            }

            return;
        }

        if ($hadPublic && !@rename($backup, $public)) {
            throw new ContentException("Unable to promote the new site and unable to restore existing publication '{$public}'. Backup remains at '{$backup}'.");
        }

        throw new ContentException("Unable to promote the new site to '{$public}'; the existing publication was preserved.");
    }

    private function writeHtml(string $path, string $contents, bool $minify, BuildBudget $budget): void
    {
        $contents = $minify ? $this->htmlMinifier->minify($contents) : $contents;
        $budget->addPage($contents, $path);
        $this->writeFile($path, $contents);
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->directory(dirname($path));
        $written = @file_put_contents($path, $contents);
        if ($written !== mb_strlen($contents, '8bit') || !@chmod($path, 0644)) {
            throw new ContentException("Unable to write generated file '{$path}'.");
        }
    }

    private function writeLlms(string $path, LlmsTxtRenderer $renderer, BuildBudget $budget): void
    {
        $this->directory(dirname($path));
        $stream = @fopen($path, 'wb');
        if (!is_resource($stream)) {
            throw new ContentException("Unable to write generated file '{$path}'.");
        }

        try {
            foreach ($renderer->render() as $chunk) {
                $budget->addPageChunk(mb_strlen($chunk, '8bit'), $path);
                $this->writeStream($stream, $chunk, $path);
            }
        } finally {
            fclose($stream);
        }

        if (!@chmod($path, 0644)) {
            throw new ContentException("Unable to write generated file '{$path}'.");
        }
    }

    private function publishCss(string $source, string $destination, bool $minify, BuildBudget $budget): void
    {
        if (!$minify) {
            $this->copy($source, $destination, $budget);
            return;
        }

        if (!is_file($source) || is_link($source)) {
            throw new ContentException("Unable to minify '{$source}' to '{$destination}'.");
        }

        $this->directory(dirname($destination));
        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new ContentException("Unable to minify '{$source}' to '{$destination}'.");
        }

        $output = @fopen($destination, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            throw new ContentException("Unable to minify '{$source}' to '{$destination}'.");
        }

        try {
            $bytes = $this->cssMinifier->minify($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }

        $budget->addAsset($bytes, $destination);
        if (!@chmod($destination, 0644)) {
            throw new ContentException("Unable to minify '{$source}' to '{$destination}'.");
        }
    }

    /** @param resource $stream */
    private function writeStream(mixed $stream, string $contents, string $path): void
    {
        $offset = 0;
        $bytes = mb_strlen($contents, '8bit');
        while ($offset < $bytes) {
            $written = @fwrite($stream, mb_substr($contents, $offset, null, '8bit'));
            if (!is_int($written) || $written < 1) {
                throw new ContentException("Unable to write generated file '{$path}'.");
            }

            $offset += $written;
        }
    }

    private function copy(string $source, string $destination, BuildBudget $budget): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new ContentException("Unable to copy '{$source}' to '{$destination}'.");
        }

        $size = @filesize($source);
        if (!is_int($size)) {
            throw new ContentException("Unable to read publication asset size for '{$source}'.");
        }

        $budget->addAsset($size, $destination);
        $this->directory(dirname($destination));
        if (!@copy($source, $destination) || !@chmod($destination, 0644)) {
            throw new ContentException("Unable to copy '{$source}' to '{$destination}'.");
        }
    }

    private function validateAsset(string $path, Limits $limits, bool $utf8): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new ContentException("Publication asset '{$path}' must be a regular non-symlink file.");
        }

        $size = @filesize($path);
        if (!is_int($size) || $size > $limits->assetBytes) {
            throw new ContentException("Publication asset '{$path}' exceeds the {$limits->assetBytes}-byte asset limit.");
        }

        if (!$this->utf8FileValidator->isValid($path, $utf8)) {
            throw new ContentException("Publication asset '{$path}' must be readable" . ($utf8 ? ' UTF-8 text.' : '.'));
        }
    }

    private function directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0755, true) || !@chmod($path, 0755)) {
            throw new ContentException("Unable to create output directory '{$path}'.");
        }
    }

    private function removeIfPresent(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            $this->remove($path);
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (!@unlink($path)) {
                throw new ContentException("Unable to remove temporary path '{$path}'.");
            }

            return;
        }

        $entries = @scandir($path, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            throw new ContentException("Unable to read temporary directory '{$path}'.");
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }

        if (!@rmdir($path)) {
            throw new ContentException("Unable to remove temporary directory '{$path}'.");
        }
    }
}
