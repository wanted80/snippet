# Make the theme yours

Your stylesheet is `site/site.css`. The builder loads it after the bundled theme, so you can change colors, fonts, spacing, and responsive layout while keeping the installed templates.

Start with tokens in the final CSS layer:

```css
@layer overrides {
  :root {
    --color-accent: light-dark(#174b91, #b8d5ff);
    --color-on-accent: light-dark(#ffffff, #111827);
    --font-reading: system-ui, sans-serif;
    --measure-prose: 42rem;
    --radius-control: 0.5rem;
  }
}
```

`light-dark()` supplies values for both themes. Visitors start with their system preference and can change it with the header control. Check your overrides in both modes, on narrow screens, and with keyboard navigation.

## A custom header font, or your own

The starter ships Snippet Logo, a custom font created specifically for Snippet's header wordmark. Reading and interface text use the self-hosted Atkinson Hyperlegible Next family. These fonts are served with your static files, without a font-service request.

The header displays `sitename`; your full document `title` is separate. Snippet Logo covers Latin letters, numbers, and selected punctuation, with unsupported characters falling back to the interface font. Keep the header name short: the default layout uses one line and clips names that exceed the available width. It scales with screen width, rather than fitting arbitrary text lengths.

You can use the interface font for the header instead:

```css
@layer overrides {
  :root {
    --font-wordmark: var(--font-interface);
  }
}
```

Or add your own font files under `site/assets/`, declare them with `@font-face` in `site/site.css`, and set `--font-reading`, `--font-interface`, or `--font-wordmark` to your chosen family. The starter's branding is yours to customize.

## Ask an agent to style the site

With the bundled snippet-authoring skill, your coding agent can inspect the installed theme contract and edit the same stylesheet. Ask for a specific result: a warmer palette, larger reading text, more compact spacing, a different header font, or solid navigation controls. Have it validate and build, then review both themes and screen sizes. [The agent guide](/articles/working-with-agents/) includes requests you can adapt.

## Glass that keeps the content readable

The scrolled header and navigation panel use translucent fills with backdrop blur. Header control highlights and ordinary navigation items use denser translucent fills over those surfaces, keeping icons and text clear. Only the header and panel apply blur; controls do not add nested backdrop filters. Selected and hovered or pressed menu items use a solid accent fill; idle header controls stay transparent. The fullscreen mobile navigation retains an opaque backdrop.

Existing component colors still apply. Two independent opacity tokens control the new control fills, with `90%` defaults. To keep the previous solid controls:

```css
@layer overrides {
  :root {
    --opacity-header-button-background: 100%;
    --opacity-navigation-item-background: 100%;
  }
}
```

Header and panel opacity remain separate: `--opacity-header-background` and `--opacity-navigation-background` both default to `82%`. Their color tokens use the surface color; control color tokens use the interactive color. Unsupported browsers and reduced-transparency preferences receive solid base colors without blur. The effect never lowers text opacity or animates the blur.

Use opaque base colors. Lowering a fill's opacity lets the background influence contrast, so check the composed result over actual content. Set `--color-on-accent` to contrast with your accent in both themes. You can remove shadows with `--shadow-header`, `--shadow-menu`, and `--shadow-content` set to `none`.

## Stable places to customize

The published layer order is `reset`, `tokens`, `base`, `layout`, `components`, `overrides`. Public class hooks include `.site-header`, `.site-navigation`, `.site-main`, `.prose`, `.article-figure`, and `.site-footer`. Put class overrides in `@layer overrides`; you do not need `!important` for the normal screen rules.

Use the [complete CSS API reference](https://github.com/wanted80/snippet#stable-css-api) for the token and hook lists. A coding agent can inspect the same contract through `inspect theme --json`.

## What belongs to the builder

HTML templates, base CSS, and theme JavaScript stay in the installed image. There are no template overrides. Your site configuration, custom CSS, optional JavaScript, favicon, and assets stay in your repository. Builds never replace them.

Pin the image version for repeatable results. When you upgrade, preview the same content with your custom CSS before publishing. Public tokens, hooks, and layer order are preserved across patch and minor releases; default visual details can improve.

Next: [Preview and validation](/articles/preview-and-validation/).
