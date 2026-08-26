<?php

declare(strict_types=1);

namespace Snippet\Content;

/** Common behavior exposed by every validated content item. */
interface ContentItem
{
    /** Return the discriminator that selected the item's metadata contract. */
    public function type(): ContentType;

    /** Return the canonical, root-relative URL reserved for the item. */
    public function url(): string;
}
