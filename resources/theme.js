(() => {
  const storageKey = "snippet-theme";
  const root = document.documentElement;
  const system = window.matchMedia("(prefers-color-scheme: light)");
  const systemTheme = () => (system.matches ? "light" : "dark");
  let preference = null;
  let storage = null;
  let themeChangeSequence = 0;

  try {
    storage = localStorage;
    const stored = storage.getItem(storageKey);
    if (stored === "light" || stored === "dark") {
      preference = stored;
    }
  } catch {
    preference = null;
    storage = null;
  }

  const initialTheme = preference ?? systemTheme();
  root.dataset.theme = initialTheme;
  const themeColor = document.querySelector('meta[name="theme-color"]');
  const syncThemeColor = () => {
    themeColor?.setAttribute("content", window.getComputedStyle(root).backgroundColor);
  };
  syncThemeColor();

  const initializeHeader = () => {
    const header = document.querySelector("[data-site-header]");
    if (header === null) return;
    const sync = () => {
      if (!root.hasAttribute("data-navigation-modal")) {
        header.toggleAttribute("data-scrolled", window.scrollY > 0);
      }
    };
    window.addEventListener("scroll", sync, { passive: true });
    window.addEventListener("pageshow", sync);
    sync();
  };

  const initializeNavigation = () => {
    const header = document.querySelector("[data-site-header]");
    const button = document.querySelector(".menu-toggle");
    const navigation = document.querySelector("#site-navigation");
    const dialog = document.querySelector("#navigation-dialog");
    if (!header || !button || !navigation || !dialog ||
        typeof navigation.showPopover !== "function" ||
        typeof dialog.showModal !== "function") return;

    const compact = window.matchMedia("(max-width: 1024px)");
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const links = Array.from(navigation.querySelectorAll(".menu-link"));
    const placeholder = document.createElement("div");
    placeholder.setAttribute("aria-hidden", "true");
    let savedScroll = 0;
    let closing = null;
    let opening = null;
    let positionFrame = 0;

    const sync = (open) => {
      const label = open ? "Close navigation" : "Open navigation";
      button.setAttribute("aria-expanded", String(open));
      button.setAttribute("aria-label", label);
      button.setAttribute("title", label);
    };
    const position = () => {
      positionFrame = 0;
      const top = Math.max(8, header.getBoundingClientRect().bottom + 8);
      const width = navigation.getBoundingClientRect().width;
      const left = Math.max(16, Math.min(button.getBoundingClientRect().left, root.clientWidth - width - 16));
      navigation.style.setProperty("--navigation-top", `${top}px`);
      navigation.style.setProperty("--navigation-left", `${left}px`);
    };
    const schedulePosition = () => {
      if (navigation.matches(":popover-open") && !positionFrame) {
        positionFrame = window.requestAnimationFrame(position);
      }
    };
    const finishClose = (restoreFocus) => {
      opening?.cancel();
      opening = null;
      closing?.cancel();
      closing = null;
      if (dialog.open) dialog.close();
      if (placeholder.isConnected) {
        placeholder.replaceWith(header);
        header.append(navigation);
        navigation.setAttribute("popover", "auto");
        button.setAttribute("popovertarget", "site-navigation");
        button.setAttribute("aria-controls", "site-navigation");
        button.removeAttribute("aria-haspopup");
        root.removeAttribute("data-navigation-modal");
        root.style.removeProperty("--navigation-scroll");
        window.scrollTo({ top: savedScroll, behavior: "instant" });
        header.toggleAttribute("data-scrolled", savedScroll > 0);
      }
      sync(false);
      if (restoreFocus) button.focus({ preventScroll: true });
    };
    const close = (restoreFocus = true, animate = true) => {
      if (dialog.open) {
        if (closing && animate) return;
        const opacity = window.getComputedStyle(navigation).opacity;
        opening?.cancel();
        opening = null;
        sync(false);
        if (animate && !reducedMotion.matches) {
          closing = navigation.animate([{ opacity }, { opacity: 0 }], { duration: 200, easing: "ease-out" });
          const animation = closing;
          animation.finished.then(() => {
            if (closing === animation) finishClose(restoreFocus);
          }, () => {});
        } else {
          finishClose(restoreFocus);
        }
      } else if (navigation.matches(":popover-open")) {
        navigation.hidePopover();
        sync(false);
        if (restoreFocus) button.focus({ preventScroll: true });
      }
    };
    const openModal = () => {
      savedScroll = window.scrollY;
      placeholder.style.height = `${header.getBoundingClientRect().height}px`;
      header.before(placeholder);
      root.style.setProperty("--navigation-scroll", `${-savedScroll}px`);
      root.setAttribute("data-navigation-modal", "");
      navigation.removeAttribute("popover");
      button.removeAttribute("popovertarget");
      button.setAttribute("aria-controls", "navigation-dialog");
      button.setAttribute("aria-haspopup", "dialog");
      dialog.append(header, navigation);
      dialog.showModal();
      // Establish the bars' initial geometry after moving into the top layer.
      button.getBoundingClientRect();
      sync(true);
      if (!reducedMotion.matches) {
        opening = navigation.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160, easing: "ease-out" });
      }
      links[0]?.focus({ preventScroll: true });
    };

    button.addEventListener("click", (event) => {
      if (!compact.matches) return;
      event.preventDefault();
      if (closing) {
        closing.cancel();
        closing = null;
        sync(true);
      } else if (dialog.open) {
        close();
      } else {
        openModal();
      }
    });
    navigation.addEventListener("beforetoggle", (event) => {
      if (dialog.open) return;
      sync(event.newState === "open");
    });
    navigation.addEventListener("toggle", () => {
      if (dialog.open) return;
      const open = navigation.matches(":popover-open");
      sync(open);
      if (open) {
        position();
        links[0]?.focus({ preventScroll: true });
      }
    });
    navigation.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !dialog.open) {
        event.preventDefault();
        close();
        return;
      }
      const current = links.indexOf(document.activeElement);
      if (current === -1 || event.altKey || event.ctrlKey || event.metaKey) return;
      const next = { ArrowDown: (current + 1) % links.length,
        ArrowUp: (current - 1 + links.length) % links.length,
        Home: 0, End: links.length - 1 }[event.key];
      if (next === undefined) return;
      event.preventDefault();
      links[next]?.focus();
    });
    dialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      close();
    });
    dialog.addEventListener("keydown", (event) => {
      if (event.key !== "Tab") return;
      // Keep the boundary keys inside the modal instead of visiting browser chrome.
      const controls = Array.from(dialog.querySelectorAll("button, a[href]"))
        .filter((control) => !control.disabled && control.getClientRects().length > 0);
      const first = controls[0];
      const last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
    });
    dialog.addEventListener("close", () => {
      if (!dialog.open && placeholder.isConnected) finishClose(true);
    });
    document.addEventListener("click", (event) => {
      if (!event.defaultPrevented && event.target.closest?.("a") &&
          (navigation.contains(event.target) || dialog.contains(event.target))) {
        close(false, false);
      }
    });
    compact.addEventListener("change", () => close(true, false));
    reducedMotion.addEventListener("change", () => {
      if (reducedMotion.matches) {
        opening?.cancel();
        opening = null;
        if (closing) finishClose(true);
      }
    });
    window.addEventListener("pagehide", () => close(false, false));
    window.addEventListener("pageshow", () => close(false, false));
    window.addEventListener("beforeprint", () => close(false, false));
    window.addEventListener("resize", schedulePosition, { passive: true });
    window.addEventListener("scroll", schedulePosition, { passive: true });
    sync(false);
  };

  const initializeTheme = () => {
    const themeButton = document.querySelector("[data-theme-toggle]");
    const apply = (theme, persist) => {
      if (root.dataset.theme !== theme) {
        root.dataset.themeChanging = "true";
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
      const label = theme === "dark" ? "Use light theme" : "Use dark theme";
      themeButton?.setAttribute("aria-label", label);
      themeButton?.setAttribute("title", label);
      syncThemeColor();
      if (persist && storage !== null) {
        try {
          storage.setItem(storageKey, theme);
        } catch {
          storage = null;
          // Keep this page’s choice, including when restored from the back/forward cache.
        }
      }
    };

    apply(initialTheme, false);
    system.addEventListener("change", () => {
      if (preference === null) {
        apply(systemTheme(), false);
      }
    });
    window.addEventListener("storage", (event) => {
      if (
        storage === null ||
        event.storageArea !== storage ||
        (event.key !== storageKey && event.key !== null)
      ) {
        return;
      }

      const stored = event.key === storageKey ? event.newValue : null;
      preference = stored === "light" || stored === "dark" ? stored : null;
      apply(preference ?? systemTheme(), false);
    });
    window.addEventListener("pageshow", (event) => {
      if (!event.persisted) return;
      if (storage !== null) {
        try {
          const stored = storage.getItem(storageKey);
          preference = stored === "light" || stored === "dark" ? stored : null;
        } catch {
          // Retain the in-memory choice when storage becomes unavailable.
        }
      }
      apply(preference ?? systemTheme(), false);
    });
    themeButton?.addEventListener("click", () => {
      preference = root.dataset.theme === "dark" ? "light" : "dark";
      apply(preference, true);
    });
  };

  const initialize = () => {
    initializeTheme();
    initializeHeader();
    initializeNavigation();
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  } else {
    initialize();
  }
})();
