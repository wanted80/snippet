(() => {
    const storageKey = 'snippet-theme';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: light)');
    const colors = { light: '#f7f1e8', dark: '#08090a' };
    const systemTheme = () => system.matches ? 'light' : 'dark';
    let preference = null;
    let storage = null;
    let themeChangeSequence = 0;

    try {
        storage = localStorage;
        const stored = storage.getItem(storageKey);
        if (stored === 'light' || stored === 'dark') {
            preference = stored;
        }
    } catch {
        preference = null;
        storage = null;
    }

    const initialTheme = preference ?? systemTheme();
    root.dataset.theme = initialTheme;
    const themeColor = document.querySelector('meta[name="theme-color"]');
    themeColor?.setAttribute('content', colors[initialTheme]);

    const initialize = () => {
        const header = document.querySelector('[data-site-header]');
        const menuButton = document.querySelector('[popovertarget="site-navigation"]');
        const navigation = document.querySelector('#site-navigation');
        const themeButton = document.querySelector('[data-theme-toggle]');

        if (header !== null) {
            let scrolled = false;
            const syncScrollState = () => {
                const nextScrolled = window.scrollY > 0;
                if (nextScrolled === scrolled) {
                    return;
                }

                scrolled = nextScrolled;
                header.toggleAttribute('data-scrolled', scrolled);
            };

            window.addEventListener('scroll', syncScrollState, { passive: true });
            window.addEventListener('pageshow', syncScrollState);
            syncScrollState();
        }

        if (menuButton !== null && navigation !== null) {
            const links = Array.from(navigation.querySelectorAll('.menu-link'));

            navigation.addEventListener('toggle', (event) => {
                const open = event.newState === 'open';
                const label = open ? 'Close navigation' : 'Open navigation';
                menuButton.setAttribute('aria-label', label);
                menuButton.setAttribute('title', label);
                if (open) {
                    links[0]?.focus();
                }
            });

            navigation.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    navigation.hidePopover();
                    menuButton.focus();
                    return;
                }

                const current = links.indexOf(document.activeElement);
                if (current === -1) {
                    return;
                }

                let next = null;
                switch (event.key) {
                    case 'ArrowDown':
                        next = (current + 1) % links.length;
                        break;
                    case 'ArrowUp':
                        next = (current - 1 + links.length) % links.length;
                        break;
                    case 'Home':
                        next = 0;
                        break;
                    case 'End':
                        next = links.length - 1;
                        break;
                    default:
                        return;
                }

                event.preventDefault();
                links[next].focus();
            });
        }

        const apply = (theme, persist) => {
            if (root.dataset.theme !== theme) {
                root.dataset.themeChanging = 'true';
                root.dataset.theme = theme;
                const sequence = ++themeChangeSequence;
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        if (themeChangeSequence === sequence) {
                            delete root.dataset.themeChanging;
                        }
                    });
                });
            }
            const label = theme === 'dark' ? 'Use light theme' : 'Use dark theme';
            themeButton?.setAttribute('aria-label', label);
            themeButton?.setAttribute('title', label);
            themeColor?.setAttribute('content', colors[theme]);
            if (persist && storage !== null) {
                try {
                    storage.setItem(storageKey, theme);
                } catch {
                    // The selected theme still applies for this page.
                }
            }
        };

        apply(initialTheme, false);
        system.addEventListener('change', () => {
            if (preference === null) {
                apply(systemTheme(), false);
            }
        });
        window.addEventListener('storage', (event) => {
            if (storage === null || event.storageArea !== storage || (event.key !== storageKey && event.key !== null)) {
                return;
            }

            const stored = event.key === storageKey ? event.newValue : null;
            preference = stored === 'light' || stored === 'dark' ? stored : null;
            apply(preference ?? systemTheme(), false);
        });
        themeButton?.addEventListener('click', () => {
            preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
            apply(preference, true);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
