<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Rendering\AssetPaths;

/** The immutable entry-asset snapshot shared by validation, rendering, and publication. */
final readonly class PublicationAssets
{
    public AssetPaths $paths;

    public function __construct(
        public PublicationAsset $themeStylesheet,
        public PublicationAsset $themeScript,
        public ?PublicationAsset $siteStylesheet,
        public ?PublicationAsset $siteScript,
    ) {
        $this->paths = new AssetPaths(
            $themeStylesheet->publishedPath,
            $themeScript->publishedPath,
            $siteStylesheet?->publishedPath,
            $siteScript?->publishedPath,
        );
    }

    /** @return list<PublicationAsset> */
    public function all(): array
    {
        $assets = [$this->themeStylesheet, $this->themeScript];
        if ($this->siteStylesheet instanceof PublicationAsset) {
            $assets[] = $this->siteStylesheet;
        }
        if ($this->siteScript instanceof PublicationAsset) {
            $assets[] = $this->siteScript;
        }

        return $assets;
    }
}
