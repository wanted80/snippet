<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Publishing\CssMinifier;
use Snippet\Tests\PublisherFaults;

mutates(CssMinifier::class);

/** @return array{string, int} */
function minifiedCss(string $css, ?CssMinifier $minifier = null): array
{
    $source = fopen('php://memory', 'w+b');
    $destination = fopen('php://memory', 'w+b');
    assert(is_resource($source));
    assert(is_resource($destination));
    fwrite($source, $css);
    rewind($source);

    $bytes = ($minifier ?? new CssMinifier())->minify($source, $destination);
    rewind($destination);
    $output = stream_get_contents($destination);
    fclose($source);
    fclose($destination);
    assert(is_string($output));

    return [$output, $bytes];
}

it('conservatively collapses CSS whitespace while preserving tokens, comments, strings, and escapes', function (): void {
    $css = <<<'CSS'
@media screen  and (min-width: 1px) {
    .card,  .item:hover {
        margin: calc(100% - 1rem);
        --label: "a  b";
        font-family: A  B;
        width: var(--size, 10px);
        content: "/* text */ \"";
        color: red /* keep  this */ ;
    }
}
.\31 23  >  [data-value="a,b"] { color: blue; }
CSS;

    [$output, $bytes] = minifiedCss($css);

    expect($output)->toBe('@media screen and (min-width: 1px){.card,.item:hover{margin: calc(100% - 1rem);--label: "a  b";font-family: A B;width: var(--size,10px);content: "/* text */ \"";color: red /* keep  this */;}}.\31 23 > [data-value="a,b"]{color: blue;}')
        ->and($bytes)->toBe(mb_strlen($output, '8bit'))
        ->and($bytes)->toBeLessThanOrEqual(mb_strlen($css, '8bit'));
});

it('preserves string continuations and complete hexadecimal escape terminators', function (): void {
    $css = "a { content: \"line\\\r\ncontinued\"; } .\\123456 { color: red; }";

    [$output] = minifiedCss($css);

    expect($output)->toBe("a{content: \"line\\\r\ncontinued\";}.\\123456 {color: red;}");
});

it('handles complete escape forms and end-of-input scanner states', function (string $css, string $expected): void {
    [$output] = minifiedCss($css);

    expect($output)->toBe($expected);
})->with([
    'empty input' => ['', ''],
    'trailing slash' => ['a /', 'a /'],
    'slash between tokens' => ['a / b', 'a / b'],
    'non-hexadecimal escape' => ['.a\\g { }', '.a\\g{}'],
    'short hexadecimal escape at end' => ['\\1', '\\1'],
    'six-digit hexadecimal escape at end' => ['\\123456', '\\123456'],
    'hexadecimal escape with CRLF terminator' => ["\\1\r\nx", "\\1\r\nx"],
    'hexadecimal escape with CR terminator' => ["\\1\rx", "\\1\rx"],
    'hexadecimal escape followed by a token' => ['\\1g { }', '\\1g{}'],
    'string continuation with CR' => ["a { content: \"x\\\ry\"; }", "a{content: \"x\\\ry\";}"],
    'string escape at end' => ['"x\\', '"x\\'],
]);

it('bounds delimiter state and falls back beyond the fixed nesting ceiling', function (): void {
    $valid = 'a,  b ' . str_repeat('(', 256) . 'x' . str_repeat(')', 256);
    $uncertain = 'a,  b ' . str_repeat('(', 257) . 'x' . str_repeat(')', 257);

    [$validOutput] = minifiedCss($valid);
    [$uncertainOutput] = minifiedCss($uncertain);

    expect($validOutput)->toBe('a,b ' . str_repeat('(', 256) . 'x' . str_repeat(')', 256))
        ->and($uncertainOutput)->toBe($uncertain);
});

it('resets scanner and byte-counting state when one minifier is reused', function (): void {
    $minifier = new CssMinifier();

    minifiedCss(str_repeat('a', 8_193), $minifier);
    [$output, $bytes] = minifiedCss('b { color: blue; }', $minifier);

    expect($output)->toBe('b{color: blue;}')
        ->and($bytes)->toBe(mb_strlen($output, '8bit'));
});

it('flushes at exact output buffer boundaries', function (int $length, array $outcomes, int $writes, bool $fails): void {
    /** @var list<'fail'|'pass'> $outcomes */
    $source = fopen('php://memory', 'w+b');
    $destination = fopen('php://memory', 'w+b');
    assert(is_resource($source));
    assert(is_resource($destination));
    fwrite($source, str_repeat('a', $length));
    rewind($source);
    PublisherFaults::set('publishing_fwrite', $outcomes);

    $minify = fn(): int => new CssMinifier()->minify($source, $destination);
    if ($fails) {
        expect($minify)->toThrow(ContentException::class, 'Unable to write stylesheet while minifying it.');
    } else {
        expect($minify())->toBe($length);
    }

    expect(PublisherFaults::calls('publishing_fwrite'))->toBe($writes);
    fclose($source);
    fclose($destination);
})->with([
    'one complete buffer' => [8_192, ['pass', 'fail'], 1, false],
    'one buffer and one byte' => [8_193, ['pass', 'fail'], 2, true],
    'two complete buffers' => [16_384, ['pass', 'pass', 'fail'], 2, false],
    'two buffers and one byte' => [16_385, ['pass', 'pass', 'fail'], 3, true],
]);

it('completes partial stylesheet writes', function (): void {
    PublisherFaults::set('publishing_fwrite', ['partial']);

    [$output, $bytes] = minifiedCss('abc');

    expect($output)->toBe('abc')
        ->and($bytes)->toBe(3)
        ->and(PublisherFaults::calls('publishing_fwrite'))->toBe(2);
});

it('keeps scanner state across fixed-size input and output buffer boundaries', function (): void {
    $css = str_repeat('a', 8_191) . "/* boundary\ncomment */  { content: \"boundary\\\" string\"; }\n";

    [$output, $bytes] = minifiedCss($css);

    expect($output)->toBe(str_repeat('a', 8_191) . "/* boundary\ncomment */{content: \"boundary\\\" string\";}")
        ->and($bytes)->toBe(mb_strlen($output, '8bit'));
});

it('rewinds and copies malformed or uncertain CSS byte for byte', function (string $css): void {
    [$output, $bytes] = minifiedCss($css);

    expect($output)->toBe($css)
        ->and($bytes)->toBe(mb_strlen($css, '8bit'));
})->with([
    'unterminated comment' => 'a,  b /* no end',
    'unterminated string' => 'a,  b "no end',
    'raw newline in string' => "a,  b \"line\nbreak\"",
    'unterminated escape' => 'a,  b \\',
    'unclosed block' => 'a { color: red;',
    'unmatched closer' => 'a { color: red; }}',
    'crossed delimiters' => 'a { width: calc([100%)); }',
]);

it('routes every malformed lexical state through the fallback copy', function (string $css): void {
    PublisherFaults::set('publishing_rewind', ['fail']);

    expect(fn(): array => minifiedCss($css))
        ->toThrow(ContentException::class, 'Unable to rewind stylesheet streams after malformed CSS.');
})->with([
    'unterminated comment' => 'a,  b /* no end',
    'unterminated double-quoted string' => 'a,  b "no end',
    'unterminated single-quoted string' => "a,  b 'no end",
    'line feed in string' => "a,  b \"line\nbreak\"",
    'carriage return in string' => "a,  b \"line\rbreak\"",
    'form feed in string' => "a,  b \"line\fbreak\"",
    'line feed in final string' => "a,  b \"line\nbreak",
    'carriage return in final string' => "a,  b \"line\rbreak",
    'form feed in final string' => "a,  b \"line\fbreak",
    'unterminated string escape' => 'a,  b "escape\\',
    'unterminated top-level escape' => 'a,  b \\',
    'line feed after top-level escape' => "a,  b \\\n",
    'carriage return after top-level escape' => "a,  b \\\r",
    'form feed after top-level escape' => "a,  b \\\f",
]);

it('does not enter malformed fallback for complete end-of-input states', function (string $css): void {
    PublisherFaults::set('publishing_rewind', ['fail']);

    [$output, $bytes] = minifiedCss($css);

    expect($output)->toBe($css)
        ->and($bytes)->toBe(mb_strlen($css, '8bit'));
})->with([
    'trailing slash' => 'a/',
    'short hexadecimal escape' => '\\1',
    'six-digit hexadecimal escape' => '\\123456',
]);

it('preserves required whitespace after hexadecimal escape boundary bytes', function (string $hexadecimal): void {
    $css = '\\' . $hexadecimal . '  x';

    [$output] = minifiedCss($css);

    expect($output)->toBe($css);
})->with(['0', '9', 'A', 'F', 'a', 'f']);

it('does not consume a seventh hexadecimal escape digit', function (): void {
    [$output] = minifiedCss('\\1234567  x');

    expect($output)->toBe('\\1234567 x');
});

it('keeps comment contents intact until the actual closing pair', function (): void {
    [$output] = minifiedCss('a/*/  b */  c');

    expect($output)->toBe('a/*/  b */ c');
});

it('is idempotent with output bounded by large input', function (): void {
    $rule = ".item, .other { width: calc(100% - 1rem); color: red; }\n";
    $css = str_repeat($rule, 20_000);

    [$once] = minifiedCss($css);
    [$twice, $bytes] = minifiedCss($once);

    expect($twice)->toBe($once)
        ->and($bytes)->toBe(mb_strlen($once, '8bit'))
        ->and($bytes)->toBeLessThanOrEqual(mb_strlen($css, '8bit'))
        ->and(mb_substr_count($once, '.item,.other{'))->toBe(20_000);
});

it('reports deterministic stylesheet stream failures', function (string $operation, array $outcomes, string $css, string $message): void {
    /** @var list<'fail'|'pass'|'throw'|'zero'> $outcomes */
    $source = fopen('php://memory', 'w+b');
    $destination = fopen('php://memory', 'w+b');
    assert(is_resource($source));
    assert(is_resource($destination));
    fwrite($source, $css);
    rewind($source);
    PublisherFaults::set($operation, $outcomes);

    expect(fn(): int => new CssMinifier()->minify($source, $destination))
        ->toThrow(ContentException::class, $message);

    fclose($source);
    fclose($destination);
})->with([
    'scan read' => ['publishing_fread', ['fail'], 'a { color: red; }', 'Unable to read stylesheet while minifying it.'],
    'fallback rewind' => ['publishing_rewind', ['fail'], '{', 'Unable to rewind stylesheet streams after malformed CSS.'],
    'fallback read' => ['publishing_fread', ['pass', 'pass', 'fail'], '{', 'Unable to read the original stylesheet after malformed CSS.'],
    'write' => ['publishing_fwrite', ['fail'], 'a { color: red; }', 'Unable to write stylesheet while minifying it.'],
    'zero-byte write' => ['publishing_fwrite', ['zero'], 'a { color: red; }', 'Unable to write stylesheet while minifying it.'],
]);
