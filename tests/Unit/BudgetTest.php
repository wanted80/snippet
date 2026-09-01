<?php

declare(strict_types=1);

use Snippet\Content\CatalogBudget;
use Snippet\Exception\ContentException;
use Snippet\Markdown\Parser;
use Snippet\Publishing\BuildBudget;
use Snippet\Site\Limits;

mutates(Parser::class);

it('accepts values exactly at every catalog budget ceiling', function (): void {
    $document = new Parser()->parse('A', 'exact.md');
    $limits = new Limits(
        catalogMarkdownBytes: 1,
        documentNodes: 2,
        catalogNodes: 2,
        catalogAssets: 1,
        catalogAssetBytes: 1,
    );
    $budget = new CatalogBudget($limits);

    $budget->addMarkdown(1);
    $budget->addDocument($document, 'exact');
    $budget->addAsset(1);

    expect($document->nodeCount())->toBe(2);
});

it('accepts values exactly at every publication budget ceiling', function (): void {
    $limits = new Limits(
        assetBytes: 1,
        renderedPageBytes: 1,
        buildBytes: 2,
    );
    $budget = new BuildBudget($limits);

    expect(function () use ($budget): void {
        $budget->addPage('A', '/index.html');
        $budget->addAsset(1, '/asset');
    })
        ->not->toThrow(ContentException::class);
});

it('accumulates streamed chunks against one generated-page ceiling', function (): void {
    $budget = new BuildBudget(new Limits(renderedPageBytes: 2, buildBytes: 3));

    $budget->addPageChunk(1, '/llms.txt');
    $budget->addPageChunk(1, '/llms.txt');

    expect(fn() => $budget->addPageChunk(1, '/llms.txt'))
        ->toThrow(ContentException::class, "Generated page '/llms.txt' exceeds the 2-byte rendered page limit.");
});

it('accumulates generated pages and copied assets into one build ceiling', function (): void {
    $limits = new Limits(
        assetBytes: 1,
        renderedPageBytes: 1,
        buildBytes: 1,
    );
    $budget = new BuildBudget($limits);

    $budget->addPage('A', '/index.html');

    expect(function () use ($budget): void {
        $budget->addAsset(1, '/asset');
    })
        ->toThrow(ContentException::class, '1-byte total build limit');
});

it('rejects one publication asset above its individual byte ceiling', function (): void {
    $budget = new BuildBudget(new Limits(assetBytes: 1));

    expect(fn() => $budget->addAsset(2, '/asset'))
        ->toThrow(ContentException::class, "Publication asset '/asset' exceeds the 1-byte asset limit.");
});
