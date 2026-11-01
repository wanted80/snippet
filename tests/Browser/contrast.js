// oxlint-disable-next-line no-unused-expressions -- Pest evaluates this function as browser source.
() => {
  // Convert computed sRGB colors to relative luminance, including color-mix output and an explicit backdrop for translucent fills.
  /** @param {string} foreground @param {string} property @param {string} background @param {string} backgroundProperty @param {number[]} backdrop */
  const themeContrast = (foreground, property, background, backgroundProperty, backdrop = [1, 1, 1]) => {
    /** @param {string} selector @param {string} name */
    const channels = (selector, name) => {
      const element = document.querySelector(selector);
      if (element === null) throw new Error(`Missing ${selector}`);
      const color = getComputedStyle(element).getPropertyValue(
        name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`),
      );
      const values = (color.match(/[\d.]+/g) ?? []).map(Number);
      return {
        rgb: values.slice(0, 3).map((value) => (color.startsWith("color(") ? value : value / 255)),
        alpha: values[3] ?? 1,
      };
    };
    /** @param {number[]} rgb */
    const luminance = (rgb) =>
      rgb
        .map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4))
        .reduce((sum, value, index) => sum + value * ([0.2126, 0.7152, 0.0722][index] ?? 0), 0);
    const fill = channels(background, backgroundProperty);
    const a = luminance(channels(foreground, property).rgb);
    const b = luminance(
      fill.rgb.map((value, index) => value * fill.alpha + (backdrop[index] ?? 0) * (1 - fill.alpha)),
    );
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  };
  Object.assign(window, { themeContrast });
};
