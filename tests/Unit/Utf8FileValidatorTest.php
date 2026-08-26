<?php

declare(strict_types=1);

use Snippet\Support\Utf8FileValidator;
use Snippet\Tests\PublisherFaults;

mutates(Utf8FileValidator::class);

it('validates UTF-8 incrementally across fixed-size chunk boundaries', function (): void {
    $path = $this->directory . '/text.css';
    file_put_contents($path, str_repeat('a', 8_191) . "é日本語\xF1\x80\x80\x80\n");

    expect(new Utf8FileValidator()->isValid($path))->toBeTrue();
});

it('rejects invalid and truncated input', function (string $bytes): void {
    $path = $this->directory . '/invalid.css';
    file_put_contents($path, $bytes);

    expect(new Utf8FileValidator()->isValid($path))->toBeFalse();
})->with([
    'unexpected continuation' => "\x80",
    'invalid lead byte' => "\xC0\x80",
    'truncated sequence' => "\xF0\x9F\x92",
    'overlong sequence' => "\xE0\x80\x80",
    'surrogate' => "\xED\xA0\x80",
    'above Unicode range' => "\xF4\x90\x80\x80",
]);

it('streams arbitrary binary files without applying UTF-8 rules', function (): void {
    $path = $this->directory . '/asset.bin';
    file_put_contents($path, str_repeat("\xFF", 20_000));

    expect(new Utf8FileValidator()->isValid($path, false))->toBeTrue();
});

it('returns false for an unreadable path', function (): void {
    expect(new Utf8FileValidator()->isValid($this->directory . '/missing.css'))->toBeFalse();
});

it('returns false for deterministic stream failures', function (string $operation): void {
    $path = $this->directory . '/text.css';
    file_put_contents($path, 'valid');
    PublisherFaults::set($operation, ['fail']);

    expect(new Utf8FileValidator()->isValid($path))->toBeFalse();
})->with(['support_fopen', 'support_fread']);
