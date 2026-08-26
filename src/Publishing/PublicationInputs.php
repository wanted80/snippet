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
}
