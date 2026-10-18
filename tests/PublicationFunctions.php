<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Content\Catalog;
use Snippet\Publishing\PublicationInputs;
use Snippet\Publishing\PublicationResourceLoader;
use Snippet\Site\Config;
use Snippet\Site\Limits;

/**
 * Run an operation without PHPUnit converting its intentionally suppressed
 * filesystem warning into a test warning.
 *
 * @template T
 * @param Closure():T $operation
 * @return T
 */
function withoutFilesystemErrorHandler(Closure $operation): mixed
{
    set_error_handler(null);
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

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

/** Prepare complete inputs while allowing tests to retain an already loaded catalog. */
function publicationInputs(
    string $root,
    Config $config,
    Catalog $catalog,
    Limits $limits = new Limits(),
    ?string $engineRoot = null,
): PublicationInputs {
    $resources = new PublicationResourceLoader(engineRoot: $engineRoot ?? $root)->load($root, $config, $limits);

    return new PublicationInputs($limits, $config, $catalog, $resources->templates, $resources->assets);
}

/** @return array<string, string> */
function publicationBytes(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);
        if ($file->isFile()) {
            $contents = file_get_contents($file->getPathname());
            assert(is_string($contents));
            $files[mb_substr($file->getPathname(), mb_strlen($root . '/public/'))] = $contents;
        }
    }

    ksort($files);
    return $files;
}
