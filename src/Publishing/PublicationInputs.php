<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Content\Catalog;
use Snippet\Rendering\Templates;
use Snippet\Site\Config;
use Snippet\Site\Limits;

/** Every validated, read-only input required to publish one site snapshot. */
final readonly class PublicationInputs
{
    public function __construct(
        public Limits $limits,
        public Config $config,
        public Catalog $catalog,
        public Templates $templates,
    ) {}

    /** Count every validated non-document file that publication will copy or generate. */
    public function assetCount(): int
    {
        $assets = 3 + count($this->config->assets);
        $assets += $this->config->hasSiteStylesheet ? 1 : 0;
        $assets += $this->config->hasSiteScript ? 1 : 0;
        foreach ([$this->catalog->articles, $this->catalog->pages] as $items) {
            foreach ($items as $item) {
                $assets += count($item->assets);
            }
        }

        return $assets;
    }
}
