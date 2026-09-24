import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const script = readFileSync(process.env.SNIPPET_THEME_SCRIPT ?? new URL('../../resources/theme.js', import.meta.url), 'utf8');

function page({ stored = null, controls = true, metadata = true, blockedStorage = false, light = false, loading = false } = {}) {
    const root = { dataset: {} };
    const button = new EventTarget();
    const color = { content: null, setAttribute(name, value) { this[name] = value; } };
    const media = new EventTarget();
    media.matches = light;
    button.setAttribute = (name, value) => { button[name] = value; };
    const storage = {
        value: stored,
        getItem() {
            if (blockedStorage) throw new Error('Storage is unavailable');
            return this.value;
        },
        setItem(key, value) { this.value = value; },
    };
    const window = new EventTarget();
    window.matchMedia = () => media;
    const backgrounds = { light: 'rgb(250, 240, 230)', dark: 'rgb(10, 20, 30)' };
    window.getComputedStyle = () => ({ backgroundColor: backgrounds[root.dataset.theme] });
    const frames = [];
    window.requestAnimationFrame = callback => frames.push(callback);
    const document = {
        documentElement: root,
        readyState: loading ? 'loading' : 'complete',
        addEventListener() {},
        querySelector(selector) {
            if (selector === '[data-theme-toggle]') return controls ? button : null;
            if (selector === 'meta[name="theme-color"]') return metadata ? color : null;
            return null;
        },
    };
    runInNewContext(script, { document, window, localStorage: storage });

    return {
        root, button, color, storage,
        system(light) {
            media.matches = light;
            media.dispatchEvent(Object.assign(new Event('change'), { matches: light }));
        },
        clearPreference() {
            window.dispatchEvent(Object.assign(new Event('storage'), { storageArea: storage, key: null, newValue: null }));
        },
        flushFrames() {
            while (frames.length > 0) frames.shift()();
        },
    };
}

for (const options of [{ controls: false }, { metadata: false }, { controls: false, metadata: false }]) {
    test(`system theme updates with optional layout elements ${JSON.stringify(options)}`, () => {
        const view = page(options);
        assert.equal(view.root.dataset.theme, 'dark');
        view.system(true);
        assert.equal(view.root.dataset.theme, 'light');
        view.system(false);
        assert.equal(view.root.dataset.theme, 'dark');
    });
}

test('manual preference persists until cleared in another tab', () => {
    const view = page();
    view.button.dispatchEvent(new Event('click'));
    assert.equal(view.root.dataset.theme, 'light');
    assert.equal(view.storage.value, 'light');
    assert.equal(view.button['aria-label'], 'Use dark theme');
    assert.equal(view.color.content, 'rgb(250, 240, 230)');
    view.system(false);
    assert.equal(view.root.dataset.theme, 'light');
    view.clearPreference();
    assert.equal(view.root.dataset.theme, 'dark');
    view.flushFrames();
    assert.equal(view.root.dataset.themeChanging, undefined);
});

test('unavailable storage leaves system following and manual controls usable', () => {
    const view = page({ blockedStorage: true });
    view.system(true);
    assert.equal(view.root.dataset.theme, 'light');
    view.button.dispatchEvent(new Event('click'));
    assert.equal(view.root.dataset.theme, 'dark');
});

test('a saved preference takes precedence over the system', () => {
    const view = page({ stored: 'light' });
    view.system(false);
    assert.equal(view.root.dataset.theme, 'light');
});

for (const light of [false, true]) {
    test(`initial computed color is available before DOMContentLoaded (light=${light})`, () => {
        const view = page({ light, loading: true });
        assert.equal(view.color.content, light ? 'rgb(250, 240, 230)' : 'rgb(10, 20, 30)');
        const saved = page({ light, stored: light ? 'dark' : 'light', loading: true });
        assert.equal(saved.color.content, light ? 'rgb(10, 20, 30)' : 'rgb(250, 240, 230)');
    });
}

test('computed colors follow system changes and cleared manual preferences', () => {
    const view = page();
    view.system(true);
    assert.equal(view.color.content, 'rgb(250, 240, 230)');
    view.button.dispatchEvent(new Event('click'));
    assert.equal(view.color.content, 'rgb(10, 20, 30)');
    view.clearPreference();
    assert.equal(view.color.content, 'rgb(250, 240, 230)');
});
