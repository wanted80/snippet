<?php

declare(strict_types=1);

use Snippet\Publishing\HtmlMinifier;

mutates(HtmlMinifier::class);

it('collapses only whitespace text nodes between tags', function (): void {
    $html = "<!doctype html>\n<html data-value=\"a  b\">\n    <body>\n        <p>Prose  stays\nexact <strong>and</strong> <em>separated</em>.</p>\n        <!-- comment  stays -->\n    </body>\n</html>\n";
    $minified = new HtmlMinifier()->minify($html);

    expect($minified)->toBe("<!doctype html> <html data-value=\"a  b\"> <body> <p>Prose  stays\nexact <strong>and</strong> <em>separated</em>.</p> <!-- comment  stays --> </body> </html>\n")
        ->and(new HtmlMinifier()->minify($minified))->toBe($minified)
        ->and(new HtmlMinifier()->minify("<p>日本語</p>\n<div>Café</div>"))->toBe("<p>日本語</p> <div>Café</div>");
});

it('preserves raw element content byte for byte', function (string $element, string $contents): void {
    $html = "<div>\n    <{$element} data-x=\"a  b\">{$contents}</{$element}>\n</div>";
    $minified = new HtmlMinifier()->minify($html);

    expect($minified)->toContain("<{$element} data-x=\"a  b\">{$contents}</{$element}>");
})->with([
    'pre' => ['pre', "  literal\n    spacing  "],
    'code' => ['code', "const  value = '<tag>';\n"],
    'textarea' => ['textarea', "  entered\n text  "],
    'script' => ['script', "const css = 'a  b';\nrun();"],
    'style' => ['style', ".x { white-space:  pre; }\n"],
]);

it('returns malformed or uncertain input unchanged', function (string $html): void {
    expect(new HtmlMinifier()->minify($html))->toBe($html);
})->with([
    'unterminated comment' => '<div><!-- no end</div>',
    'unterminated quote' => '<div class="broken>',
    'unterminated tag' => '<div',
    'unterminated raw element' => '<script>const value = 1;',
    'uncertain less-than' => '<div>one < two</div>',
]);

it('handles a large deterministic document with output bounded by its input', function (): void {
    $html = '<!doctype html><html><body>' . str_repeat("\n    <section><p>Text  value</p><pre>  raw\n value</pre></section>", 5_000) . "\n</body></html>";
    $minified = new HtmlMinifier()->minify($html);

    expect(mb_strlen($minified, '8bit'))->toBeLessThanOrEqual(mb_strlen($html, '8bit'))
        ->and(mb_substr_count($minified, '<section>'))->toBe(5_000)
        ->and($minified)->toContain("<pre>  raw\n value</pre>");
});
