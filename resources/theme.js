(() => {
    const storageKey = 'snippet-theme';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: light)');
    const colors = { light: '#f7f1e8', dark: '#08090a' };
    let preference = null;
    let themeChangeFrame = 0;

    try {
        const stored = localStorage.getItem(storageKey);
        if (stored === 'light' || stored === 'dark') {
            preference = stored;
        }
    } catch {
        preference = null;
    }

    const initialTheme = preference ?? (system.matches ? 'light' : 'dark');
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

            syncScrollState();
            window.addEventListener('scroll', syncScrollState, { passive: true });
            window.addEventListener('pageshow', syncScrollState);
        }

        if (menuButton !== null && navigation !== null) {
            const links = Array.from(navigation.querySelectorAll('.menu-link'));

            navigation.addEventListener('toggle', (event) => {
                const open = event.newState === 'open';
                const label = open ? 'Close navigation' : 'Open navigation';
                menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
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

        if (themeButton === null || themeColor === null) {
            return;
        }

        const apply = (theme, persist) => {
            root.dataset.themeChanging = 'true';
            root.dataset.theme = theme;
            const label = theme === 'dark' ? 'Use light theme' : 'Use dark theme';
            themeButton.setAttribute('aria-label', label);
            themeButton.setAttribute('title', label);
            themeColor.setAttribute('content', colors[theme]);
            const frame = window.requestAnimationFrame(() => {
                if (themeChangeFrame === frame) {
                    delete root.dataset.themeChanging;
                }
            });
            themeChangeFrame = frame;
            if (persist) {
                try {
                    localStorage.setItem(storageKey, theme);
                } catch {
                    // The selected theme still applies for this page.
                }
            }
        };

        apply(initialTheme, false);
        system.addEventListener('change', (event) => {
            if (preference === null) {
                apply(event.matches ? 'light' : 'dark', false);
            }
        });
        themeButton.addEventListener('click', () => {
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