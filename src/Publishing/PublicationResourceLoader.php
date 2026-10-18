<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use NoDiscard;
use Snippet\Exception\ContentException;
use Snippet\Rendering\TemplateLoader;
use Snippet\Site\Config;
use Snippet\Site\Limits;
use Snippet\Support\Utf8FileValidator;

/** Validates and retains installed-engine and workspace resources before publication. */
final readonly class PublicationResourceLoader
{
    /** @param string $engineRoot Trusted installation directory, independent of the author workspace. */
    public function __construct(
        private TemplateLoader $templateLoader = new TemplateLoader(),
        private CssMinifier $cssMinifier = new CssMinifier(),
        private Utf8FileValidator $utf8FileValidator = new Utf8FileValidator(),
        private string $engineRoot = __DIR__ . '/../..',
    ) {}

    /**
     * Validate and retain every non-content resource required for one publication snapshot.
     *
     * @throws ContentException when a required template or asset is invalid or unreadable
     */
    #[NoDiscard('the validated resources should be reused by publication')]
    public function load(string $root, Config $config, Limits $limits): PublicationResources
    {
        $retainedAssetBytes = 0;
        $templates = $this->templateLoader->load($this->engineRoot . '/resources/templates', $limits);
        $themeStylesheet = $this->stylesheet($this->engineRoot . '/resources/theme.css', '/assets/theme.css', $limits, $retainedAssetBytes);
        $themeScript = $this->script($this->engineRoot . '/resources/theme.js', '/assets/theme.js', $limits, $retainedAssetBytes);
        $this->validateAsset($root . '/site/favicon.svg', $limits, true);

        $siteStylesheet = $config->hasSiteStylesheet
            ? $this->stylesheet($root . '/site/site.css', '/assets/site.css', $limits, $retainedAssetBytes)
            : null;
        $siteScript = $config->hasSiteScript
            ? $this->script($root . '/site/site.js', '/assets/site.js', $limits, $retainedAssetBytes)
            : null;

        foreach ($config->assets as $asset) {
            $this->validateAsset($root . '/site/assets/' . $asset, $limits, false);
        }

        return new PublicationResources(
            $templates,
            new PublicationAssets($themeStylesheet, $themeScript, $siteStylesheet, $siteScript),
        );
    }

    private function stylesheet(string $source, string $logicalPath, Limits $limits, int &$retainedAssetBytes): PublicationAsset
    {
        $this->validateAsset($source, $limits, true);

        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new ContentException("Unable to minify publication stylesheet '{$source}'.");
        }

        $output = @fopen('php://temp', 'w+b');
        if (!is_resource($output)) {
            fclose($input);
            throw new ContentException("Unable to minify publication stylesheet '{$source}'.");
        }

        try {
            $publishedBytes = $this->cssMinifier->minify($input, $output);
            $this->retainEntryAsset($publishedBytes, $source, $limits, $retainedAssetBytes);
            if (!rewind($output)) {
                throw new ContentException("Unable to read minified publication stylesheet '{$source}'.");
            }
            $contents = stream_get_contents($output);
            if (!is_string($contents)) {
                throw new ContentException("Unable to read minified publication stylesheet '{$source}'.");
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        return new PublicationAsset($logicalPath, $contents);
    }

    private function script(string $source, string $logicalPath, Limits $limits, int &$retainedAssetBytes): PublicationAsset
    {
        $sourceBytes = $this->validateAsset($source, $limits, true);
        $contents = $this->readAsset($source, $sourceBytes, $limits, $retainedAssetBytes);

        return new PublicationAsset($logicalPath, $contents);
    }

    private function readAsset(string $source, int $bytes, Limits $limits, int &$retainedAssetBytes): string
    {
        $this->retainEntryAsset($bytes, $source, $limits, $retainedAssetBytes);

        $contents = @file_get_contents($source);
        if (!is_string($contents)) {
            throw new ContentException("Unable to read publication asset '{$source}'.");
        }

        return $contents;
    }

    private function retainEntryAsset(int $bytes, string $source, Limits $limits, int &$retainedAssetBytes): void
    {
        $retainedAssetBytes += $bytes;
        if ($retainedAssetBytes > $limits->retainedEntryAssetBytes) {
            throw new ContentException("Retained entry assets exceed the {$limits->retainedEntryAssetBytes}-byte retained-entry-asset ceiling while loading '{$source}'.");
        }
    }

    private function validateAsset(string $path, Limits $limits, bool $utf8): int
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

        return $size;
    }

}
