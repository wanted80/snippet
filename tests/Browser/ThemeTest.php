<?php

declare(strict_types=1);

use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Page;

dataset('theme builds', ['ordinary' => false, 'minified' => true]);
dataset('theme screens', ['desktop' => false, 'mobile' => true]);
dataset('theme palettes', ['light' => 'light', 'dark' => 'dark']);

it('computes component tokens and independent glass on real publications', function (bool $minify, bool $mobile, string $theme): void {
    $url = $this->publication($minify, <<<'CSS'
        @layer overrides {
            :root {
                --color-background: light-dark(#fafafa, #101010);
                --color-surface: light-dark(#f5f5f5, #181818);
                --color-interactive: light-dark(#eeeeee, #202020);
                --color-text: light-dark(#111111, #ffffff);
                --color-muted: light-dark(#555555, #bbbbbb);
                --color-accent: light-dark(#003399, #aaccff);
                --color-border: light-dark(#666666, #999999);
                --color-on-accent: light-dark(#ffffff, #000000);
                --color-header-background: light-dark(#ddeeff, #223344);
                --color-navigation-background: light-dark(#eeeeff, #112233);
                --color-header-button-background: light-dark(#ccddee, #334455);
                --color-navigation-item-background: light-dark(#ddddff, #223355);
                --opacity-header-background: 50%;
                --opacity-navigation-background: 100%;
                --shadow-header: none;
                --shadow-menu: none;
                --shadow-content: none;
                --radius-control: 4px;
                --radius-panel: 20px;
            }
        }
        CSS);
    $url .= '/about/';
    $browser = visit($url, ['viewport' => ['width' => $mobile ? 390 : 1280, 'height' => 800], 'isMobile' => $mobile, 'hasTouch' => $mobile, 'colorScheme' => $theme, 'reducedMotion' => 'reduce']);
    $page = $browser->__call('page', []);
    assert($page instanceof Page);
    // Direct Pest assertions avoid the automatic failure-screenshot wrapper.
    $browser = new Webpage($page, $url);
    $browser->assertScript('document.documentElement.dataset.theme', $theme);
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'rgb(250, 250, 250)' : 'rgb(16, 16, 16)');
    $browser->script('window.scrollTo(0, 400)');
    $browser->script('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $browser->assertScript('document.querySelector(".site-header").hasAttribute("data-scrolled")');
    $browser->click('.menu-toggle');
    $browser->assertScript('document.querySelector(".site-navigation").matches(":popover-open")');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'color(srgb 0.866667 0.933333 1 / 0.5)' : 'color(srgb 0.133333 0.2 0.266667 / 0.5)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor', $theme === 'light' ? 'color(srgb 0.933333 0.933333 1)' : 'color(srgb 0.0666667 0.133333 0.2)');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-toggle")).backgroundColor', $theme === 'light' ? 'rgb(204, 221, 238)' : 'rgb(51, 68, 85)');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).backgroundColor', $theme === 'light' ? 'rgb(221, 221, 255)' : 'rgb(34, 51, 85)');
    $browser->assertScript('getComputedStyle(document.querySelector("[aria-current=page]")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].map(s => getComputedStyle(document.querySelector(s)).boxShadow)', ['none', 'none', 'none']);
    $browser->assertScript('[".menu-toggle", ".menu-link", ".site-navigation", ".prose pre", ".site-main"].map(s => getComputedStyle(document.querySelector(s)).borderTopLeftRadius)', ['4px', '4px', '20px', '20px', $mobile ? '0px' : '20px']);
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).borderBottomLeftRadius', '20px');
    $browser->assertScript('document.documentElement.scrollWidth <= innerWidth');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).transitionDuration', '0s');

    $helper = file_get_contents(__DIR__ . '/contrast.js');
    assert(is_string($helper));
    $browser->script($helper);
    $browser->assertScript('themeContrast(".site-main", "color", ".site-main", "backgroundColor") >= 4.5');
    $browser->assertScript('themeContrast(".menu-link:not([aria-current])", "color", ".menu-link:not([aria-current])", "backgroundColor") >= 4.5');
    $browser->assertScript('themeContrast("[aria-current=page]", "color", "[aria-current=page]", "backgroundColor") >= 4.5');

    $page->keyDown('Tab');
    $page->keyUp('Tab');

    $browser->script('document.querySelector(".menu-link:not([aria-current])").focus()');
    $browser->assertScript('document.querySelector(".menu-link:not([aria-current])").matches(":focus-visible")');
    $browser->assertScript('themeContrast(".menu-link:not([aria-current])", "outlineColor", ".site-navigation", "backgroundColor") >= 3');
    $browser->assertScript('getComputedStyle(document.querySelector(".skip-link")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    if ($mobile) {
        // Capture the native tap's active style before release clears :active.
        $browser->script('document.querySelector(".menu-link:not([aria-current])").addEventListener("click", event => { event.preventDefault(); const s = getComputedStyle(event.currentTarget); window.touchStyle = [s.backgroundColor, s.color, event.currentTarget.matches(":active")]; }, {once: true})');
        $page->locator('.menu-link[href="/articles/"]')->tap();
        $browser->assertScript('window.touchStyle', [$theme === 'light' ? 'rgb(0, 51, 153)' : 'rgb(170, 204, 255)', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)', true]);
    } else {
        $browser->hover('.menu-link[href="/articles/"]');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).backgroundColor', $theme === 'light' ? 'rgb(0, 51, 153)' : 'rgb(170, 204, 255)');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    }
    $this->media($page, features: [['name' => 'prefers-reduced-transparency', 'value' => 'reduce'], ['name' => 'prefers-reduced-motion', 'value' => 'reduce']]);
    $browser->assertScript('matchMedia("(prefers-reduced-transparency: reduce)").matches');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'rgb(221, 238, 255)' : 'rgb(34, 51, 68)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor', $theme === 'light' ? 'rgb(238, 238, 255)' : 'rgb(17, 34, 51)');
    $browser->assertScript('[".site-header", ".site-navigation"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['none', 'none']);
    $this->media($page, 'print');
    $browser->assertScript('matchMedia("print").matches');
    $browser->assertScript('getComputedStyle(document.body).color', 'rgb(17, 17, 17)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).display', 'none');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-main")).boxShadow', 'none');
    $browser->assertScript('getComputedStyle(document.body, "::selection").color', 'rgb(255, 255, 255)');
    $this->media($page, features: [['name' => 'prefers-reduced-motion', 'value' => 'reduce']]);
    $page->goto(str_replace('/about/', '/', $url));
    $browser->assertScript('getComputedStyle(document.querySelector(".tag-count")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    $browser->assertScript('getComputedStyle(document.body, "::selection").color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    if ($mobile) {
        $browser->script('document.querySelector(".theme-toggle").addEventListener("click", event => { event.stopImmediatePropagation(); const s = getComputedStyle(event.currentTarget); window.touchStyle = [s.backgroundColor, event.currentTarget.matches(":active")]; }, {once: true, capture: true})');
        $page->locator('.theme-toggle')->tap();
        $browser->assertScript('window.touchStyle', [$theme === 'light' ? 'rgb(204, 221, 238)' : 'rgb(51, 68, 85)', true]);
    } else {
        $browser->hover('.theme-toggle');
        $browser->assertScript('getComputedStyle(document.querySelector(".theme-toggle")).backgroundColor', $theme === 'light' ? 'rgb(204, 221, 238)' : 'rgb(51, 68, 85)');
        $browser->hover('.tag-grid a');
        $browser->assertScript('getComputedStyle(document.querySelector(".tag-grid a")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
        $browser->assertScript('getComputedStyle(document.querySelector(".tag-count")).backgroundColor', $theme === 'light' ? 'rgb(238, 238, 238)' : 'rgb(32, 32, 32)');
        $browser->script($helper);
        $browser->assertScript('themeContrast(".tag-grid a", "color", ".tag-grid a", "backgroundColor") >= 4.5');
    }
})->with('theme builds')->with('theme screens')->with('theme palettes');

it('preserves theme selection motion and opaque fallback with author overrides', function (bool $minify, string $theme, bool $customized): void {
    $url = $this->publication($minify, $customized ? <<<'CSS'
        @layer overrides {
            :root {
                --color-header-background: light-dark(#ddeeff, #223344);
                --color-navigation-background: light-dark(#ffffff, #111827);
                --color-header-button-background: light-dark(#ccddee, #334455);
                --color-navigation-item-background: light-dark(#edf2fa, #1b293e);
                --shadow-header: none;
                --shadow-menu: none;
                --shadow-content: none;
            }
        }
        CSS : '');
    $pending = visit($url, ['colorScheme' => $theme, 'reducedMotion' => 'no-preference']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->assertScript('getComputedStyle(document.body).backgroundColor', $theme === 'light' ? 'rgb(247, 241, 232)' : 'rgb(8, 9, 10)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).transitionDuration', '0.18s, 0.18s');
    $browser->script('window.scrollTo(0, 400)');
    $browser->script('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $browser->click('.menu-toggle');
    $browser->script('() => Promise.all(document.getAnimations().map(animation => animation.finished))');
    if ($customized) {
        $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'color(srgb 0.866667 0.933333 1 / 0.82)' : 'color(srgb 0.133333 0.2 0.266667 / 0.82)');
        $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor', $theme === 'light' ? 'color(srgb 1 1 1 / 0.82)' : 'color(srgb 0.0666667 0.0941176 0.152941 / 0.82)');
        $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].map(s => getComputedStyle(document.querySelector(s)).boxShadow)', ['none', 'none', 'none']);
    } else {
        $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'color(srgb 1 0.980392 0.94902 / 0.82)' : 'color(srgb 0.054902 0.0666667 0.0784314 / 0.82)');
        $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor === getComputedStyle(document.querySelector(".site-header")).backgroundColor');
        $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].every(s => getComputedStyle(document.querySelector(s)).boxShadow !== "none")');
    }
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backdropFilter', 'saturate(1.4) blur(16px)');
    $browser->click('.theme-toggle');
    $browser->assertScript('document.documentElement.dataset.theme', $theme === 'light' ? 'dark' : 'light');
    $browser->assertScript('localStorage.getItem("snippet-theme")', $theme === 'light' ? 'dark' : 'light');

    $page->reload();
    $browser->assertScript('document.documentElement.dataset.theme', $theme === 'light' ? 'dark' : 'light');
    // No manual choice: native CSS must also follow the system before JavaScript runs.
    $browser->script('document.documentElement.removeAttribute("data-theme")');
    $browser->assertScript('getComputedStyle(document.body).backgroundColor', $theme === 'light' ? 'rgb(247, 241, 232)' : 'rgb(8, 9, 10)');
    $browser->script('window.scrollTo(0, 400)');
    $browser->script('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $browser->click('.menu-toggle');
    // Unsupported-glass simulation: copy only the installed stylesheet into memory,
    // remove its enhancement blocks through CSSOM, and replace that sheet in place.
    // This does not emulate an old browser or alter published bytes/media rules.
    $removed = $browser->script(<<<'JS'
        () => {
            const original = [...document.styleSheets].find(sheet => sheet.href?.includes('/theme.'));
            const copy = new CSSStyleSheet();
            copy.replaceSync([...original.cssRules].map(rule => rule.cssText).join('\n'));
            let removed = 0;
            function strip(group) {
                for (let i = group.cssRules.length - 1; i >= 0; i--) {
                    const rule = group.cssRules[i];
                    if (rule instanceof CSSSupportsRule && rule.conditionText.includes('backdrop-filter')) {
                        group.deleteRule(i);
                        removed++;
                    } else if (rule.cssRules) strip(rule);
                }
            }
            strip(copy);
            // Keep the existing sheet's position and CSP; change only its in-memory rules.
            while (original.cssRules.length) original.deleteRule(original.cssRules.length - 1);
            for (const rule of copy.cssRules) original.insertRule(rule.cssText, original.cssRules.length);
            return removed;
        }
        JS);
    expect($removed)->toBe(2);
    $browser->script('() => Promise.all(document.getAnimations().map(animation => animation.finished))');
    $browser->assertScript('[".site-header", ".site-navigation"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['none', 'none']);
    if ($customized) {
        $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'rgb(221, 238, 255)' : 'rgb(34, 51, 68)');
        $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(17, 24, 39)');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-toggle")).backgroundColor', $theme === 'light' ? 'rgb(204, 221, 238)' : 'rgb(51, 68, 85)');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).backgroundColor', $theme === 'light' ? 'rgb(237, 242, 250)' : 'rgb(27, 41, 62)');
        $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].map(s => getComputedStyle(document.querySelector(s)).boxShadow)', ['none', 'none', 'none']);
    } else {
        $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'rgb(255, 250, 242)' : 'rgb(14, 17, 20)');
        $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor', $theme === 'light' ? 'rgb(255, 250, 242)' : 'rgb(14, 17, 20)');
        $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].every(s => getComputedStyle(document.querySelector(s)).boxShadow !== "none")');
    }
})->with('theme builds')->with('theme palettes')->with(['default CSS' => false, 'custom site CSS' => true]);

it('lets override layer class rules win against responsive glass and interaction variants', function (bool $minify, bool $mobile): void {
    $url = $this->publication($minify, <<<'CSS'
        @layer overrides {
            .site-header, .site-navigation { background: rgb(20 40 60); backdrop-filter: none; }
            .icon-button, .menu-link, .button-link, .tag-list a { background: rgb(70 80 90); color: rgb(255 255 255); transition: none; }
            .site-main { border-radius: 9px; }
        }
        CSS);
    $pending = visit($url, ['viewport' => ['width' => $mobile ? 390 : 1280, 'height' => 800], 'isMobile' => $mobile, 'hasTouch' => $mobile, 'reducedMotion' => 'reduce']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('window.scrollTo(0, 300)');
    $browser->script('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $browser->click('.menu-toggle');
    if ($mobile) {
        $browser->script('document.querySelector(".menu-link").addEventListener("click", event => { event.preventDefault(); window.activeOverride = [getComputedStyle(event.currentTarget).backgroundColor, event.currentTarget.matches(":active")]; }, {once: true})');
        $page->locator('.menu-link[href="/articles/"]')->tap();
        $browser->assertScript('window.activeOverride', ['rgb(70, 80, 90)', true]);
    } else {
        $browser->hover('.menu-link[href="/articles/"]');
    }
    $browser->assertScript('[".site-header", ".site-navigation"].map(s => getComputedStyle(document.querySelector(s)).backgroundColor)', ['rgb(20, 40, 60)', 'rgb(20, 40, 60)']);
    $browser->assertScript('[".menu-toggle", ".menu-link"].map(s => getComputedStyle(document.querySelector(s)).backgroundColor)', ['rgb(70, 80, 90)', 'rgb(70, 80, 90)']);
    $browser->assertScript('getComputedStyle(document.querySelector(".site-main")).borderRadius', '9px');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-link")).transitionDuration', '0s');
})->with('theme builds')->with('theme screens');
