<?php

declare(strict_types=1);

namespace Snippet\Rendering;

/** Browser-facing paths for the fingerprinted CSS and JavaScript entry assets. */
final readonly class AssetPaths
{
    public function __construct(
        public string $themeStylesheet,
        public string $themeScript,
        public ?string $siteStylesheet,
        public ?string $siteScript,
    ) {}
}
