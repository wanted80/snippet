// oxlint-disable-next-line no-unused-expressions -- Pest evaluates this function as browser source.
() => {
  // Convert computed sRGB colors to relative luminance, including color-mix output.
  /** @param {string} foreground @param {string} property @param {string} background @param {string} backgroundProperty */
  const themeContrast = (foreground, property, background, backgroundProperty) => {
    /** @param {string} selector @param {string} name */
    const channels = (selector, name) => {
      const element = document.querySelector(selector);
      if (element === null) throw new Error(`Missing ${selector}`);
      const color = getComputedStyle(element).getPropertyValue(
        name.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`),
      );
      const values = (color.match(/[\d.]+/g) ?? []).map(Number);
      return values.slice(0, 3).map((value) => (color.startsWith("color(") ? value : value / 255));
    };
    /** @param {number[]} rgb */
    const luminance = (rgb) =>
      rgb
        .map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4))
        .reduce((sum, value, index) => sum + value * ([0.2126, 0.7152, 0.0722][index] ?? 0), 0);
    const a = luminance(channels(foreground, property));
    const b = luminance(channels(background, backgroundProperty));
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  };
  Object.assign(window, { themeContrast });
};
