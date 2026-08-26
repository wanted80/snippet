<?php

declare(strict_types=1);

namespace Snippet\Content;

use NoDiscard;
use Snippet\Exception\ContentException;
use Snippet\Support\TrustedPhpLoader;

/**
 * Loads a trusted metadata PHP file while enforcing its boundary contract.
 *
 * The delegated token parser accepts only a strict-types declaration followed
 * by one returned literal array, so metadata is read without executing PHP.
 */
final readonly class MetadataLoader
{
    public function __construct(private TrustedPhpLoader $phpLoader = new TrustedPhpLoader()) {}

    /**
     * Parse metadata for one content item and return its raw field map.
     *
     * Type-specific fields and values are deliberately validated by
     * {@see CatalogLoader}; this method owns only safe loading and the outer shape.
     *
     * @return array<string, mixed>
     *
     * @throws ContentException when the file cannot be read or violates the loading contract
     */
    #[NoDiscard('the loaded metadata should be validated and consumed')]
    public function load(string $path, string $slug, int $maximumBytes = 16_384): array
    {
        return $this->phpLoader->load($path, "metadata for '{$slug}'", $maximumBytes);
    }
}
