<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Site\Config;
use Snippet\Site\ConfigLoader;

it('renders ordered profiles and llms in the footer outside the simple navigation', function (): void {
    $profiles = array_map(static fn(string $icon): array => ['label' => ucfirst($icon), 'url' => 'https://example.test/' . $icon, 'icon' => $icon], ConfigLoader::PROFILE_ICONS);
    $profiles[] = ['label' => 'A & B', 'url' => 'https://example.test/?a=1&b=2'];
    $this->site(['profiles' => $profiles]);
    $this->item('about', ['title' => 'About', 'description' => 'About.', 'menu_order' => 1]);
    [$status, , $error] = validatePublication($this->directory, 'build');
    expect($status)->toBe(0, $error);
    $html = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($html));
    expect($html)->toContain('aria-label="Profiles"', 'A &amp; B', 'a=1&amp;b=2')
        ->not->toContain('menu-group')
        ->and(mb_strpos($html, 'class="profile-links"'))->toBeGreaterThan((int) mb_strpos($html, '<footer'))
        ->and(mb_strpos($html, 'rel="me"'))->toBeGreaterThan((int) mb_strpos($html, '<footer'))
        ->and(mb_substr_count($html, 'class="profile-icon"'))->toBe(8)
        ->and(mb_strpos($html, 'Github'))->toBeLessThan((int) mb_strpos($html, 'Mastodon'))
        ->and(mb_strpos($html, '/llms.txt'))->toBeGreaterThan((int) mb_strpos($html, '<footer'));
});

it('renders icon-only profiles with the destination as their accessible name', function (string $icon): void {
    $url = 'https://example.test/profile?a=1&b=2';
    $this->content();
    $this->site(['profiles' => [['url' => $url, 'icon' => $icon]]]);
    expect(new ConfigLoader()->load($this->directory . '/site')->profiles)->toBe([['url' => $url, 'icon' => $icon]]);
    [$status, , $error] = validatePublication($this->directory, 'build');
    expect($status)->toBe(0, $error);
    $html = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($html));
    expect($html)->toContain('<a rel="me" href="https://example.test/profile?a=1&amp;b=2" aria-label="https://example.test/profile?a=1&amp;b=2"><svg')
        ->toContain('</svg></a>');
})->with(ConfigLoader::PROFILE_ICONS);

it('rejects malformed profile configuration', function (mixed $profiles): void {
    $this->site(['profiles' => $profiles]);
    expect(fn(): Config => new ConfigLoader()->load($this->directory . '/site'))->toThrow(ContentException::class);
})->with([
    'not list' => [['one' => []]],
    'missing fields' => [[['label' => 'Link']]],
    'unknown fields' => [[['label' => 'Link', 'url' => 'https://example.test', 'extra' => true]]],
    'http' => [[['label' => 'Link', 'url' => 'http://example.test']]],
    'credentials' => [[['label' => 'Link', 'url' => 'https://user@example.test']]],
    'unknown icon' => [[['label' => 'Link', 'url' => 'https://example.test', 'icon' => 'unknown']]],
    'neither label nor icon' => [[['url' => 'https://example.test']]],
    'null label with icon' => [[['label' => null, 'url' => 'https://example.test', 'icon' => 'github']]],
    'blank label with icon' => [[['label' => ' ', 'url' => 'https://example.test', 'icon' => 'github']]],
    'null icon without label' => [[['url' => 'https://example.test', 'icon' => null]]],
    'blank label' => [[['label' => ' ', 'url' => 'https://example.test']]],
]);
