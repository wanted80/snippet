<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use NoDiscard;
use Snippet\Content\CatalogLoader;
use Snippet\Exception\ContentException;
use Snippet\Site\ConfigLoader;
use Snippet\Site\Limits;

/** Loads and cross-validates the complete publication input boundary once. */
final readonly class PublicationInputLoader
{
    public function __construct(
        private Limits $limits = new Limits(),
        private ConfigLoader $configLoader = new ConfigLoader(),
        private CatalogLoader $catalogLoader = new CatalogLoader(),
        private Publisher $publisher = new Publisher(),
        private ReferenceValidator $referenceValidator = new ReferenceValidator(),
    ) {}

    /** @throws ContentException when any publication input is invalid or unreadable */
    #[NoDiscard('the validated publication inputs should be consumed')]
    public function load(string $root): PublicationInputs
    {
        $config = $this->configLoader->load($root . '/site', $this->limits);
        $catalog = $this->catalogLoader->load($root . '/content', $this->limits);
        $resources = $this->publisher->validatedResources($root, $config, $this->limits);
        $inventory = new PublicationInventory($config, $catalog, $resources->assets->paths);
        $this->referenceValidator->validate($root, $config, $catalog, $inventory);

        return new PublicationInputs($this->limits, $config, $catalog, $resources->templates, $resources->assets);
    }
}
