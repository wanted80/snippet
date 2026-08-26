# Snippet

Snippet is a small, dependency-free PHP 8.5+ publishing system for one author. It turns self-contained Markdown content directories into a completely static website. The public repository is an MIT-licensed starter: the content is the product, the generated site is disposable, and the builder stays out of the published result.

Snippet provides:

- strict configuration, metadata, content, asset, and template validation;
- deterministic article, page, tag, and index routes;
- transactional publication that preserves the last valid `public/` on failure;
- a live-reloading local preview;
- editable semantic HTML templates, plain CSS, and light/dark themes; and
- no third-party runtime PHP packages.

## Why I created Snippet

I created Snippet because I wanted a publishing system that met my own needs without relying on a third-party tool. It was also a perfect opportunity to explore how AI can turn an idea into a working project. I did not write a single line of code myself; instead, I contributed ideas, suggestions, part of my knowledge, and the guidance needed to lead the agent through the project.

I have been working with AI for more than a year, and I believe now is the perfect time to use it as a partner. It makes it possible to build things that were previously out of reach outside our professional work—not because the ideas were missing, but because there was never enough time or more than two hands to do everything. It is also a great time to create more open-source projects driven by our own ideas and needs.

I mainly used GPT 5.6 Sol with medium and xhigh reasoning, and GPT 5.6 Luna with high and xhigh reasoning for smaller tasks. Sometimes I also used subchats in Codex, asking Luna to work within Sol's session and vice versa.

## Quick start

Docker with Make is the recommended setup:

```bash
git clone https://github.com/wanted80/snippet.git
cd snippet
cp .env.example .env
make docker-preview-trust
```

`make docker-preview-trust` installs Snippet's local development certificate, may ask for your computer password, and starts the preview. Close every browser window, reopen the browser, and visit `https://localhost:8443`. Use `make docker-preview` for later previews. See [INSTALL.md](INSTALL.md) for the complete Docker workflow, devcontainer and native PHP alternatives, writing and deployment steps, and troubleshooting.

For a personal site, the recommended arrangement is a private downstream repository rather than a GitHub fork. Create an empty private repository, then connect it to the public starter:

```bash
git clone https://github.com/wanted80/snippet.git my-site
cd my-site
git remote rename origin upstream
git remote add origin git@github.com:your-name/my-private-site.git
git push -u origin main
```

Keep personal content and customization in the private `origin`; fetch builder improvements from `upstream`. Snippet intentionally has no submodule, plugin API, or template replacement package. An official builder image is also available for content-only repositories; see [INSTALL.md](INSTALL.md#official-builder-image).

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

Start a page or article with the minimal draft command:

```bash
bin/snippet new page contact
bin/snippet new article first-post
bin/snippet new article older-note --date=2026-07-01
composer app:new -- article first-post
```

An article without `--date` uses the current UTC date. The command creates an empty Markdown file and a `meta.php` with empty title and description fields; articles also receive the selected date and an empty tag list. The result is intentionally incomplete, so finish both files before validating, building, or previewing. Draft creation does not validate the existing catalog or change `public/`, and it never replaces an existing content directory. Docker users can run the canonical command after entering `make docker-shell`; there is deliberately no separate Make target.

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

The starter keeps `content/pages/about/` as its conventional permanent About page and promotes it with `menu_order`. It remains an ordinary page—using the same content template and reading typography as articles—so its Markdown, assets, route, validation, and archive entry need no special engine path. Replace its content for a real site; the builder deliberately does not require a particular page slug.

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

`resources/` contains the builder-shipped publication defaults and preview support: the default stylesheet, theme script, HTML templates, and preview router. `site/` contains one publication's site-specific configuration, theme overrides, self-hosted fonts, and other assets. Keeping these roles separate lets a site replace presentation details without turning its identity and assets into builder defaults.

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

The `language` value sets the document's HTML language tag. The shipped templates contain English interface text and English-formatted publication dates; translate those editable templates when using another language tag.

`title` is the document identity used in browser titles, descriptions, and the homepage's hidden heading. The required `sitename` is independent trimmed, non-empty UTF-8 text used by the centered wordmark and its “— Home” accessible label. The required `author` is also trimmed, non-empty UTF-8 text and supplies the document's author metadata; every document identifies its running Snippet version as the generator. The starter theme displays the site name in uppercase with the bundled Snippet Logo font; the stored and accessible text is unchanged, and unsupported glyphs fall back to the interface font.

When `build.minify` is enabled, publication conservatively collapses whitespace-only text nodes between HTML tags. It leaves prose, attributes, comments, doctypes, inline spacing, and the contents of `pre`, `code`, `textarea`, `script`, and `style` unchanged. It also stream-minifies `resources/site.css` and optional `site/theme.css`: external whitespace is collapsed, whitespace around `{`, `}`, `;`, and `,` is removed, and strings, escapes, comments, and meaningful token spacing are preserved. Malformed or uncertain CSS is copied unchanged. When minification is disabled, both stylesheets use the direct byte-for-byte copy path. `resources/theme.js`, content assets, and files beneath `site/assets/` remain byte-for-byte copies in either mode.

### Site assets

Every regular file under `site/assets/` is copied to `/assets/site/` with its relative path preserved. Use this directory for icons, self-hosted fonts, and other site-owned presentation assets; symlinks are rejected.

### Templates and theme

The 12 editable HTML files under `resources/templates/` own the author-customizable structural markup. `layout.html` provides the document shell, header, navigation, main region, and footer; the remaining files define the homepage, shared collection and content structures, and repeated items. Small engine-owned fragments remain in PHP when they serialize typed data or depend on validated runtime state: Markdown HTML, date markup, navigation links, resource hints, conditional stylesheet tags, empty states, and optional index links. This keeps loops and conditionals out of the template language without creating one-off fragment templates.

Templates use named placeholders such as `{{body}}`, `{{title}}`, and `{{items}}`. Surrounding HTML, classes, and text may change, and placeholders may move or repeat, but every placeholder expected by a template must remain and unknown or malformed placeholders are rejected before publication. Placeholder values are either escaped text or trusted HTML produced by the engine or another validated template, so keep them in their intended text or attribute contexts rather than moving them into JavaScript or CSS. The layout's `{{author}}` placeholder supplies author metadata, `{{version}}` supplies the release-managed generator version, `{{base_path}}` prefixes template-owned root links, and the repeatable `{{sitename}}` placeholder supplies both wordmark text and its accessible home label. The generic `{{preloads}}` placeholder belongs in the document head before the stylesheets and remains empty when no validated resource hint applies. The wordmark links home; the adjacent native popover contains Articles, Tags, Pages, ordered `menu_order` pages, and a final `llms.txt` link without widening the header.

The default theme follows the visitor's system light or dark preference until the menu's theme action is used. That choice is stored under `snippet-theme` and synchronized across open same-origin tabs when browser storage is available. The behavior lives in `resources/theme.js`, is copied unchanged to `/assets/theme.js`, and is permitted by the generated same-origin Content Security Policy without `unsafe-inline`.

The builder treats UTF-8 `site/theme.css` as optional, copies it to `/assets/theme.css` when present, and loads it after the default stylesheet. Put downstream rules in the final CSS layer:

```css
@layer overrides {
    :root {
        --font-reading: "My Font", serif;
    }
}
```

The starter site self-hosts the upright and italic variable webfonts for [Atkinson Hyperlegible Next](https://www.brailleinstitute.org/freefont/) for reading and interface text, plus the supplied Snippet Logo WOFF2 for the wordmark. The upright interface and wordmark fonts are each preloaded only when both `site/theme.css` and the matching asset are present; the italic interface font remains demand-loaded. These known paths provide an optional preload optimization in the renderer; they do not constrain replacement fonts or require configurable preload machinery. The Atkinson files come from the [official font repository at commit `7925f50`](https://github.com/googlefonts/atkinson-hyperlegible-next/tree/7925f50f649b3813257faf2f4c0b381011f434f1) and are distributed under the SIL Open Font License 1.1 included beside them. No font-service request is made. Remove `site/theme.css` to return all three stable font tokens to their system fallbacks, or replace its `@font-face` declarations and files beneath `site/assets/fonts/` to self-host other families.

### Stable CSS API

Stable color tokens are `--color-background`, `--color-surface`, `--color-interactive`, `--color-text`, `--color-muted`, `--color-accent`, and `--color-border`. Stable font tokens are `--font-reading`, `--font-interface`, `--font-wordmark`, and `--font-code`. Stable sizing tokens are `--measure-prose`, `--measure-shell`, `--space-1` through `--space-6`, and `--space-section`.

Stable hooks are `.site-header`, `.site-brand`, `.site-wordmark`, `.site-navigation`, `.site-main`, `.article-list`, `.article-figure`, `.content-header`, `.prose`, `.tag-list`, and `.site-footer`. CSS layers are ordered `reset`, `tokens`, `base`, `layout`, `components`, `overrides`. Other DOM details may evolve; changing a stable token or hook requires a migration note.

### Resource limits

Snippet applies generous internal ceilings to catalog counts, text and file sizes, Markdown complexity, image dimensions, assets, templates, rendered pages, and total output. They protect the builder from pathological input and are tested as implementation boundaries; authors do not configure them, and no limit setup is required.

Configuration and metadata files are declarative PHP rather than executed code. They may contain only `declare(strict_types=1);` and one returned literal array. Calls, expressions, variables, interpolation, includes, duplicate keys, and output are rejected.

## Output and architecture

`bin/snippet build` loads one shared publication-input snapshot and validates its configuration, content catalog, article images, Markdown references, assets, and templates before rendering. `validate`, `build`, and every preview rebuild use this identical boundary. It writes a unique temporary sibling tree and replaces `public/` only after every page and asset succeeds. The generated routes are:

- `/index.html`, containing the homepage;
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

Runtime code uses only PHP's standard library. Composer supplies the PHP platform requirement, PSR-4 autoloading, project scripts, and approved development-only quality tools. The selected static host owns upload configuration, public HTTPS, and certificates; only the generated `public/` directory is deployable. This repository builds `public/` through the locked production Docker workflow and deploys that artifact to GitHub Pages after the separate `Quality` workflow succeeds on `main`; pull requests never deploy, generated output remains ignored, and no publication branch is used. Dispatch `Quality` manually when a manual publication is needed.

The default layout also carries a restrictive meta Content Security Policy for same-origin scripts, styles, images, fonts, and connections. Browsers do not enforce `frame-ancestors` from a meta policy, so configure the static host to send `Content-Security-Policy: frame-ancestors 'none'` as an HTTP response header. `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin` are sensible companion headers. Docker's local Caddy preview sends these headers, but deployment configuration belongs to the selected host. GitHub Pages does not provide project-controlled response headers, so its deployment retains the meta CSP but cannot add all of these recommended response headers.

## Maintenance and releases

Pull requests run the complete Docker development gate, validate both Compose environments, audit the locked Composer dependencies, and validate content through the production image. Run the same project-owned checks locally with:

```bash
make docker-check
make docker-audit
```

`docker-check` is deterministic and includes exact line and type coverage, Pint, Rector, PHPStan, content validation, ShellCheck, and JavaScript syntax validation. `docker-audit` is separate because the Composer advisory lookup requires network access.

Snippet follows Semantic Versioning. Pull requests are squash-merged with conventional titles, and Release Please maintains `CHANGELOG.md`, `vX.Y.Z` tags, and GitHub releases. Each stable release also publishes the official multi-platform builder image to GitHub Container Registry. See [CONTRIBUTING.md](CONTRIBUTING.md) for title conventions and the full contributor workflow. Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Documentation

- [Installation and operation](INSTALL.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Contributor and coding guidance](AGENTS.md)
- [MIT license](LICENSE)
- [`content/`](content/) for authored pages and articles
- [`site/`](site/) for site-specific configuration, overrides, fonts, and assets
- [`resources/`](resources/) for builder-shipped publication defaults and preview support
- [`src/`](src/) for the dependency-free builder

Snippet source is available under the [MIT License](LICENSE). The bundled Atkinson Hyperlegible Next font files retain their separate [SIL Open Font License 1.1](site/assets/fonts/atkinson-hyperlegible-next/OFL.txt).
