<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Exception\ContentException;
use Snippet\Site\Limits;

/** Enforces deterministic output-size ceilings during publication. */
final class BuildBudget
{
    private int $bytes = 0;

    /** @var array<string, int> */
    private array $pageBytes = [];

    public function __construct(private readonly Limits $limits) {}

    public function addPage(string $contents, string $path): void
    {
        $bytes = mb_strlen($contents, '8bit');
        if ($bytes > $this->limits->renderedPageBytes) {
            throw new ContentException("Generated page '{$path}' exceeds the {$this->limits->renderedPageBytes}-byte rendered page limit.");
        }

        $this->add($bytes, $path);
    }

    /** Count one streamed chunk toward its generated-page and total-build ceilings. */
    public function addPageChunk(int $bytes, string $path): void
    {
        $pageBytes = ($this->pageBytes[$path] ?? 0) + $bytes;
        if ($pageBytes > $this->limits->renderedPageBytes) {
            throw new ContentException("Generated page '{$path}' exceeds the {$this->limits->renderedPageBytes}-byte rendered page limit.");
        }

        $this->pageBytes[$path] = $pageBytes;
        $this->add($bytes, $path);
    }

    public function addAsset(int $bytes, string $path): void
    {
        if ($bytes > $this->limits->assetBytes) {
            throw new ContentException("Publication asset '{$path}' exceeds the {$this->limits->assetBytes}-byte asset limit.");
        }

        $this->add($bytes, $path);
    }

    private function add(int $bytes, string $path): void
    {
        $this->bytes += $bytes;
        if ($this->bytes > $this->limits->buildBytes) {
            throw new ContentException("Publication exceeds the {$this->limits->buildBytes}-byte total build limit while adding '{$path}'.");
        }
    }
}
