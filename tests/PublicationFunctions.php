<?php

declare(strict_types=1);

use Snippet\Application;

/** @return array{int, string, string} */
function validatePublication(string $root, string $command = 'validate'): array
{
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $status = new Application($root)->run(['bin/snippet', $command], $stdout, $stderr);
    $stdout->rewind();
    $stderr->rewind();
    $output = $stdout->fread(8192);
    $error = $stderr->fread(8192);
    assert(is_string($output));
    assert(is_string($error));

    return [$status, $output, $error];
}
