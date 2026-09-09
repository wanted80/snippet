<p align="center">
  <img src="site/favicon.svg" alt="Snippet logo" width="160" height="160">
</p>

# Snippet

Snippet is a small, dependency-free PHP 8.5+ publishing system for one author. It turns self-contained Markdown content directories into a completely static website. This repository contains the generator and its canonical defaults; the public example site lives separately under `demo/`.

Snippet provides:

- strict configuration, metadata, content, asset, and template validation;
- deterministic article, page, tag, and index routes;
- transactional publication that preserves the last valid `public/` on failure;
- a live-reloading local preview;
- semantic HTML, customizable plain CSS, and light/dark themes; and
- no third-party runtime PHP packages.

## Why I created Snippet

I created Snippet because I wanted a publishing system that met my own needs without relying on a third-party tool. It was also a perfect opportunity to explore how AI can turn an idea into a working project. I did not write a single line of code myself; instead, I contributed ideas, suggestions, part of my knowledge, and the guidance needed to lead the agent through the project.

I have been working with AI for more than a year, and I believe now is the perfect time to use it as a partner. It makes it possible to build things that were previously out of reach outside our professional work—not because the ideas were missing, but because there was never enough time or more than two hands to do everything. It is also a great time to create more open-source projects driven by our own ideas and needs.

I mainly used GPT 5.6 Sol with medium and xhigh reasoning, and GPT 5.6 Luna with high and xhigh reasoning for smaller tasks. Sometimes I also used subchats in Codex, asking Luna to work within Sol's session and vice versa.

## Quick start

The primary workflow is a content-only repository powered by the official builder image. Start in an empty directory with the pinned release:

```bash
mkdir my-site
cd my-site

docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v3.1.0 init # x-release-please-version
```

`--user` prevents root-owned output, while `--volume` exposes the current repository at the image's `/workspace` path. Edit the generated `site/config.php` and content, then rerun the command with `init` replaced by `validate` or `build`. Create later drafts through the same image, for example by replacing `init` with `new article first-post`.

The publication inputs are `content/` and `site/`; `public/` is disposable output. Initialization also adds author-owned `AGENTS.md` and `.agents/skills/snippet-authoring/SKILL.md` for coding agents. The builder image supports `--version`, `inspect`, `init`, `new page`, `new article`, `validate`, `build`, and `preview`. Run the local development preview from a content-only repository with an explicitly published loopback port:

```bash
docker run --rm --init \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges \
  --pids-limit 64 \
  --cpus 2 \
  --user "$(id -u):$(id -g)" \
  --publish 127.0.0.1:8080:8080 \
  --volume "$PWD:/workspace" \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
  ghcr.io/wanted80/snippet:v3.1.0 preview --host=0.0.0.0 --port=8080 # x-release-please-version
```

Preview is for local authoring only; production deployment still consists solely of the generated `public/` directory. See [INSTALL.md](INSTALL.md) for hardened raw-Docker and Compose preview examples, building another directory, direct PHP use, the contributor HTTPS preview, customization, CI, and deployment.

For a full checkout used to develop Snippet itself, Docker with Make remains the recommended environment:

```bash
git clone https://github.com/wanted80/snippet.git
cd snippet
cp .env.example .env
make demo-check
```

This builds the release image, assembles the demo in a temporary workspace, validates it, and proves the production build. [INSTALL.md](INSTALL.md) documents both official-image preview for content-only publications and direct preview from a full checkout.

For contributor preview, run `make docker-preview` and open `https://localhost:8443/`. Docker exposes `demo/content/` as the CLI content collection and uses the canonical root `site/` and `resources/`, with live updates as those files change.

## Agent CLI

Agents can query the installed engine before a workspace exists:

```text
bin/snippet inspect capabilities --json
bin/snippet inspect theme --json
bin/snippet inspect config --json
bin/snippet inspect content --json
```

Inspection requires `--json`. The four subjects describe available commands and
customization paths, the stable theme API and `engine_defaults`, required site
configuration and `starter_values`, and the page/article authoring contract.
Inspection reads installed engine resources, independently of workspace files.
Theme results contain the 32 public tokens grouped into colors, fonts, sizing, and effects,
11 class hooks, layer order, and an override example. Values preserve CSS
expressions; they exclude author CSS and print overrides. Configuration starter
values are required values copied by initialization, not defaults for omissions.

| Command | JSON result |
| --- | --- |
| `--version --json` | Schema and installed version |
| `inspect <capabilities\|theme\|config\|content> --json` | Installed contract |
| `init --json` | Created and skipped files; Docker entrypoint only |
| `new page <slug> --json` | Created files and `incomplete: true` |
| `new article <slug> [--date=YYYY-MM-DD] --json` | Created files and `incomplete: true`; omitted date uses UTC |
| `validate --json` | `valid: true` and article, page, tag, asset counts |
| `build --json` | `output: "public/"`, article, page, tag, asset, file counts, and any cleanup warning |

Every JSON response is one compact object followed by a newline on stdout, with
`schema: "snippet.agent/v1"` and the release-managed `snippet_version`. Normal
human output remains available without `--json`. Pass the option once after the
command's required arguments, before or after other supported tail options.
Duplicate, misplaced, and unsupported options fail before an operation starts.
`preview --json` returns a usage error; normal preview remains interactive.

Exit statuses are `0` for success, `1` for an operation failure, and `2` for invalid
usage. Handled JSON failures contain `error: {code, message}` with
`cli.invalid_arguments`, `init.failed`, `new.failed`, `inspect.failed`,
`validate.failed`, or `build.failed`. Messages retain source context; their text
is not a structured field contract. JSON contains no human diagnostics or ANSI
decoration. Invalid UTF-8 in diagnostics is replaced with the Unicode replacement
character so the response remains valid JSON.

Successful inspection and validation are deterministic. JSON build results omit
duration; `warnings` appears only when warnings exist. A successful publication
with a backup-cleanup warning still exits `0`. `created` and `skipped` paths are
workspace-relative files; new-content success means that incomplete source files
were created, not that the publication is valid.

The complete workflow is **inspect → initialize → create → edit → validate →
build**. Read and edit author files directly, complete generated Markdown and
metadata, and customize `site/site.css` through the documented API. Validate and
build with Snippet, then review the appearance in light and dark modes. See
[the agent workflow in INSTALL.md](INSTALL.md#agent-workflow) for Docker commands.

### Agent instructions and skill

`init` adds a short `AGENTS.md` that points to the bundled `snippet-authoring`
skill in `.agents/skills/snippet-authoring/SKILL.md`. The skill discovers the
installed contracts and guides content, configuration, styling, validation, and
building. Record the workspace's actual command or pinned image in its project
instructions so the agent can reuse it. For example:

> Use the snippet-authoring skill to add an About page in my existing writing
> style, then validate and build the site using the configured Snippet command.

Both files belong to the author after initialization. Rerunning `init --json`
on an older workspace adds missing files and reports existing ones as `skipped`.
It does not append to or replace an existing `AGENTS.md`, customized Snippet
skill, or other skills. Existing instructions can opt in with this reference:

```markdown
For Snippet publication work, read `.agents/skills/snippet-authoring/SKILL.md`.
```

Keep these instructions in the publication repository; they are not included in
`public/`. See [upgrading agent guidance](INSTALL.md#agent-guidance-in-existing-workspaces)
for discovery and upgrade details. The generator repository's own `AGENTS.md`
continues to describe engine development.

## Content

Pages and articles occupy separate source collections. Each leaf directory is one content item, and its directory name is its URL slug:

```text
content/
├── pages/
│   └── <slug>/
│       ├── page.md
│       ├── meta.php
│       └── optional assets
└── articles/
    └── YYYY/MM/DD/<slug>/
        ├── article.md
        ├── meta.php
        └── optional assets
```

Article directories must use a real, zero-padded calendar date that matches the metadata date. The source hierarchy keeps archives manageable but does not change public URLs: articles use `/articles/<slug>/`, while pages use `/<slug>/`. The slugs `articles`, `assets`, `pages`, and `tags` are reserved.

Page and article directory slugs are author-chosen lowercase ASCII letters and numbers separated by single hyphens. Titles are independent and may use any language: a page titled `日本語`, for example, can use the directory slug `nihongo`. Snippet never transliterates a title automatically because pronunciation and convention are language-dependent.

Start a page or article with the minimal draft command in an initialized publication workspace:

```bash
snippet new page contact
snippet new article first-post
snippet new article older-note --date=2026-07-01
```

Content-only repositories use the equivalent builder-image command:

```bash
docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v3.1.0 new article first-post # x-release-please-version
```

An article without `--date` uses the current UTC date. The command creates an empty Markdown file and a `meta.php` with empty title and description fields; articles also receive the selected date and an empty tag list. The result is intentionally incomplete, so finish both files before validating, building, or previewing. Draft creation checks that the relevant content collection is a regular non-symlink directory, does not validate the existing catalog or change `public/`, and never replaces an existing content directory. Run `snippet init` first when using an empty content-only workspace. Contributors can also run the canonical command after entering `make docker-shell`; there is deliberately no separate Make target.

Every `meta.php` starts with `declare(strict_types=1);` and returns one literal associative array. Article metadata has these required fields:

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Example article',
    'description' => 'A short description used by indexes and metadata.',
    'date' => '2026-08-02',
    'tags' => [
        'PHP 8.5',
        'Writing',
    ],
];
```

Articles may opt into one cover stored at the root of the same article directory:

```php
'cover' => true,
'alt' => 'A descriptive text alternative.',
```

`cover` is optional and defaults to `false`. When it is `true`, exactly one file named `cover.jpg`, `cover.png`, or `cover.webp` must exist directly in the article directory. The builder detects the format, requires it to match the filename, and derives the intrinsic dimensions for the generated markup. A missing, corrupt, mismatched, or ambiguous cover fails validation.

`alt` is optional and may be used only with an enabled cover. When present it must be trimmed non-empty text within the description-length limit; when omitted the generated image uses `alt=""`. Pages reject `cover` and `alt`, and Markdown image syntax remains unsupported.

The original cover bytes are copied unchanged. The figure appears only on the canonical article page and when that article is featured in full on the homepage; archive cards and tag pages omit it. `.article-figure` is the stable styling hook, and `resources/templates/article-figure.html` owns its semantic markup.

Page metadata requires only `title` and `description`. It may also define a positive, site-unique `menu_order` to enter the primary navigation:

```php
<?php

declare(strict_types=1);

return [
    'title' => 'About',
    'description' => 'About this site.',
    'menu_order' => 1,
];
```

No more than four pages may define `menu_order` under the internal navigation ceiling. Every page appears at `/pages/` whether or not it is in the direct navigation.

The demo keeps `demo/content/pages/about/` as a conventional permanent About page and promotes it with `menu_order`. It remains an ordinary page; initialized publications start with empty page and article collections and do not require any particular page slug.

Tags retain their source order. Their route slugs are generated deterministically by lowercasing UTF-8 labels, replacing runs of characters other than Unicode letters, combining marks, and numbers with one hyphen, and trimming edge hyphens. `PHP 8.5` becomes `php-8-5`, `Café` becomes `café`, and `日本語` remains `日本語`. A generated slug must be non-empty and unique within an article, and it must map to one consistent display label throughout the catalog. Tag slugs and output-directory names remain Unicode—for example, `public/tags/café/`—while every emitted slug is UTF-8 percent-encoded as one RFC 3986 path segment, producing the ASCII-safe URL `/tags/caf%C3%A9/`.

Assets placed beside `page.md` or `article.md` are copied beside that item's generated `index.html`, with relative paths preserved. Symlinks and paths whose first component is `index.html` are rejected.

### Markdown

The supported Markdown subset is:

- paragraphs separated by blank lines;
- level 1–3 ATX headings, with the first authored heading at level one and no skipped levels;
- unordered lists using `-` or `*`;
- ordered lists using numeric markers such as `1.`;
- fenced code blocks using triple backticks, with an optional language;
- inline code;
- emphasis, strong emphasis, and strikethrough;
- links with safe HTTP(S), root-relative, or relative targets; and
- thematic breaks.

Raw HTML, images, tables, nested lists, HTML-style attributes, and arbitrary extensions are not supported. Link labels must not be blank. Text, labels, URLs, and code are escaped so authored content cannot become executable markup.

Validation checks every internal Markdown link against the complete generated route and copied-asset inventory. Authored root-relative links are portable site links: `/about/` renders beneath the configured deployment path, such as `/snippet/about/`. Item-relative links remain relative to their content route. Absolute HTTP(S) links on the configured origin are checked only when they are beneath the configured deployment path; same-origin links outside that path and external HTTP(S) links are outside this publication and are left unchecked. Resolution removes query strings and fragments, percent-decodes each path segment, normalizes `.` and `..`, and percent-encodes the normalized segments again before inventory matching. Raw `/tags/café/` and encoded `/tags/caf%C3%A9/` references are therefore equivalent. Encoded dot segments such as `%2e` and `%2e%2e` receive the same traversal checks, including rejection above the site root. Canonical directory routes and explicit generated `index.html` paths are accepted; errors identify the Markdown file and line. Fragment identifiers themselves are not checked.

## Site customization

`snippet init` creates empty content collections, copies the generic `site/` defaults (configuration, custom CSS, favicon, and assets), and adds the agent instructions and skill. These files belong to the author and are never replaced by initialization or builds. HTML templates, base CSS, theme JavaScript, and the preview router stay inside the installed builder. Each build uses that builder version's theme, so updating the image and rebuilding delivers theme fixes without copying files into the site.

Customize appearance in `site/site.css` using the [stable CSS API](#stable-css-api). Optional `site/site.js` adds local behavior and remains author-owned. Template overrides are not supported; a separate workspace’s `resources/` directory is not a publication input. Structural changes to the theme belong in the builder itself.

## Repository and demo separation

The root is the generator and reference implementation. `site/` supplies author-owned defaults and `resources/` supplies the installed theme and workspace instruction templates, while `demo/content/` preserves the project website's articles and pages. `snippet init` creates empty `content/articles/` and `content/pages/` collections, copies the generic `site/` defaults, and copies the author instructions from `resources/workspace/` into the workspace root. Demo configuration and content are never initialized. CI composes the demo into a temporary normal workspace, validates it, builds it, and deploys only the generated output.

Container support is grouped by responsibility under `docker/`: `development/` owns the contributor image, `builder/` the published minimal image, `demo/` temporary demo composition, `preview/` local Caddy support, and `quality/` container-specific quality tooling. Shell sources retain their `.sh` extension in the repository even when an image installs them as an extensionless command.

`site/config.php` defines the site's identity and build preferences with one exact shape:

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Snippet',
    'sitename' => 'Snippet',
    'author' => 'Your Name',
    'description' => 'A personal collection of articles.',
    'url' => 'https://example.com/snippet',
    'language' => 'en',
    'home' => [
        'articles' => 10,
        'tags' => 21,
    ],
    'build' => [
        'minify' => false,
    ],
];
```

The URL must be the final HTTPS site URL, including any deployment path, without credentials, a query, fragment, or trailing slash; it supplies every canonical URL. Root hosting such as `https://example.com` and project hosting such as `https://example.com/snippet` are both supported. Path segments must be well formed and percent-encoded when necessary. Homepage counts are positive integers. The homepage renders the newest article in full, then up to the configured number of older articles and popular tags. Conditional links lead to complete indexes when either collection is truncated.

The `language` value sets the document's HTML language tag. The built-in interface text and formatted publication dates remain English; this setting does not translate the theme.

`title` is the document identity used in browser titles, descriptions, and the homepage's hidden heading. The required `sitename` is independent trimmed, non-empty UTF-8 text used by the centered wordmark and its “— Home” accessible label. The required `author` is also trimmed, non-empty UTF-8 text and supplies the document's author metadata; every document identifies its running Snippet version as the generator. The starter theme displays the site name in uppercase with the bundled Snippet Logo font; the stored and accessible text is unchanged, and unsupported glyphs fall back to the interface font.

When `build.minify` is enabled, publication conservatively collapses whitespace-only text nodes between HTML tags. It leaves prose, attributes, comments, doctypes, inline spacing, and the contents of `pre`, `code`, `textarea`, `script`, and `style` unchanged. It also stream-minifies required `resources/theme.css` and optional `site/site.css`: external whitespace is collapsed, whitespace around `{`, `}`, `;`, and `,` is removed, and strings, escapes, comments, and meaningful token spacing are preserved. Malformed or uncertain CSS is copied unchanged. When minification is disabled, both stylesheets use the direct byte-for-byte copy path. The bundled `resources/theme.js`, optional `site/site.js`, content assets, and files beneath `site/assets/` remain byte-for-byte copies in either mode.

### Site assets

`site/favicon.svg` is the required default browser icon and is copied unchanged to `/favicon.svg`. Replace that file with any valid SVG to customize the site's favicon; a square canvas is recommended for consistent browser presentation. The generated layout references it at the configured deployment path.

Every regular file under `site/assets/` is copied to `/assets/site/` with its relative path preserved. Use this directory for icons, self-hosted fonts, and other site-owned presentation assets; symlinks are rejected.

### Templates and theme

The 13 HTML templates under the builder’s `resources/templates/` own the document shell and shared page structures. They are released with the engine and validated before rendering. Named placeholders receive escaped text or trusted HTML generated from validated data. This is an internal rendering contract, not a site customization API.

The default theme follows the visitor's system light or dark preference until the menu's theme action is used. That choice is stored under `snippet-theme` and synchronized across open same-origin tabs when browser storage is available. The behavior lives in `resources/theme.js`, is copied unchanged to a fingerprinted `/assets/theme.<xxh3>.js` filename, and is permitted by the generated same-origin Content Security Policy without `unsafe-inline`.

The default theme uses native popovers and CSS `light-dark()` in current browsers. Palette pairs live together in the token layer, and `@layer overrides` can customize them for both themes. Print output uses a light, high-contrast palette independently of the selected screen theme.

The builder publishes its bundled `resources/theme.css` as `/assets/theme.<xxh3>.css`, then loads optional UTF-8 `site/site.css` from `/assets/site.<xxh3>.css`. Optional UTF-8 `site/site.js` is copied byte-for-byte to `/assets/site.<xxh3>.js` and loaded with `defer` after the built-in script. Each `<xxh3>` token is the complete 16-character lowercase digest of the exact published bytes, after optional CSS minification. Absent optional files produce neither tags nor output files. Files beneath `site/assets/`, the favicon, and content assets retain their stable paths. Put downstream CSS rules in the final layer:

```css
@layer overrides {
    :root {
        --color-accent: light-dark(#713923, #efbb9f);
        --measure-prose: 42rem;
        --font-reading: "My Font", serif;
    }
}
```

The starter site self-hosts the upright and italic variable webfonts for [Atkinson Hyperlegible Next](https://www.brailleinstitute.org/freefont/) for reading and interface text, plus the supplied Snippet Logo WOFF2 for the wordmark. The upright interface and wordmark fonts are each preloaded only when both `site/site.css` and the matching asset are present; the italic interface font remains demand-loaded. These known paths provide an optional preload optimization in the renderer; they do not constrain replacement fonts or require configurable preload machinery. The Atkinson files come from the [official font repository at commit `7925f50`](https://github.com/googlefonts/atkinson-hyperlegible-next/tree/7925f50f649b3813257faf2f4c0b381011f434f1) and are distributed under the SIL Open Font License 1.1 included beside them. No font-service request is made. Remove `site/site.css` to return all three stable font tokens to their system fallbacks, or replace its `@font-face` declarations and files beneath `site/assets/fonts/` to self-host other families.

Every route keeps its canonical link and receives Open Graph and Twitter/X title, description, URL, and site-name metadata. Articles use `og:type=article`; other routes use `website`. A covered article also emits its validated absolute image URL, MIME type, dimensions, and non-empty authored alt text, and uses the large-image card. No site-wide social image or additional social configuration is implied.

Custom scripts should use the documented class hooks and browser APIs, guard optional elements, and preserve native navigation and accessibility. Their code is never overwritten by a build or `init`. Undocumented DOM details and internal functions in `theme.js` are not a supported JavaScript API. Scripts execute under the existing same-origin Content Security Policy; no bundler or external script origin is added.

### Stable CSS API

Stable color tokens are `--color-background`, `--color-surface`, `--color-interactive`, `--color-text`, `--color-muted`, `--color-accent`, and `--color-border`. Stable font tokens are `--font-reading`, `--font-interface`, `--font-wordmark`, and `--font-code`. Stable sizing tokens are `--measure-prose`, `--measure-shell`, `--space-1` through `--space-6`, and `--space-section`.

Additional stable color tokens are `--color-header-background` and
`--color-navigation-background` (both default to `var(--color-surface)`),
`--color-header-button-background` and `--color-navigation-item-background`
(both default to `var(--color-interactive)`), and `--color-on-accent`
(defaulting to `var(--color-background)`). Header button backgrounds apply on
hover, touch-active, and while the menu is open. Navigation item backgrounds
apply to ordinary items; selected and hovered/active items use the accent fill.
`--color-on-accent` supplies the foreground on accent fills: selection, skip
links, selected menu items, tag-count badges, and hovered/active controls.
Ordinary links and focus outlines retain `--color-accent`. Choose an on-accent
color that contrasts with your accent in both themes.

The sizing group also exposes `--radius-control` and `--radius-panel`, both
`0.75rem`. Controls include buttons, tags, and menu items. Panels include the
header's lower corners, navigation, main surface, and fenced code. The main
surface intentionally has square corners at viewport widths up to `40rem`.

The appended effects group exposes `--opacity-header-background` and
`--opacity-navigation-background` (both `82%`), plus `--shadow-header`,
`--shadow-menu`, and `--shadow-content`. Shadows use neutral black with the
existing geometry: header `0 0.25rem 0.9rem` (10% light / 22% dark), navigation
`0 1rem 2.5rem` (18% / 38%), and symmetric content side shadows
`±1rem 0 2rem -1.35rem` (24% / 42%). Each shadow accepts `none`.
`snippet inspect theme --json` reports their complete canonical expressions,
with existing keys first and additions appended deterministically.

Use opaque component base colors and opacity percentages from `0%` to `100%`.
Glass is mixed at each component from its base color and opacity, and requires
both color mixing and backdrop filtering. At `100%` the fill is opaque.
Unsupported browsers and reduced-transparency preferences use the opaque base
without filtering. The unscrolled header always uses `--color-background`.
Screen rules, including responsive and interaction variants, belong to the
published layers, so root variables and class rules in `@layer overrides` work
without `!important`. Theme selection and print are unlayered exceptions:
system/manual theme selection is preserved, motion effects respect reduced
motion, and print forces high-contrast light colors with white on-accent text.

For example, a Wanted80-style customization can separate header controls from
menu items, make navigation opaque, and optionally remove the shadows:

```css
@layer overrides {
    :root {
        --color-header-background: light-dark(#eef2f7, #17202c);
        --color-navigation-background: light-dark(#ffffff, #111827);
        --color-header-button-background: light-dark(#dce6f3, #26374c);
        --color-navigation-item-background: light-dark(#edf2fa, #1b293e);
        --opacity-navigation-background: 100%;
        --color-accent: light-dark(#174b91, #b8d5ff);
        --color-on-accent: light-dark(#ffffff, #111827);
        --radius-control: 0.5rem;
        --radius-panel: 0.75rem;
        --shadow-header: none;
        --shadow-menu: none;
        --shadow-content: none;
    }
}
```

Stable hooks are `.site-header`, `.site-brand`, `.site-wordmark`, `.site-navigation`, `.site-main`, `.article-list`, `.article-figure`, `.content-header`, `.prose`, `.tag-list`, and `.site-footer`. CSS layers are ordered `reset`, `tokens`, `base`, `layout`, `components`, `overrides`. Patch and minor releases preserve these tokens, class hooks, and layer order; removing or changing their meaning requires a major release. Target these classes directly rather than relying on tag names, child positions, or undocumented selectors. Other DOM details and default visual values may evolve.

Use `light-dark(lightValue, darkValue)` for palette overrides so system preference and the theme control work together. CSS supports colors, typography, spacing, and responsive layout; it cannot change interface wording or document structure. Pin the builder image version when you need repeatable output, then update the image and rebuild to adopt a newer theme.

### Resource limits

Snippet applies generous internal ceilings to catalog counts, text and file sizes, Markdown complexity, image dimensions, assets, templates, rendered pages, and total output. They protect the builder from pathological input and are tested as implementation boundaries; authors do not configure them, and no limit setup is required.

Content and site asset inventories enforce file, depth, and directory-entry ceilings while traversing the source tree. Empty directories count toward the traversal budget, so unused subtrees cannot consume unlimited work or memory.

Configuration and metadata files are declarative PHP rather than executed code. They may contain only `declare(strict_types=1);` and one returned literal array. Calls, expressions, variables, interpolation, includes, duplicate keys, and output are rejected.

## Output and architecture

`bin/snippet build` loads one shared publication-input snapshot and validates its configuration, content catalog, article images, Markdown references, assets, and templates before rendering. `validate`, `build`, and every preview rebuild use this identical boundary. Content assets must be readable during validation. The builder writes a unique temporary sibling tree and replaces `public/` only after every page and asset succeeds.

Once the new site has replaced `public/`, an error removing the previous publication is a cleanup warning: the build remains successful and reports the remaining `.snippet-backup-*` path for manual removal. Preview also reports this warning and continues serving the new site.

The generated routes are:

- `/index.html`, containing the homepage;
- `/404.html`, containing the shared-layout not-found document used by compatible static hosts;
- `/articles/index.html`, `/pages/index.html`, and `/tags/index.html`;
- `/articles/<slug>/index.html` for each article;
- `/<slug>/index.html` for each page;
- `/tags/<tag-slug>/index.html` for each tag;
- `/llms.txt`, containing the metadata-only language-model index; and
- generated and copied assets beneath `/assets/` or beside their content item.

`/llms.txt` has this exact shape, with each collection heading and its list omitted when that collection is empty:

```text
# <site title>

> <site description>

Author: <author>

## Articles

- [<article title>](<absolute canonical URL>): <description> (Published: YYYY-MM-DD)

## Pages

- [<page title>](<absolute canonical URL>): <description>
```

Its metadata is collapsed to single lines and Markdown-escaped so authored values cannot add structure. Article links are newest-first, page links use the existing title/slug order, and URLs are absolute. Markdown bodies, tags, archives, and copied assets are deliberately not duplicated in this file.

Articles are ordered by date descending and then slug ascending. Pages are ordered by title and then slug. Tags on indexes are ordered by article count descending, then label and slug ascending. Builds do not depend on filesystem traversal order or ambient process state.

Markdown inline, list-item, and link traversals, together with preview filesystem fingerprint records, are exposed internally as fresh, forward-only generators. This reduces transient allocations for one-pass consumers. The validated catalog remains materialized because ordering, complete route inventory, tag aggregation, reference validation, and snapshot-safe transactional publication all require one shared complete snapshot; generators do not replace it.

Runtime code uses only PHP's standard library. Composer supplies the PHP platform requirement, PSR-4 autoloading, project scripts, and approved development-only quality tools. The selected static host owns upload configuration, public HTTPS, and certificates; only the generated `public/` directory is deployable. This repository deploys the demo to GitHub Pages after the Quality workflow succeeds for a push to `main`. The Pages workflow checks out that exact tested commit and builds the demo with the production builder image. Pull requests and manual quality runs do not deploy. Stable releases separately publish the versioned builder image; generated output remains ignored, and no publication branch is used.

The root-level `/404.html` uses the same layout, navigation, theme, optional site assets, configured deployment path, and minification policy as every other document. GitHub Pages and static hosts with the same convention serve it for missing routes with a 404 response. Snippet preview mirrors that behavior beneath the configured deployment path and still injects live reload only into the served response.

The default layout also carries a restrictive meta Content Security Policy for same-origin scripts, styles, images, fonts, and connections. Browsers do not enforce `frame-ancestors` from a meta policy, so configure the static host to send `Content-Security-Policy: frame-ancestors 'none'` as an HTTP response header. `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin` are sensible companion headers. Docker's local Caddy preview sends these headers, but deployment configuration belongs to the selected host. GitHub Pages does not provide project-controlled response headers, so its deployment retains the meta CSP but cannot add all of these recommended response headers.

## Maintenance and releases

Pull requests run the complete Docker development gate, validate both Compose environments, audit the locked Composer dependencies, exercise preview through the release builder image, report fixed high and critical operating-system vulnerabilities in that image, and validate and build the composed demo. Run the same project-owned checks locally with:

```bash
make docker-check
make demo-check
make docker-audit
```

`docker-check` is deterministic and includes exact line and type coverage, Pint, Rector, PHPStan, content validation, ShellCheck, JavaScript syntax validation, and JavaScript behavior tests using Node’s built-in test runner. The resource-intensive `docker-mutations` target separately runs the complete Pest suite against every covered source class and requires a 100% mutation score. `docker-audit` is separate because the Composer advisory lookup requires network access.

The architectural decision for browser regression testing approves
`pestphp/pest-plugin-browser` (Pest 5), its Composer development dependency
closure, and the npm development dependency `playwright` (including
`playwright-core`). Computed CSS, native popovers, media preferences, and cascade
precedence require a real browser. Pest remains the runner; Chromium runs
headlessly and serially against temporary publications over container-local
HTTP. Tests require no external network and record no screenshots, videos, or
traces.

Composer and npm lock files pin development tooling. Only the Docker development
stage installs Node dependencies, Chromium, and browser system libraries. npm
modules live in an isolated `/app/node_modules` volume, and the browser cache is
readable by the development user. Production and release-builder stages remain
browser-free. No runtime dependency or publishing-engine capability is added.

The test harness uses direct Pest `Webpage` assertions to avoid the plugin's
automatic failure screenshots. It adapts the pinned browser plugin's process
command to `exec` Node so normal Pest shutdown also reaps the transport server.
A small Chromium protocol adapter supplies media emulation absent from Pest's
PHP page API. Review these test-only adapters when upgrading the plugin.

Snippet follows Semantic Versioning. Pull requests are squash-merged with conventional titles, and Release Please maintains `CHANGELOG.md`, `vX.Y.Z` tags, and GitHub releases. Each stable release also publishes the official multi-platform builder image to GitHub Container Registry with maximum BuildKit provenance, an SPDX SBOM, and GitHub build provenance; the release workflow records its immutable digest. See [CONTRIBUTING.md](CONTRIBUTING.md) for title conventions and the full contributor workflow. Report vulnerabilities privately and verify builder releases as described in [SECURITY.md](SECURITY.md).

## Documentation

- [Installation and operation](INSTALL.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Contributor and coding guidance](AGENTS.md)
- [MIT license](LICENSE)
- [`demo/`](demo/) for the public example site's content and configuration override
- [`site/`](site/) for generic initialized-site configuration, fonts, and assets
- [`resources/`](resources/) for builder-owned templates, theme assets, and preview support
- [`src/`](src/) for the dependency-free builder

Snippet source is available under the [MIT License](LICENSE). The bundled Atkinson Hyperlegible Next font files retain their separate [SIL Open Font License 1.1](site/assets/fonts/atkinson-hyperlegible-next/OFL.txt).
