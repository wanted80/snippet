<?php

declare(strict_types=1);

namespace Snippet\Content;

use Snippet\Exception\ContentException;
use Snippet\Markdown\Document;
use Snippet\Site\Limits;

/** Tracks aggregate retained catalog resources while content is loaded. */
final class CatalogBudget
{
    private int $assetBytes = 0;

    private int $assets = 0;

    private int $markdownBytes = 0;

    private int $nodes = 0;

    public function __construct(private readonly Limits $limits) {}

    public function addMarkdown(int $bytes): void
    {
        $this->markdownBytes += $bytes;
        if ($this->markdownBytes > $this->limits->catalogMarkdownBytes) {
            throw new ContentException(sprintf('Content exceeds the %d-byte catalog Markdown limit.', $this->limits->catalogMarkdownBytes));
        }
    }

    public function addDocument(Document $document, string $slug): void
    {
        $documentNodes = $document->nodeCount();
        if ($documentNodes > $this->limits->documentNodes) {
            throw new ContentException(sprintf("Markdown for '%s' exceeds the %d-node document limit.", $slug, $this->limits->documentNodes));
        }

        $this->nodes += $documentNodes;
        if ($this->nodes > $this->limits->catalogNodes) {
            throw new ContentException(sprintf('Content exceeds the %d-node catalog limit.', $this->limits->catalogNodes));
        }
    }

    public function addAsset(int $bytes): void
    {
        ++$this->assets;
        if ($this->assets > $this->limits->catalogAssets) {
            throw new ContentException(sprintf('Content exceeds the %d-asset catalog limit.', $this->limits->catalogAssets));
        }

        $this->assetBytes += $bytes;
        if ($this->assetBytes > $this->limits->catalogAssetBytes) {
            throw new ContentException(sprintf('Content exceeds the %d-byte catalog asset limit.', $this->limits->catalogAssetBytes));
        }
    }
}
