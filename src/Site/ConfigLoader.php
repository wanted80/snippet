<?php

declare(strict_types=1);

namespace Snippet\Site;

use NoDiscard;
use Snippet\Exception\ContentException;
use Snippet\Support\RegularFileInventory;
use Snippet\Support\TrustedPhpLoader;
use Snippet\Support\Utf8FileValidator;
use Uri\Rfc3986\Uri;

use function array_diff;
use function array_keys;
use function is_array;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function mb_check_encoding;
use function mb_trim;
use function preg_match;
use function rawurldecode;

/** Loads the stable, trusted site customization boundary. */
final readonly class ConfigLoader
{
    private const array FIELDS = ['title', 'sitename', 'author', 'description', 'url', 'language', 'home', 'build'];

    public function __construct(
        private TrustedPhpLoader $phpLoader = new TrustedPhpLoader(),
        private RegularFileInventory $fileInventory = new RegularFileInventory(),
        private Utf8FileValidator $utf8FileValidator = new Utf8FileValidator(),
    ) {}

    /** @throws ContentException when site configuration or presentation files are invalid */
    #[NoDiscard('the loaded configuration should be consumed')]
    public function load(string $siteDirectory, ?Limits $limits = null): Config
    {
        $limits ??= new Limits();
        $path = $siteDirectory . '/config.php';
        $value = $this->phpLoader->load($path, 'site configuration', $limits->metadataBytes);
        if (!$this->hasExactFields($value, self::FIELDS)) {
            throw new ContentException("Site configuration must return the exact fields: title, sitename, author, description, url, language, home, build.");
        }

        $title = $this->text($value, 'title');
        $sitename = $this->text($value, 'sitename');
        $author = $this->text($value, 'author');
        $description = $this->text($value, 'description');
        $url = $this->url($value['url']);
        $language = $this->language($value['language']);
        [$homeArticles, $homeTags] = $this->home($value['home']);
        $minify = $this->build($value['build']);
        $assetsDirectory = $siteDirectory . '/assets';
        $assets = file_exists($assetsDirectory) || is_link($assetsDirectory)
            ? $this->fileInventory->files($assetsDirectory, 'site assets')
            : [];
        $stylesheet = $siteDirectory . '/site.css';
        $script = $siteDirectory . '/site.js';
        $hasSiteStylesheet = $this->optionalTextAsset($stylesheet, 'Site site.css');
        $hasSiteScript = $this->optionalTextAsset($script, 'Site site.js');

        return new Config($title, $sitename, $author, $description, $url, $language, $assets, $hasSiteStylesheet, $hasSiteScript, $homeArticles, $homeTags, $minify);
    }

    /**
     * @param array<mixed, mixed> $value
     * @param list<string> $fields
     */
    private function hasExactFields(array $value, array $fields): bool
    {
        return array_diff(array_keys($value), $fields) === [] && array_diff($fields, array_keys($value)) === [];
    }

    /** @param array<string, mixed> $value */
    private function text(array $value, string $field): string
    {
        if (!isset($value[$field]) || !is_string($value[$field]) || !mb_check_encoding($value[$field], 'UTF-8') || mb_trim($value[$field]) === '' || $value[$field] !== mb_trim($value[$field])) {
            throw new ContentException(sprintf("Site configuration field '%s' must be a trimmed, non-empty UTF-8 string.", $field));
        }

        return $value[$field];
    }

    private function url(mixed $value): string
    {
        if (!is_string($value) || str_ends_with($value, '/')) {
            throw new ContentException("Site configuration field 'url' must be an HTTPS site URL without a trailing slash.");
        }
        if (preg_match('/[\x00-\x20\x7F"\'<>]/', $value) === 1) {
            throw new ContentException("Site configuration field 'url' path control characters, whitespace, quotes, '<', and '>' must be percent-encoded.");
        }

        $uri = Uri::parse($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !$uri instanceof Uri || $uri->getRawScheme() !== 'https' || $uri->getRawHost() === null || $uri->getRawUserInfo() !== null || $uri->getRawQuery() !== null || $uri->getRawFragment() !== null) {
            throw new ContentException("Site configuration field 'url' must be an HTTPS site URL without credentials, query, or fragment.");
        }

        $path = $uri->getRawPath();
        if (($path !== '' && !str_starts_with($path, '/')) || str_contains($path, '//') || str_contains($path, '\\') || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            throw new ContentException("Site configuration field 'url' must be an HTTPS site URL with a well-formed absolute path.");
        }

        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || str_contains($decoded, '/') || str_contains($decoded, '\\') || !mb_check_encoding($decoded, 'UTF-8')) {
                throw new ContentException("Site configuration field 'url' must be an HTTPS site URL whose path contains no traversal or encoded separators.");
            }
        }

        return $value;
    }

    /** @return array{positive-int, positive-int} */
    private function home(mixed $value): array
    {
        if (!is_array($value) || !$this->hasExactFields($value, ['articles', 'tags'])) {
            throw new ContentException("Site configuration field 'home' must contain exact articles and tags fields.");
        }

        if (!is_int($value['articles']) || $value['articles'] < 1 || !is_int($value['tags']) || $value['tags'] < 1) {
            throw new ContentException("Site configuration home articles and tags must be positive integers.");
        }

        return [$value['articles'], $value['tags']];
    }

    private function build(mixed $value): bool
    {
        if (!is_array($value) || !$this->hasExactFields($value, ['minify'])) {
            throw new ContentException("Site configuration field 'build' must contain the exact minify field.");
        }

        if (!is_bool($value['minify'])) {
            throw new ContentException("Site configuration field 'build.minify' must be a boolean.");
        }

        return $value['minify'];
    }

    private function language(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $value) !== 1) {
            throw new ContentException("Site configuration field 'language' must be a valid language tag.");
        }

        return $value;
    }

    private function optionalTextAsset(string $path, string $label): bool
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new ContentException("{$label} must be a regular non-symlink file.");
        }

        if (!is_file($path)) {
            return false;
        }

        if (!$this->utf8FileValidator->isValid($path)) {
            throw new ContentException("{$label} must be a readable UTF-8 file.");
        }

        return true;
    }


}
