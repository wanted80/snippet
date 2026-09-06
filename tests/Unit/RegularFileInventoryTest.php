<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Support\RegularFileInventory;
use Snippet\Tests\PublisherFaults;

mutates(RegularFileInventory::class);

it('retains deterministic file order at the exact file and depth ceilings', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root . '/nested', 0777, true);
    file_put_contents($root . '/z.txt', 'last');
    file_put_contents($root . '/nested/b.txt', 'nested');
    file_put_contents($root . '/a.txt', 'first');

    expect(new RegularFileInventory()->files($root, 'test assets', 3, 2))
        ->toBe(['a.txt', 'nested/b.txt', 'z.txt']);
});

it('rejects another file before opening later asset directories', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root . '/z-later', 0777, true);
    foreach (['a', 'b', 'c'] as $name) {
        file_put_contents($root . '/' . $name, 'asset');
    }

    expect(fn(): array => new RegularFileInventory()->files($root, 'test assets', 2, 3))
        ->toThrow(ContentException::class, "Test assets exceeds the 2-file limit while adding 'c'.")
        ->and(PublisherFaults::calls('support_opendir'))->toBe(1)
        ->and(PublisherFaults::calls('support_closedir'))->toBe(1);
});

it('rejects a deeper empty directory before opening it', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root . '/one/two/three', 0777, true);

    expect(fn(): array => new RegularFileInventory()->files($root, 'test assets', 10, 2))
        ->toThrow(ContentException::class, "Test assets entry 'one/two/three' exceeds directory depth 2.")
        ->and(PublisherFaults::calls('support_opendir'))->toBe(3)
        ->and(PublisherFaults::calls('support_closedir'))->toBe(3);
});

it('bounds directory entries across the complete tree including empty directories', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root . '/a/child', 0777, true);
    mkdir($root . '/b/child', 0777, true);

    expect(fn(): array => new RegularFileInventory()->files($root, 'test assets', 1, 3))
        ->toThrow(ContentException::class, "Test assets exceeds the 3-entry traversal limit in '{$root}/b'.")
        ->and(PublisherFaults::calls('support_opendir'))->toBe(4)
        ->and(PublisherFaults::calls('support_closedir'))->toBe(4);
});

it('accepts the exact entry ceiling and counts empty directories toward it', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root . '/one/two/three', 0777, true);

    expect(new RegularFileInventory()->files($root, 'test assets', 1, 3))->toBeEmpty();
});

it('stops reading oversized directories at the entry ceiling and closes the stream', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root);
    foreach (['a', 'b', 'c'] as $name) {
        mkdir($root . '/' . $name);
    }

    expect(fn(): array => new RegularFileInventory()->files($root, 'test assets', 1, 2))
        ->toThrow(ContentException::class, "Test assets exceeds the 2-entry traversal limit in '{$root}'.")
        ->and(PublisherFaults::calls('support_closedir'))->toBe(1);
});

it('resets traversal budgets when one inventory instance is reused', function (): void {
    $root = $this->directory . '/assets';
    mkdir($root);
    file_put_contents($root . '/one', 'asset');
    $inventory = new RegularFileInventory();

    expect($inventory->files($root, 'test assets', 1, 1))->toBe(['one'])
        ->and($inventory->files($root, 'test assets', 1, 1))->toBe(['one']);
});
