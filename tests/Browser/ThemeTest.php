<?php

declare(strict_types=1);

use Pest\Browser\Api\Webpage;
use Pest\Browser\Enums\BrowserType;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;

dataset('theme screens', ['desktop' => false, 'mobile' => true]);
dataset('theme palettes', ['light' => 'light', 'dark' => 'dark']);

it('preserves escaped descendant selectors through publication', function (): void {
    $url = $this->publication('@layer overrides { .foo\\, .bar { color: rgb(255 0 0); } }');
    $pending = visit($url);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script(<<<'JS'
        () => {
            const parent = document.createElement('div');
            parent.className = 'foo,';
            const child = document.createElement('span');
            child.className = 'bar';
            child.id = 'descendant';
            parent.append(child);
            const sibling = document.createElement('div');
            sibling.className = 'foo, bar';
            sibling.id = 'same-element';
            document.body.append(parent, sibling);
        }
        JS);
    $browser->assertScript('getComputedStyle(document.querySelector("#descendant")).color', 'rgb(255, 0, 0)');
    $browser->assertScript('getComputedStyle(document.querySelector("#same-element")).color === getComputedStyle(document.body).color');
});

it('computes component tokens and independent glass on real publications', function (bool $mobile, string $theme): void {
    $url = $this->publication(<<<'CSS'
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
                --opacity-header-button-background: 100%;
                --opacity-navigation-item-background: 100%;
                --shadow-header: none;
                --shadow-menu: none;
                --shadow-content: none;
                --radius-control: 4px;
                --radius-panel: 20px;
            }
        }
        CSS);
    $url .= '/about/';
    $browser = visit($url, ['viewport' => ['width' => $mobile ? 390 : 1280, 'height' => 800], 'hasTouch' => $mobile, 'colorScheme' => $theme, 'reducedMotion' => 'reduce']);
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
    $browser->assertScript('document.querySelector(".site-navigation").matches(":popover-open")', !$mobile);
    $browser->assertScript('document.querySelector("dialog").matches(":modal")', $mobile);
    $this->assertSrgb($browser, '.site-header', $theme === 'light' ? [0.866667, 0.933333, 1, 0.5] : [0.133333, 0.2, 0.266667, 0.5]);
    $this->assertSrgb($browser, '.site-navigation', $theme === 'light' ? [0.933333, 0.933333, 1, 1] : [0.0666667, 0.133333, 0.2, 1]);
    $this->assertSrgb($browser, '.menu-toggle', $theme === 'light' ? [0.8, 0.866667, 0.933333, 1] : [0.2, 0.266667, 0.333333, 1]);
    $this->assertSrgb($browser, '.menu-link:not([aria-current])', $theme === 'light' ? [0.866667, 0.866667, 1, 1] : [0.133333, 0.2, 0.333333, 1]);
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
        $this->press($page, '.menu-link[href="/articles/"]');
        $browser->assertScript('document.querySelector(".menu-link:not([aria-current])").matches(":active")');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).backgroundColor', $theme === 'light' ? 'rgb(0, 51, 153)' : 'rgb(170, 204, 255)');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
        $this->release($page);
        $browser->script('document.querySelector(".menu-link:not([aria-current])").addEventListener("click", event => { event.preventDefault(); window.linkTapped = true; }, {once: true})');
        $page->locator('.menu-link[href="/articles/"]')->tap();
        $browser->assertScript('window.linkTapped');
    } else {
        $browser->hover('.menu-link[href="/articles/"]');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).backgroundColor', $theme === 'light' ? 'rgb(0, 51, 153)' : 'rgb(170, 204, 255)');
        $browser->assertScript('getComputedStyle(document.querySelector(".menu-link:not([aria-current])")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    }
    $this->media($page, 'print');
    $browser->assertScript('matchMedia("print").matches');
    $browser->assertScript('getComputedStyle(document.body).color', 'rgb(17, 17, 17)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).display', 'none');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-main")).boxShadow', 'none');
    $browser->assertScript('getComputedStyle(document.body, "::selection").color', 'rgb(255, 255, 255)');
    $this->media($page);
    $page->goto(str_replace('/about/', '/', $url));
    $browser->assertScript('getComputedStyle(document.querySelector(".tag-count")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    $browser->assertScript('getComputedStyle(document.body, "::selection").color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
    if ($mobile) {
        $this->press($page, '.theme-toggle');
        $browser->assertScript('document.querySelector(".theme-toggle").matches(":active")');
        $this->assertSrgb($browser, '.theme-toggle', $theme === 'light' ? [0.8, 0.866667, 0.933333, 1] : [0.2, 0.266667, 0.333333, 1]);
        $browser->assertScript('getComputedStyle(document.querySelector(".theme-toggle")).backdropFilter', 'none');
        $this->release($page);
        $page->locator('.theme-toggle')->tap();
        $browser->assertScript('document.documentElement.dataset.theme', $theme === 'light' ? 'dark' : 'light');
    } else {
        $browser->hover('.theme-toggle');
        $this->assertSrgb($browser, '.theme-toggle', $theme === 'light' ? [0.8, 0.866667, 0.933333, 1] : [0.2, 0.266667, 0.333333, 1]);
        $browser->assertScript('getComputedStyle(document.querySelector(".theme-toggle")).backdropFilter', 'none');
        $browser->hover('.tag-grid a');
        $browser->assertScript('getComputedStyle(document.querySelector(".tag-grid a")).color', $theme === 'light' ? 'rgb(255, 255, 255)' : 'rgb(0, 0, 0)');
        $browser->assertScript('getComputedStyle(document.querySelector(".tag-count")).backgroundColor', $theme === 'light' ? 'rgb(238, 238, 238)' : 'rgb(32, 32, 32)');
        $browser->script($helper);
        $browser->assertScript('themeContrast(".tag-grid a", "color", ".tag-grid a", "backgroundColor") >= 4.5');
    }
})->with('theme screens')->with('theme palettes');

it('preserves theme selection motion and opaque fallback with author overrides', function (string $theme, bool $customized): void {
    $url = $this->publication($customized ? <<<'CSS'
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
        $this->assertSrgb($browser, '.site-header', $theme === 'light' ? [0.866667, 0.933333, 1, 0.82] : [0.133333, 0.2, 0.266667, 0.82]);
        $this->assertSrgb($browser, '.site-navigation', $theme === 'light' ? [1, 1, 1, 0.82] : [0.0666667, 0.0941176, 0.152941, 0.82]);
        $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].map(s => getComputedStyle(document.querySelector(s)).boxShadow)', ['none', 'none', 'none']);
    } else {
        $this->assertSrgb($browser, '.site-header', $theme === 'light' ? [1, 0.980392, 0.94902, 0.82] : [0.054902, 0.0666667, 0.0784314, 0.82]);
        $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor === getComputedStyle(document.querySelector(".site-header")).backgroundColor');
        $browser->assertScript('[".site-header", ".site-navigation", ".site-main"].every(s => getComputedStyle(document.querySelector(s)).boxShadow !== "none")');
    }
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backdropFilter', 'saturate(1.4) blur(16px)');
    $browser->assertScript('[".menu-toggle", ".menu-link"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['none', 'none']);
    $this->assertSrgb($browser, '.menu-link', $customized
        ? ($theme === 'light' ? [0.929412, 0.94902, 0.980392, 0.9] : [0.105882, 0.160784, 0.243137, 0.9])
        : ($theme === 'light' ? [0.933333, 0.890196, 0.847059, 0.9] : [0.0901961, 0.113725, 0.133333, 0.9]));
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
    $browser->assertScript('[".menu-toggle", ".menu-link"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['none', 'none']);
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
})->with('theme palettes')->with(['default CSS' => false, 'custom site CSS' => true]);

it('lets override layer class rules win against responsive glass and interaction variants', function (bool $mobile): void {
    $url = $this->publication(<<<'CSS'
        @layer overrides {
            .site-header, .site-navigation { background: rgb(20 40 60); backdrop-filter: none; }
            .icon-button, .menu-link, .button-link, .tag-list a { background: rgb(70 80 90); color: rgb(255 255 255); transition: none; }
            .site-main { border-radius: 9px; }
        }
        CSS);
    $pending = visit($url, ['viewport' => ['width' => $mobile ? 390 : 1280, 'height' => 800], 'hasTouch' => $mobile, 'reducedMotion' => 'reduce']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('window.scrollTo(0, 300)');
    $browser->script('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
    $browser->click('.menu-toggle');
    if ($mobile) {
        $this->press($page, '.menu-link[href="/articles/"]');
        $browser->assertScript('document.querySelector(".menu-link").matches(":active")');
    } else {
        $browser->hover('.menu-link[href="/articles/"]');
    }
    $browser->assertScript('[".site-header", ".site-navigation"].map(s => getComputedStyle(document.querySelector(s)).backgroundColor)', ['rgb(20, 40, 60)', 'rgb(20, 40, 60)']);
    $browser->assertScript('[".menu-toggle", ".menu-link"].map(s => getComputedStyle(document.querySelector(s)).backgroundColor)', ['rgb(70, 80, 90)', 'rgb(70, 80, 90)']);
    $browser->assertScript('getComputedStyle(document.querySelector(".site-main")).borderRadius', '9px');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-link")).transitionDuration', '0s');
    if ($mobile) {
        $this->release($page);
    }
})->with('theme screens');

it('synchronizes browser color before DOM readiness and after theme changes', function (string $theme, bool $customized): void {
    file_put_contents($this->directory . '/site/site.js', <<<'JS'
        window.initialThemeColor = {
            state: document.readyState,
            color: document.querySelector('meta[name="theme-color"]').content,
            background: getComputedStyle(document.body).backgroundColor,
        };
        JS);
    $url = $this->publication($customized ? '@layer overrides { :root { --color-background: light-dark(#faf0e6, #0a141e); } }' : '');
    $pending = visit($url, ['colorScheme' => $theme]);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $light = $customized ? 'rgb(250, 240, 230)' : 'rgb(247, 241, 232)';
    $dark = $customized ? 'rgb(10, 20, 30)' : 'rgb(8, 9, 10)';
    $browser->assertScript('window.initialThemeColor', ['state' => 'interactive', 'color' => $theme === 'light' ? $light : $dark, 'background' => $theme === 'light' ? $light : $dark]);
    $browser->assertScript('document.querySelector(".site-footer-row").textContent.replace(/\s+/g, " ").trim()', 'Generated and published with love by Snippet.');
    $browser->assertScript('document.querySelector(".site-footer-heart").getAttribute("aria-hidden")', 'true');
    $browser->click('.theme-toggle');
    $browser->assertScript('document.querySelector("meta[name=theme-color]").content', $theme === 'light' ? $dark : $light);

    $page->reload();
    $browser->assertScript('window.initialThemeColor', ['state' => 'interactive', 'color' => $theme === 'light' ? $dark : $light, 'background' => $theme === 'light' ? $dark : $light]);
})->with('theme palettes')->with([false, true]);

it('uses a modal through 1024 pixels and restores focus and scroll on dismissal', function (int $width): void {
    $url = $this->publication();
    $pending = visit($url, ['viewport' => ['width' => $width, 'height' => 700], 'reducedMotion' => 'reduce']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('scrollTo(0, 350)');
    $browser->click('.menu-toggle');
    $browser->assertScript('document.querySelector(".menu-toggle").getAttribute("aria-expanded")', 'true');
    $browser->assertScript('document.querySelector(".menu-toggle").getAttribute("aria-label")', 'Close navigation');

    $page->waitForSelector('.menu-link:focus');
    $browser->assertScript('document.activeElement === document.querySelector(".menu-link")');
    $browser->assertScript('document.querySelector("dialog").matches(":modal")', $width <= 1024);
    foreach ([['ArrowDown', 1], ['End', 3], ['ArrowDown', 0], ['ArrowUp', 3], ['Home', 0]] as [$key, $index]) {
        $page->keyDown($key);
        $page->keyUp($key);
        $browser->assertScript('Array.from(document.querySelectorAll(".menu-link")).indexOf(document.activeElement)', $index);
    }

    if ($width <= 1024) {
        $browser->assertScript('document.querySelector("dialog").contains(document.querySelector(".site-header"))');
        $browser->assertScript('Math.abs(document.querySelector("dialog").getBoundingClientRect().height - innerHeight) < 1');
        $browser->script('document.querySelector(".site-main a").focus()');
        $browser->assertScript('document.querySelector("dialog").contains(document.activeElement)');
        $browser->click('.theme-toggle');
        $browser->assertScript('document.querySelector("dialog").matches(":modal")');
        $browser->script('document.querySelector(".menu-link:last-child").focus()');
        $page->keyDown('Tab');
        $page->keyUp('Tab');
        $browser->assertScript('document.activeElement === document.querySelector(".menu-toggle")');
        $page->keyDown('Shift');
        $page->keyDown('Tab');
        $page->keyUp('Tab');
        $page->keyUp('Shift');
        $browser->assertScript('document.activeElement === document.querySelector(".menu-link:last-child")');
    }
    $page->keyDown('Escape');
    $page->keyUp('Escape');
    $page->waitForSelector('.menu-toggle[aria-expanded="false"]:focus');

    $browser->assertScript('document.querySelector(".site-header").parentElement === document.body');
    $browser->assertScript('document.querySelector("dialog").open', false);
    $browser->assertScript('document.querySelector(".site-navigation").matches(":popover-open")', false);
    $browser->assertScript('document.querySelector(".menu-toggle").getAttribute("aria-label")', 'Open navigation');
    $browser->assertScript('document.documentElement.hasAttribute("data-navigation-modal")', false);
    $browser->assertScript('scrollY', 350);
})->with([390, 768, 1024, 1025, 1440]);

it('cleans up interrupted transitions and page restoration', function (): void {
    $url = $this->publication();
    $pending = visit($url, ['viewport' => ['width' => 390, 'height' => 700]]);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('() => { const b = document.querySelector(".menu-toggle"); b.click(); b.click(); b.click(); }');
    $browser->assertScript('document.querySelector("dialog").matches(":modal")');
    $browser->script('dispatchEvent(new PageTransitionEvent("pagehide", {persisted: true})); dispatchEvent(new PageTransitionEvent("pageshow", {persisted: true}))');
    $browser->assertScript('document.querySelector("dialog").open', false);
    $browser->assertScript('document.querySelectorAll(".site-header").length', 1);
    $browser->assertScript('document.querySelectorAll("#site-navigation").length', 1);
    $browser->assertScript('document.documentElement.hasAttribute("data-navigation-modal")', false);
    $browser->click('.menu-toggle');
    $browser->click('.menu-link[href="/about/"]');
    $browser->assertPathIs('/about/');

    // Pest's load-state wait does not consume its lazy protocol request.
    $page->locator('#navigation-dialog')->waitFor(['state' => 'attached']);
    $browser->assertScript('document.querySelector("dialog").open', false);
});

it('preserves native navigation when JavaScript is disabled', function (int $width): void {
    $url = $this->publication();
    $pending = visit($url, ['javaScriptEnabled' => false, 'viewport' => ['width' => $width, 'height' => 700]]);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->click('.menu-toggle');
    $browser->click('.menu-link[href="/about/"]');
    $browser->assertPathIs('/about/');
})->with([390, 1280]);

it('closes cleanly when crossing the responsive boundary in either direction', function (): void {
    $url = $this->publication();
    $pending = visit($url, ['viewport' => ['width' => 1024, 'height' => 700]]);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    foreach ([1025, 1024] as $width) {
        $browser->click('.menu-toggle');
        $page->setViewportSize($width, 700);
        $page->waitForSelector('.menu-toggle[aria-expanded="false"]');
        $browser->assertScript('document.querySelector("dialog").open', false);
        $browser->assertScript('document.querySelector(".site-navigation").matches(":popover-open")', false);
        $browser->assertScript('document.documentElement.hasAttribute("data-navigation-modal")', false);
    }
});

it('keeps long links scrollable on a short screen and restores after the animated close', function (): void {
    $url = $this->publication();
    $pending = visit($url, ['viewport' => ['width' => 600, 'height' => 280], 'reducedMotion' => 'no-preference']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('document.querySelector(".menu-link").textContent = "Long navigation label ".repeat(15)');
    $browser->click('.menu-toggle');
    $browser->assertScript('document.documentElement.scrollWidth <= innerWidth');
    $browser->assertScript('document.querySelector(".site-navigation").scrollHeight > document.querySelector(".site-navigation").clientHeight');
    $browser->script('document.querySelector(".menu-link:last-child").focus()');
    $browser->assertScript('document.querySelector(".site-navigation").scrollTop > 0');
    $browser->click('.menu-toggle');

    $page->waitForSelector('body > .site-header');
    $browser->assertScript('document.querySelector("dialog").open', false);
    $browser->assertScript('document.activeElement === document.querySelector(".menu-toggle")');
});

it('dismisses the desktop popover without stealing focus from an outside control', function (): void {
    $url = $this->publication();
    $pending = visit($url, ['viewport' => ['width' => 1280, 'height' => 700], 'reducedMotion' => 'reduce']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->click('.menu-toggle');

    $page->waitForSelector('.menu-link:focus');
    $browser->script('document.querySelector(".theme-toggle").focus()');
    $browser->click('.theme-toggle');

    $page->waitForSelector('.menu-toggle[aria-expanded="false"]');
    $browser->assertScript('document.activeElement === document.querySelector(".theme-toggle")');
});

it('morphs the same strokes and honors reduced motion', function (int $width, bool $reduced): void {
    $url = $this->publication();
    $pending = visit($url, ['viewport' => ['width' => $width, 'height' => 700], 'reducedMotion' => $reduced ? 'reduce' : 'no-preference']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $animated = $browser->script(<<<'JS'
        async () => {
            const bar = document.querySelector(".menu-bar-top");
            getComputedStyle(bar).transform;
            document.querySelector(".menu-toggle").click();
            const animations = bar.getAnimations();
            await Promise.all(animations.map(animation => animation.finished));
            return animations.length > 0;
        }
        JS);
    expect($animated)->toBe(!$reduced);
    $browser->assertScript('new DOMMatrix(getComputedStyle(document.querySelector(".menu-bar-top")).transform).b > 0.7');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-bar-middle")).opacity', '0');
    $browser->click('.menu-toggle');
    $browser->script('() => Promise.allSettled(document.getAnimations().map(animation => animation.finished))');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-bar-middle")).opacity', '1');
    $browser->assertScript('document.querySelector("dialog").open', false);
})->with([390, 1280])->with([false, true]);

it('honors the native reduced-transparency preference', function (bool $mobile, string $theme): void {
    $url = $this->publication(<<<'CSS'
        @layer overrides {
            :root {
                --color-header-background: light-dark(#ddeeff, #223344);
                --color-navigation-background: light-dark(#eeeeff, #112233);
                --opacity-header-background: 50%;
                --opacity-navigation-background: 100%;
            }
        }
        CSS);
    $pending = visit($url, ['viewport' => ['width' => $mobile ? 390 : 1280, 'height' => 800], 'hasTouch' => $mobile, 'colorScheme' => $theme, 'reducedMotion' => 'reduce']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('scrollTo(0, 400)');

    $page->waitForSelector('.site-header[data-scrolled]');
    $browser->click('.menu-toggle');
    $this->reducedTransparency($page);
    $browser->assertScript('matchMedia("(prefers-reduced-transparency: reduce)").matches');
    $browser->assertScript('[".menu-toggle", ".menu-link"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['none', 'none']);
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-toggle")).backgroundColor', $theme === 'light' ? 'rgb(238, 227, 216)' : 'rgb(23, 29, 34)');
    $browser->assertScript('getComputedStyle(document.querySelector(".menu-link")).backgroundColor', $theme === 'light' ? 'rgb(238, 227, 216)' : 'rgb(23, 29, 34)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-header")).backgroundColor', $theme === 'light' ? 'rgb(221, 238, 255)' : 'rgb(34, 51, 68)');
    $browser->assertScript('getComputedStyle(document.querySelector(".site-navigation")).backgroundColor', $theme === 'light' ? 'rgb(238, 238, 255)' : 'rgb(17, 34, 51)');
    $browser->assertScript('[".site-header", ".site-navigation"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['none', 'none']);
})->with('theme screens')->with('theme palettes')
    ->skip(fn(): bool => Playwright::defaultBrowserType() !== BrowserType::CHROME, 'Playwright exposes native reduced-transparency emulation only through Chromium CDP.');

it('keeps translucent controls readable without nesting backdrop filters', function (bool $mobile, string $theme): void {
    $url = $this->publication() . '/about/';
    $pending = visit($url, ['viewport' => ['width' => $mobile ? 390 : 1280, 'height' => 800], 'hasTouch' => $mobile, 'colorScheme' => $theme, 'reducedMotion' => 'reduce']);
    $page = $pending->__call('page', []);
    assert($page instanceof Page);
    $browser = new Webpage($page, $url);
    $browser->script('scrollTo(0, 400)');

    $page->waitForSelector('.site-header[data-scrolled]');
    $browser->click('.menu-toggle');
    $helper = file_get_contents(__DIR__ . '/contrast.js');
    assert(is_string($helper));
    $browser->script($helper);
    $browser->assertScript('[".site-header", ".site-navigation"].map(s => getComputedStyle(document.querySelector(s)).backdropFilter)', ['saturate(1.4) blur(16px)', 'saturate(1.4) blur(16px)']);
    foreach (['.menu-toggle', '.menu-link:not([aria-current])'] as $selector) {
        $this->assertSrgb($browser, $selector, $theme === 'light' ? [0.933333, 0.890196, 0.847059, 0.9] : [0.0901961, 0.113725, 0.133333, 0.9]);
        $browser->assertScript('getComputedStyle(document.querySelector(' . json_encode($selector, flags: JSON_THROW_ON_ERROR) . ')).backdropFilter', 'none');
    }
    // These extremes bound every sRGB backdrop, independently of page content and blur.
    $browser->assertScript('[".menu-toggle", ".menu-link:not([aria-current])", "[aria-current=page]"].every(s => [[0, 0, 0], [1, 1, 1]].every(bg => themeContrast(s, "color", s, "backgroundColor", bg) >= 4.5))');
    $browser->assertScript('getComputedStyle(document.querySelector("[aria-current=page]")).backdropFilter', 'none');
})->with('theme screens')->with('theme palettes');
