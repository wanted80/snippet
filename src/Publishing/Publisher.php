<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Content\Article;
use Snippet\Content\Page;
use Snippet\Exception\ContentException;
use Snippet\Rendering\HtmlRenderer;
use Throwable;

/** Builds a complete temporary tree and transactionally promotes it to public/. */
final readonly class Publisher
{
    private const int FILE_PERMISSIONS = 0644;

    private const int DIRECTORY_PERMISSIONS = 0755;

    /**
     * Publish already validated inputs without reloading their retained resources.
     * Backup-cleanup failures are reported after a successful promotion.
     * Failed-build cleanup diagnostics retain the original publication failure as their cause.
     *
     * @throws ContentException when rendering, copying, or publication fails
     */
    public function publish(
        string $root,
        PublicationInputs $inputs,
        ?string $previewVersion = null,
    ): BuildReport {
        $public = $root . '/public';
        if (is_link($public) || (file_exists($public) && !is_dir($public))) {
            throw new ContentException("Publication target '{$public}' must be a regular directory or absent.");
        }

        $budget = new BuildBudget($inputs->limits);
        // Neighbouring random-byte lengths preserve the unique sibling-path contract.
        $suffix = bin2hex(random_bytes(8)); // @pest-mutate-ignore: DecrementInteger,IncrementInteger
        $temporary = $root . '/.snippet-build-' . $suffix;
        $backup = $root . '/.snippet-backup-' . $suffix;

        try {
            $this->buildTree($root, $temporary, $inputs, $budget, $previewVersion);
            $cleanupWarning = $this->promote($temporary, $public, $backup);
        } catch (Throwable $throwable) {
            $failure = $throwable instanceof ContentException
                ? $throwable
                : new ContentException('Unable to publish site: ' . $throwable->getMessage(), 0, $throwable);
            try {
                $this->removeIfPresent($temporary);
            } catch (Throwable $cleanupFailure) {
                throw new ContentException(
                    $failure->getMessage() . " Temporary publication cleanup failed for '{$temporary}': " . $cleanupFailure->getMessage(),
                    0,
                    $failure,
                );
            }

            throw $failure;
        }

        return $budget->report($inputs->catalog, $cleanupWarning);
    }

    private function buildTree(
        string $root,
        string $output,
        PublicationInputs $inputs,
        BuildBudget $budget,
        ?string $previewVersion,
    ): void {
        $renderer = new HtmlRenderer($inputs->config, $inputs->catalog, $inputs->templates, $inputs->assets->paths);
        $this->writeHtml($output . '/index.html', $renderer->home(), $budget);
        $this->writeHtml($output . '/404.html', $renderer->notFound(), $budget);

        $this->writeHtml($output . "/pages/index.html", $renderer->pages(), $budget);
        $this->writeHtml($output . "/articles/index.html", $renderer->articles(), $budget);
        $this->writeHtml($output . "/tags/index.html", $renderer->tags(), $budget);

        foreach ($inputs->catalog->articles as $article) {
            $this->publishItem($root, $output, $renderer, $article, $budget);
        }

        foreach ($inputs->catalog->pages as $page) {
            $this->publishItem($root, $output, $renderer, $page, $budget);
        }

        foreach ($inputs->catalog->tags() as $tag) {
            $this->writeHtml($output . '/tags/' . $tag->slug . '/index.html', $renderer->tag($tag), $budget);
        }

        $this->writeLlms($output . '/llms.txt', new LlmsTxtRenderer($inputs->config, $inputs->catalog), $budget);

        if ($previewVersion !== null) {
            $contents = $previewVersion . "\n";
            $path = $output . '/.snippet-preview-version';
            $budget->addAsset(mb_strlen($contents, '8bit'), $path);
            $this->writeFile($path, $contents);
        }

        foreach ($inputs->assets->all() as $asset) {
            $this->writeAsset($output . $asset->publishedPath, $asset, $budget);
        }
        $this->copy($root . '/site/favicon.svg', $output . '/favicon.svg', $budget);

        foreach ($inputs->config->assets as $asset) {
            $this->copy($root . '/site/assets/' . $asset, $output . '/assets/site/' . $asset, $budget);
        }
    }

    private function publishItem(
        string $root,
        string $output,
        HtmlRenderer $renderer,
        Article|Page $item,
        BuildBudget $budget,
    ): void {
        $directory = $output . $item->url();
        $type = $item->type();
        $sourceDirectory = $root . '/content/' . $type->collection();
        if ($item instanceof Article) {
            $sourceDirectory .= '/' . str_replace('-', '/', $item->date);
        }
        $sourceDirectory .= '/' . $item->slug;
        $this->writeHtml($directory . 'index.html', $renderer->content($item), $budget);
        foreach ($item->assets as $asset) {
            $this->copy($sourceDirectory . '/' . $asset->path, $directory . $asset->path, $budget);
        }
    }

    private function promote(string $temporary, string $public, string $backup): ?string
    {
        $hadPublic = is_dir($public);
        if ($hadPublic && !@rename($public, $backup)) {
            throw new ContentException("Unable to move existing publication '{$public}' to a backup.");
        }

        if (@rename($temporary, $public)) {
            if ($hadPublic) {
                try {
                    $this->remove($backup);
                } catch (Throwable $throwable) {
                    return "The new site was published. Remove the remaining backup '{$backup}' manually. " . $throwable->getMessage();
                }
            }

            return null;
        }

        if ($hadPublic && !@rename($backup, $public)) {
            throw new ContentException("Unable to promote the new site and unable to restore existing publication '{$public}'. Backup remains at '{$backup}'.");
        }

        throw new ContentException("Unable to promote the new site to '{$public}'; the existing publication was preserved.");
    }

    private function writeHtml(string $path, string $contents, BuildBudget $budget): void
    {
        // Distinct Unicode routes can name the same file on the destination filesystem.
        if (file_exists($path)) {
            throw new ContentException("Generated output path collides with an existing file: '{$path}'. Check content and tag slugs.");
        }

        $budget->addPage($contents, $path);
        $this->writeFile($path, $contents);
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->directory(dirname($path));
        $written = @file_put_contents($path, $contents);
        if ($written !== mb_strlen($contents, '8bit') || !@chmod($path, self::FILE_PERMISSIONS)) {
            throw new ContentException("Unable to write generated file '{$path}'.");
        }
    }

    private function writeAsset(string $path, PublicationAsset $asset, BuildBudget $budget): void
    {
        $budget->addAsset($asset->bytes(), $path);
        $this->writeFile($path, $asset->contents);
    }

    private function writeLlms(string $path, LlmsTxtRenderer $renderer, BuildBudget $budget): void
    {
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

        if (!@chmod($path, self::FILE_PERMISSIONS)) {
            throw new ContentException("Unable to write generated file '{$path}'.");
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
        if (!@copy($source, $destination) || !@chmod($destination, self::FILE_PERMISSIONS)) {
            throw new ContentException("Unable to copy '{$source}' to '{$destination}'.");
        }
    }

    private function directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        $this->directory(dirname($path));
        if (!@mkdir($path, self::DIRECTORY_PERMISSIONS) || !@chmod($path, self::DIRECTORY_PERMISSIONS)) {
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

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->remove($path . '/' . $entry);
        }

        if (!@rmdir($path)) {
            throw new ContentException("Unable to remove temporary directory '{$path}'.");
        }
    }
}
