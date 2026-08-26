<?php

declare(strict_types=1);

namespace Snippet\Site;

/** Validated resource ceilings for one deterministic build. */
final readonly class Limits
{
    public function __construct(
        public int $articles = 1_000,
        public int $pages = 50,
        public int $menuPages = 4,
        public int $tagsPerArticle = 8,
        public int $tagCharacters = 48,
        public int $titleCharacters = 120,
        public int $descriptionCharacters = 320,
        public int $imageDimension = 32_768,
        public int $metadataBytes = 16_384,
        public int $markdownBytes = 262_144,
        public int $catalogMarkdownBytes = 33_554_432,
        public int $markdownDepth = 16,
        public int $documentNodes = 10_000,
        public int $catalogNodes = 200_000,
        public int $assetsPerItem = 128,
        public int $catalogAssets = 5_000,
        public int $assetDepth = 12,
        public int $assetBytes = 26_214_400,
        public int $catalogAssetBytes = 536_870_912,
        public int $templateBytes = 131_072,
        public int $allTemplateBytes = 1_048_576,
        public int $renderedPageBytes = 5_242_880,
        public int $buildBytes = 1_073_741_824,
    ) {}
}
