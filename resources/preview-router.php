<?php

declare(strict_types=1);

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$configuredBasePath = getenv('SNIPPET_BASE_PATH');
$basePath = is_string($configuredBasePath) ? $configuredBasePath : '';
if ($documentRoot === false || !is_string($requestPath)) {
    return false;
}

header('Cache-Control: no-store');

if ($basePath !== '') {
    if ($requestPath === '/') {
        header('Location: ' . $basePath . '/', true, 302);
        return true;
    }

    if ($requestPath === $basePath) {
        header('Location: ' . $basePath . '/', true, 301);
        return true;
    }

    if (!str_starts_with($requestPath, $basePath . '/')) {
        http_response_code(404);
        return true;
    }

    $publicRequestPath = mb_substr($requestPath, mb_strlen($basePath));
} else {
    $publicRequestPath = $requestPath;
}

if ($publicRequestPath === '/.snippet-preview-reload.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    $encodedBasePath = json_encode($basePath, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    echo <<<JS
(() => {
    const basePath = {$encodedBasePath};
    const baseline = document.currentScript?.dataset.version;
    if (!baseline) {
        return;
    }
    const version = async () => {
        const response = await fetch(basePath + '/.snippet-preview-version', { cache: 'no-store' });
        return response.ok ? (await response.text()).trim() : null;
    };
    const check = async () => {
        try {
            const current = await version();
            if (current !== null && current !== baseline) {
                location.reload();
                return;
            }
        } catch {
            // A transactional rebuild can briefly replace the publication.
        }
        setTimeout(check, 500);
    };
    setTimeout(check, 500);
})();
JS;
    return true;
}

$candidate = $documentRoot . '/' . mb_ltrim(rawurldecode($publicRequestPath), '/');
if (is_dir($candidate)) {
    $candidate .= '/index.html';
}
$notFound = false;
$resolved = realpath($candidate);
if ($resolved === false || !str_starts_with($resolved, $documentRoot . '/') || !is_file($resolved)) {
    $resolved = realpath($documentRoot . '/404.html');
    if ($resolved === false || !str_starts_with($resolved, $documentRoot . '/') || !is_file($resolved)) {
        http_response_code(404);
        return true;
    }
    $notFound = true;
}
$extension = mb_strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
if ($extension !== 'html') {
    $contentType = $publicRequestPath === '/.snippet-preview-version' || $publicRequestPath === '/llms.txt'
        ? 'text/plain; charset=utf-8'
        : match ($extension) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            default => null,
        };
    if ($contentType === null) {
        http_response_code(404);
        return true;
    }

    header("Content-Type: {$contentType}");
    $size = @filesize($resolved);
    if (is_int($size)) {
        header("Content-Length: {$size}");
    }
    @readfile($resolved);
    return true;
}

$html = @file_get_contents($resolved);
if ($html === false) {
    http_response_code(404);
    return true;
}
$version = @file_get_contents($documentRoot . '/.snippet-preview-version');
if (!is_string($version) || preg_match('/\A[a-f0-9]{16}\n?\z/D', $version) !== 1) {
    http_response_code(500);
    return true;
}
$baseline = mb_trim($version);
$reloadPath = $basePath . '/.snippet-preview-reload.js';

if ($notFound) {
    http_response_code(404);
}
header('Content-Type: text/html; charset=utf-8');
echo str_replace('</body>', "<script src=\"{$reloadPath}\" data-version=\"{$baseline}\"></script>\n</body>", $html);
return true;
