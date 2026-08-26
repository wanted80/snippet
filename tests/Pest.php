<?php

declare(strict_types=1);

use Snippet\Tests\TestCase;

require_once __DIR__ . '/PublisherFunctions.php';
require_once __DIR__ . '/DraftFunctions.php';
require_once __DIR__ . '/PreviewFunctions.php';

pest()
    ->extend(TestCase::class)
    ->in('Feature', 'Unit');

pest()->tia()
    ->locally();

expect()->extend('toBeStrictlyEqualTo', fn(mixed $expected): mixed => $this->toBe($expected));
