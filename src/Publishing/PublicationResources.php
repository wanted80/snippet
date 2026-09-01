<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Rendering\Templates;

/** Validated templates and retained entry assets for one publication snapshot. */
final readonly class PublicationResources
{
    public function __construct(
        public Templates $templates,
        public PublicationAssets $assets,
    ) {}
}
