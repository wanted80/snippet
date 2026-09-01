<?php

declare(strict_types=1);

use Snippet\Support\Utf8FileValidator;
use Snippet\Tests\PublisherFaults;

mutates(Utf8FileValidator::class);

it('validates UTF-8 incrementally across fixed-size chunk boundaries', function (): void {
    $path = $this->directory . '/text.css';
    file_put_contents($path, str_repeat('a', 8_191) . "é日本語\xF1\x80\x80\x80\n");

    expect(new Utf8FileValidator()->isValid($path))->toBeTrue()
        ->and(PublisherFaults::calls('support_fclose'))->toBe(1);
});

it('classifies every UTF-8 lead and continuation boundary exactly', function (string $bytes, bool $valid): void {
    $path = $this->directory . '/boundary.txt';
    file_put_contents($path, $bytes);

    expect(new Utf8FileValidator()->isValid($path))->toBe($valid);
})->with([
    'empty' => ['', true],
    'lowest ASCII' => ["\x00", true],
    'highest ASCII' => ["\x7F", true],
    'lowest stray continuation' => ["\x80", false],
    'highest stray continuation' => ["\xBF", false],
    'below two-byte lead range' => ["\xC1\xBF", false],
    'lowest two-byte sequence' => ["\xC2\x80", true],
    'highest two-byte sequence' => ["\xDF\xBF", true],
    'two-byte continuation below range' => ["\xC2\x7F", false],
    'two-byte continuation above range' => ["\xC2\xC0", false],
    'lowest three-byte sequence' => ["\xE0\xA0\x80", true],
    'highest E0 sequence' => ["\xE0\xBF\xBF", true],
    'overlong E0 boundary' => ["\xE0\x9F\x80", false],
    'final continuation below reset range' => ["\xE0\xA0\x7F", false],
    'final continuation above reset range' => ["\xE0\xA0\xC0", false],
    'lowest E1 sequence' => ["\xE1\x80\x80", true],
    'highest EC sequence' => ["\xEC\xBF\xBF", true],
    'lowest ED sequence' => ["\xED\x80\x80", true],
    'highest non-surrogate sequence' => ["\xED\x9F\xBF", true],
    'lowest surrogate sequence' => ["\xED\xA0\x80", false],
    'lowest EE sequence' => ["\xEE\x80\x80", true],
    'highest three-byte sequence' => ["\xEF\xBF\xBF", true],
    'lowest four-byte sequence' => ["\xF0\x90\x80\x80", true],
    'highest F0 sequence' => ["\xF0\xBF\xBF\xBF", true],
    'overlong F0 boundary' => ["\xF0\x8F\x80\x80", false],
    'lowest F1 sequence' => ["\xF1\x80\x80\x80", true],
    'highest F3 sequence' => ["\xF3\xBF\xBF\xBF", true],
    'lowest F4 sequence' => ["\xF4\x80\x80\x80", true],
    'highest Unicode sequence' => ["\xF4\x8F\xBF\xBF", true],
    'above Unicode range boundary' => ["\xF4\x90\x80\x80", false],
    'above four-byte lead range' => ["\xF5\x80\x80\x80", false],
]);

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
