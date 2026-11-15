# Snippet: agent guidance

Snippet is a small personal publishing system for one author, built for learning,
curiosity, experimentation, and fun. A dependency-free PHP builder transforms
self-contained content directories into a static website.

The content is the product. The builder is an implementation detail. Generated
output is disposable, and technology serves the content.

## Start here

- Read the relevant source and tests before editing. Inspect `git status` and
  preserve existing work; keep the change focused on the user's request.
- This is the **engine repository**, not an initialized publication workspace.
  The public example lives in `demo/`; do not create root `content/` to make
  validation pass. Use `composer app:content:validate` for the composed demo.
- Work inside the devcontainer for PHP and tests. From the host, use the Docker
  Make targets in [CONTRIBUTING.md](CONTRIBUTING.md). Documentation edits do not
  require starting containers or running the application suite.
- Keep author-owned files and engine-owned resources distinct. This file guides
  contributors; `resources/workspace/AGENTS.md` and the bundled authoring skill
  guide users of initialized publications. Edit those only when the task affects
  author guidance.
- Before handing back work, review the diff, run the checks appropriate to the
  change, and report what changed, what passed, and anything that could not run.
  Never present an unavailable check as passing.

## Scope and change boundaries

The engine is frozen: content discovery, metadata, route resolution, Markdown
parsing, image validation, internal-reference validation, rendering, transactional
publication, preview fallback, and live reload define the finished engine.
Subsequent work is limited to layout, typography, responsive behavior, themes,
and visual polish unless an explicit product need reopens engine development.
The generated 404 document and existing Open Graph and Twitter/X metadata are
explicit exceptions.

Unless explicitly requested:

- Do not add feeds, sitemaps, search, pagination, drafts, Markdown images, new
  content types, image processing, plugins, deployment integrations, JSON-LD,
  additional social configuration, or author/update metadata.
- Do not add packages, change URLs, redesign the architecture, implement future
  features, or perform unrelated refactoring.
- Do not edit generated `public/` as source or delete or overwrite user content.

Existing `new` commands create incomplete source scaffolds; they do not authorize
adding a draft publication state. Preserve the existing author configuration and
metadata contracts.

When repository files conflict, follow direct user instructions, then this file,
then README and existing code. Prefer the smallest reversible choice for an
unspecified implementation detail; document it in code or README if it affects
users.

## Git

- Commits should be atomic: include only one coherent change or fix, and do not mix unrelated work.
- Commit messages should be succinct and describe the change being made.
- Use Conventional Commits: `<type>(<optional scope>): <description>`.

## Where to look

| Task | Relevant sources |
| --- | --- |
| Contributor setup and commands | `CONTRIBUTING.md`, `INSTALL.md`, `Makefile`, `composer.json` |
| Devcontainers | `.devcontainer/default/`, optional `.devcontainer/codex/` |
| CLI and machine-readable contracts | `bin/snippet`, `src/Application.php`, `src/Cli/`, `src/Inspection/`, README's Agent CLI section |
| Configuration and content | `src/Site/`, `src/Content/`, `src/Support/TrustedPhpLoader.php` |
| Markdown and internal links | `src/Markdown/`, `src/Publishing/ReferenceValidator.php` |
| Rendering, assets, and publication | `src/Rendering/`, `src/Publishing/` |
| Theme and stable customization API | `resources/theme.css`, `resources/theme.js`, `resources/templates/`, README's Stable CSS API section |
| Preview and reload | `src/Preview/`, `resources/preview-router.php` |
| Initialization and author instructions | `src/Scaffolding/`, `resources/workspace/AGENTS.md`, `resources/workspace/.agents/skills/snippet-authoring/SKILL.md` |
| Generic author defaults / project example | `site/` / `demo/content/` and `demo/site/config.php` |
| Image, shell, CI, and release workflows | `docker/`, `.githooks/`, `.github/workflows/`, `CONTRIBUTING.md` |

The publication path is `Application → PublicationInputLoader → validated inputs
→ Publisher → HtmlRenderer`. Reuse the same validated snapshot across rendering
and publication. Internal resource budgets live in `src/Site/Limits.php`; they
are not author configuration or compatibility promises.

## Commands and workspaces

Run the direct CLI **from the publication workspace**; the executable may live
in a separate engine checkout. Canonical commands include:

```text
/path/to/snippet/bin/snippet inspect capabilities --json
/path/to/snippet/bin/snippet inspect theme --json
/path/to/snippet/bin/snippet inspect config --json
/path/to/snippet/bin/snippet inspect content --json
/path/to/snippet/bin/snippet validate [--json]
/path/to/snippet/bin/snippet build [--json]
/path/to/snippet/bin/snippet preview [--host=<host>] [--port=<port>]
/path/to/snippet/bin/snippet new page <slug> [--json]
/path/to/snippet/bin/snippet new article <slug> [--date=YYYY-MM-DD] [--json]
```

Inspection reads installed contracts without needing a workspace. Query the
relevant contract before guessing configuration fields, content rules, CSS
tokens, or class hooks. `init` is available through the Docker entrypoint only.
`new` never replaces an existing content directory and succeeds with incomplete
Markdown and metadata; complete them before validation. An omitted article date
uses UTC. `preview` has no JSON mode. JSON exit statuses are `0` for success, `1`
for operation failure, and `2` for invalid usage; see [README.md](README.md#agent-cli)
for the response contract.

`validate` does not change `public/`. `build` publishes transactionally. Direct
preview serves HTTP at `127.0.0.1:8080` by default. Contributor Docker preview uses
Caddy and HTTPS; keep Make/Compose behavior aligned with [INSTALL.md](INSTALL.md).
Run only one preview against a workspace because they share `public/`.
Production hosting contains only generated static files, with no PHP runtime.

## Verification workflow

Read `composer.json`, `phpunit.xml`, `pint.json`, `rector.php`, `phpstan.neon`,
`tests/Feature/ArchitectureTest.php`, and `tests/Feature/PresetsTest.php` before
changing PHP or tests. These are project source; do not weaken rules, thresholds,
or presets to make checks pass.

For every coherent PHP change:

1. For behavior changes, add or update the smallest focused Pest test first.
2. Run it while iterating, for example
   `composer app:test -- --filter='relevant test description'`. Use `--dirty`,
   test impact analysis, parallel execution, or profiling where useful and safe.
3. Run `composer app:fix`; review Rector and Pint changes and keep them convergent.
4. Run `composer app:analyse`; fix findings in code or precise types, without
   suppressions or a baseline.
5. Run `composer app:check` before handoff. Focused tests do not replace this gate.

From the host, use `make docker-fix`, `make docker-analyse`, and
`make docker-check`. The shared `docker/quality/check.sh` runs `app:check` plus
ShellCheck, shell behavior tests, JavaScript syntax validation, and
`composer app:test:assets`. Run it inside development when shell or JavaScript
changes need the full shared gate. Keep checks proportional: for documentation
only, verify commands and links against source and run `git diff --check`.

`app:check` requires exactly 100% source line coverage through PCOV, 100% type coverage, Pint,
Rector, PHPStan at maximum level, demo validation, and Firefox/WebKit browser
compatibility tests. The normal Pest suite includes Chromium.
`composer app:test:browser` / `make docker-test-browser` runs all three browsers;
use browser tests for computed styles, accessibility, and interactive behavior
that require a real browser.

`make builder-smoke` and `make demo-check` cover release-image and composed-demo
build behavior. `composer app:audit` / `make docker-audit` is separate because
advisory lookup needs network access. The full `make docker-mutations` gate is
also separate and resource-intensive; it requires a 100% mutation score.
See `make help` and [CONTRIBUTING.md](CONTRIBUTING.md) for the pre-push workflow
and conventional PR titles. Do not bypass a failing hook to complete a push.

Use Pest 5 idiomatically: datasets for behavioral matrices, lifecycle hooks, and
`covers()` or `uses()` where they clarify intent. Add focused architecture
expectations for new invariants. Use focused mutation testing
for non-trivial validation/parsing changes. Use snapshots only for stable complex
structures clearer than direct expectations. Use the Pest Agent plugin's AI
verification when creating or substantially rewriting tests; Evals apply if the
project gains LLM/agent behavior. Keep the Pest PHPStan and Rector plugins enabled.
Select these features for the behavior under test, not ceremonially.

Tests use temporary content trees, never repository `public/`. They must not
depend on external network access, wall-clock time, or locale. Repeated validation
of identical input must produce byte-identical serialized DTO/document models.

## Implementation conventions

- PHP 8.5+, standard library first, no third-party runtime packages. Composer
  provides the PHP requirement, PSR-4 autoloading, scripts, and approved development
  tools. Every new external package requires an explicit architectural decision
  recorded in the repository before adding it.
- Use small, readable, modern PHP with explicit invalid states and useful failures.
  Match nearby naming, structure, formatting, and error handling. Fixed dependencies
  in mutable classes stay readonly. Use enums, property hooks, asymmetric visibility,
  and other language features when they clarify a concrete contract.
- Use `#[\NoDiscard]` with an explanatory message when discarding a result defeats
  the operation. Keep justified declaration exceptions documented beside their
  architecture expectations.
- Add PHPDoc for information native types cannot express: collection shapes,
  ordering, invariants, trust and side-effect boundaries, and meaningful failures.
  Public boundary methods describe their contract and normal-use `@throws`.
  Avoid comments that merely restate names, types, or implementation lines.
  Update documentation together with behavior.
- Treat CPU, memory, and network usage as requirements. Preserve deterministic
  behavior independent of traversal order and ambient process state.
- Prefer composition and standard-library functions. Add abstractions only for a
  concrete second use or clear improvement in clarity. Review nearby consistency
  and remove complexity introduced by the change.

## Content and validation invariants

Full field and Markdown contracts are available through `inspect content --json`
and `inspect config --json`. Preserve these boundaries:

- Pages: `content/pages/<slug>/page.md` plus `meta.php` and optional assets.
  Articles: `content/articles/YYYY/MM/DD/<slug>/article.md` plus `meta.php` and
  optional assets. Dates are real, zero-padded calendar dates matching metadata;
  they do not change public URLs. Leaf slugs use lowercase ASCII letters/numbers
  separated by single hyphens. Titles may use any language; never transliterate.
- Metadata and configuration begin with `declare(strict_types=1);` and return one
  literal associative array. Parse without executing. Reject calls, expressions,
  variables, interpolation, includes, duplicate keys, output, unknown/missing
  fields, and invalid types or values. Convert validated data into readonly DTOs.
- Articles require title, description, date, and ordered tags. Pages require title
  and description, reject article fields, and may have a positive, site-unique
  `menu_order`; navigation has an internal ceiling.
- Tag slugs lowercase UTF-8 labels, replace runs outside Unicode letters, combining
  marks, and numbers with one hyphen, then trim edge hyphens. Require non-empty,
  article-unique slugs. Keep Unicode in the model and output directories; encode
  each complete slug as one RFC 3986 URL segment (`café` → `caf%C3%A9`).
- An enabled article cover requires exactly one root `cover.jpg`, `cover.png`, or
  `cover.webp`, matching detected format and dimension limits (32,768 pixels by
  default). Optional trimmed non-empty `alt` is allowed only with `cover=true`
  and uses the description-length limit; omission renders `alt=""`.
- Markdown supports paragraphs, ATX headings 1–3, flat ordered/unordered lists,
  triple-backtick code fences, inline code, emphasis, strong, strikethrough, links,
  and thematic breaks. Reject raw HTML, images, tables, nested lists, attributes,
  and arbitrary extensions. Escape text, labels, URLs, and code. The first heading,
  if any, must be level one; later headings cannot skip levels. Link labels cannot
  be blank.
- Validate root-relative, item-relative, and same-origin absolute links against
  generated routes and copied assets. Ignore queries/fragments, decode segments,
  normalize dot segments, reject traversal above root, then encode for matching.
  Raw and encoded Unicode paths are equivalent; encoded dot segments receive the
  same traversal checks. Report missing targets with Markdown path and line.
  External HTTP(S) targets and fragment identifiers remain unchecked.

## Rendering, theme, and publication invariants

- Validate first, build in a unique temporary sibling directory, and replace
  `public/` only after every page and asset succeeds. Fail with source/item/field
  context where possible. Preserve the last valid publication after invalid edits.
- Preserve `/`, `/404.html`, `/articles/`, `/pages/`, `/tags/`, `/llms.txt`,
  `/articles/<slug>/`, `/<slug>/`, and `/tags/<tag-slug>/`, plus presentation and
  content assets. The homepage features the newest article in full and configured
  collections of older articles and popular tags; `llms.txt` is metadata-only.
- Sort articles by date descending then slug; pages by title then slug; popular
  tags by count descending then label then slug. Ascending tie-breaks are stable.
- Engine-owned templates, theme CSS/JS, and preview resources live in `resources/`.
  They are immutable installed inputs for authors, never workspace overrides.
  Initialization copies only author defaults and guidance, creates empty content
  collections, and never replaces author files or copies demo content.
- Authors own `site/config.php`, optional `site/site.css` and `site/site.js`,
  favicon, site assets, and content. Preserve the documented CSS tokens, class
  hooks, and layer meanings within a major release. Use semantic HTML, plain CSS,
  and `@layer overrides` for author styles. Preserve readable, navigable output
  without JavaScript and review visual changes across widths and light/dark modes.
- Load optional site JavaScript with `defer` after the bundled theme script.
  Preserve CSP behavior; external origins require explicit configuration.
- Render covers through `resources/templates/article-figure.html` only on the
  canonical article and when featured in full on the homepage, never archive
  cards or tag pages. Preserve original bytes and root-relative article asset
  URLs. Do not add resizing, compression, variants, lazy-loading policy, or captions.
- Preserve ordered footer profile links (HTTPS, with a label or bundled icon),
  accessible URL names for icon-only profiles, and the footer `llms.txt` link.
- Always conservatively minify theme and optional site CSS, preserving token
  boundaries and falling back to original bytes for uncertain syntax. Publish
  HTML as rendered and copy JavaScript unchanged. Fingerprint entry assets from
  final published bytes; do not add build/minification configuration.
- Preview may inject a live-reload helper into responses, never published HTML.
  Direct contributor preview reloads runtime after engine `bin/` or `src/` edits
  and rebuilds after `resources/` edits. The official image uses its immutable
  engine router and watches only mounted publication inputs (`content/`, `site/`).
  Caddy's local certificate is for preview; the static host owns public HTTPS.
