<?php

declare(strict_types=1);

namespace Snippet\Site;

use Uri\Rfc3986\Uri;

/** Validated site identity, presentation inventory, resource policy, and homepage collections. */
final readonly class Config
{
    public const array CSP_DIRECTIVES = ['script-src', 'style-src', 'img-src', 'font-src', 'connect-src'];

    public string $basePath;

    /**
     * @param list<string> $assets
     * @param list<array{label?: string, url: string, icon?: string}> $profiles
     * @param array<string, list<string>> $csp Additional HTTPS origins by fetch directive.
     */
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
        public array $profiles = [],
        public array $csp = [],
    ) {
        $this->basePath = Uri::parse($url)?->getRawPath() ?? '';
    }

    /** Extend the restrictive meta policy while retaining same-origin resources. */
    public function contentSecurityPolicy(): string
    {
        $directives = ["default-src 'none'", "base-uri 'none'", "form-action 'none'"];
        foreach (self::CSP_DIRECTIVES as $directive) {
            $sources = ["'self'", ...($this->csp[$directive] ?? [])];
            $directives[] = $directive . ' ' . implode(' ', $sources);
        }

        return implode('; ', $directives);
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
        return new Uri($this->url)->withPath('')->toRawString();
    }
}
