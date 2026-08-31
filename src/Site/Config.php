<?php

declare(strict_types=1);

namespace Snippet\Site;

/** Validated site identity, presentation inventory, and build preferences. */
final readonly class Config
{
    public string $basePath;

    /** @param list<string> $assets */
    public function __construct(
        public string $title,
        public string $sitename,
        public string $author,
        public string $description,
        public string $url,
        public string $language,
        public array $assets,
        public bool $hasSiteStylesheet,
        public bool $hasSiteScript = false,
        public int $homeArticles = 10,
        public int $homeTags = 20,
        public bool $minify = false,
    ) {
        $path = parse_url($url, PHP_URL_PATH);
        $this->basePath = is_string($path) ? $path : '';
    }

    /** Prefix a validated logical publication route for browser-facing output. */
    public function publicPath(string $route): string
    {
        return $this->basePath . $route;
    }

    /** Convert a validated logical publication route to its canonical URL. */
    public function canonicalUrl(string $route): string
    {
        return $this->url . $route;
    }

    /** Return the HTTPS origin independently from the configured deployment path. */
    public function origin(): string
    {
        $scheme = parse_url($this->url, PHP_URL_SCHEME);
        $host = parse_url($this->url, PHP_URL_HOST);
        $port = parse_url($this->url, PHP_URL_PORT);

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }
}
