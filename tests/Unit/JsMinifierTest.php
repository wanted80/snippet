<?php

declare(strict_types=1);

use Snippet\Publishing\JsMinifier;

mutates(JsMinifier::class);

it('conservatively compacts JavaScript without changing token boundaries', function (string $source, string $expected): void {
    $minifier = new JsMinifier();
    $output = $minifier->minify($source);
    expect($output)->toBe($expected)
        ->and($minifier->minify($output))->toBe($output)
        ->and(mb_strlen($output, '8bit'))->toBeLessThanOrEqual(mb_strlen($source, '8bit'));
})->with([
    'empty' => ['', ''],
    'horizontal space' => ["const   x =\t\t1;\v\f", 'const x = 1; '],
    'line breaks' => ["return\r\n   value\n++counter\rnext()", "return\r\n value\n++counter\rnext()"],
    'tokens' => ['a/*remove*/++ + +b; 1 .toString(); x ? .5 : 0;', 'a ++ + +b; 1 .toString(); x ? .5 : 0;'],
    'block lines' => ["return/*x\r\ny\nz*/value", "return \r\n\nvalue"],
    'line comment' => ["x; // remove\r\ny; // eof", "x; \r\ny; "],
    'quotes' => ["const  x = '日本語 / ` \\' '; const y = \"a\\\"b\";", "const x = '日本語 / ` \\' '; const y = \"a\\\"b\";"],
    'continuation' => ["x = 'a\\\r\nb';  y = 'c\\\nd';", "x = 'a\\\r\nb'; y = 'c\\\nd';"],
    'preservation' => ["/*! keep */  /** @license MIT */  // @preserve keep\nx;", "/*! keep */ /** @license MIT */ // @preserve keep\nx;"],
    'CR continuation before quote' => ["x = 'a\\\r';  y();", "x = 'a\\\r'; y();"],
    'comment delimiters in line comment' => ["// */ more\n  x();", " \n x();"],
    'slash in block comment' => ['/* a/b */  x();', ' x();'],
    'ordinary near markers' => ["/* sourceMappingUR sourceUR @licens @preserv \u{2027} */  x();", ' x();'],
    'operators near legacy comments' => ['x <!-y;  --counter;  ~bits; !ready;', 'x <!-y; --counter; ~bits; !ready;'],
    'unicode comment' => ["x/* 日本語 */y", 'x y'],
    'space before comment' => ["a \t/* remove */+b;", 'a +b;'],
    'space after comment' => ["a/* remove */ \t+b;", 'a +b;'],
    'adjacent comments' => ['a/* one *//* two */+b;', 'a +b;'],
    'comment between spaces' => ['a /* remove */  +  b;', 'a + b;'],
    'multiline comment between spaces' => ["return \t/* x\r\ny\n*/  value;", "return \r\n\n value;"],
    'preserved comment spacing' => ["/*!  keep  */ \t/* remove *//*!  keep too  */", '/*!  keep  */ /*!  keep too  */'],
]);

it('returns exact original bytes for uncertain JavaScript', function (string $uncertain): void {
    $source = "const   before = 1; /* removable */\n" . $uncertain;
    expect(new JsMinifier()->minify($source))->toBe($source);
})->with([
    '`template`', 'x / 2', '/regex/', 'x /= 2', '/', '/text*/', '<!-- legacy', '--> legacy', '#!/bin/node',
    'const café = 1;', 'const \\u0061 = 1;', "\0", "\x7f", "\x1f", "'unterminated", "'escape\\", "'raw\nline'", "'raw\rline'",
    '/* unfinished', '/*', '/*/', '// sourceMappingURL=x', '/*# sourceMappingURL=x */', '// @ sourceURL=x',
    "// line\u{2028}code()", "/* line\u{2029} */",
]);
