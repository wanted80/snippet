() => {
// Convert computed sRGB colors to relative luminance, including color-mix output.
window.themeContrast = (foreground, property, background, backgroundProperty) => {
    const channels = (selector, name) => {
        const color = getComputedStyle(document.querySelector(selector))[name];
        const values = color.match(/[\d.]+/g).map(Number);
        return values.slice(0, 3).map(value => color.startsWith('color(') ? value : value / 255);
    };
    const luminance = rgb => rgb.map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)
        .reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
    const a = luminance(channels(foreground, property));
    const b = luminance(channels(background, backgroundProperty));
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
};

}
