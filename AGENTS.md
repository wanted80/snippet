# Snippet

Snippet is a small personal publishing system.

It transforms self-contained content directories into a completely static website using a dependency-free PHP builder.

The project exists for learning, curiosity, experimentation, and fun.

It is built for one author.

## Core Principles

- The content is the product.
- The builder is an implementation detail.
- The generated website is disposable.
- Technology serves the content.

## Current system

The current builder discovers and validates site configuration, content, metadata, templates, and assets; applies internal resource ceilings; orders the resulting catalog; resolves routes; parses the supported Markdown into a typed document model; and renders the complete static site.

Publication validates first, builds in a unique temporary sibling directory, and replaces `public/` only after every page and asset succeeds. The preview command performs the same build, serves it locally, watches `content/`, `site/`, and `resources/`, reloads fresh runtime code after changes beneath `bin/` or `src/`, preserves the last valid publication after an invalid edit, and live-reloads open pages after a successful rebuild.

Do not implement feeds, pagination, search, drafts, Markdown images, a generated 404 page, or additional content types unless explicitly requested.

## Project goals

- PHP 8.5+
- PHP standard library first
- Composer for the PHP requirement, PSR-4 autoloading, project scripts, and approved development-only quality tools
- No third-party runtime packages
- Static HTML output
- Semantic HTML
- Plain CSS
- Deterministic builds
- Small, readable code

## Repository layout

snippet/
- .devcontainer/
- docker/
- AGENTS.md
- INSTALL.md
- LICENSE
- Makefile
- README.md
- compose.yaml
- compose.dev.yaml
- composer.json
- bin/
- demo/
- resources/
- site/
- src/
- tests/
- public/ (generated, disposable, and ignored)

## Composer policy

Composer is allowed only as project infrastructure. Runtime dependencies remain forbidden.

Allowed:
- PHP platform requirement
- PSR-4 autoloading
- project scripts
- the development packages already approved in `composer.json`

Forbidden:
- third-party runtime packages
- framework packages

Every external package requires an explicit architectural decision recorded in the repository before it is added.

## Runtime and commands

PHP exists only while validating, building, or previewing the site; production hosting contains no PHP runtime.

The canonical direct CLI commands are:

```text
bin/snippet validate
bin/snippet build
bin/snippet preview [--host=<host>] [--port=<port>]
```

`validate` reads and validates without changing `public/`. `build` transactionally publishes the static site to `public/`. `preview` builds, serves, watches, and live-reloads the site over direct HTTP at `127.0.0.1:8080` by default. Production hosting contains only the generated static files.

Useful project commands should be exposed through Composer scripts when they exist, but the direct CLI command remains canonical.

Docker with Make is the recommended user workflow. The root Compose and Make interfaces provide development and production images, an HTTPS preview through Caddy, isolated dependency volumes, and development-only quality targets. Keep their documented behavior aligned with `INSTALL.md`.

## Content model

Pages and articles occupy separate source collections in a normal initialized workspace. The repository's own public example stores the same shape beneath `demo/content/`:

content/
- pages/
  - <slug>/
    - page.md
    - meta.php
    - optional assets
- articles/
  - YYYY/MM/DD/<slug>/
    - article.md
    - meta.php
    - optional assets

The leaf directory is the content unit, and its name is the URL slug; validate it before generating output. Page and article directory slugs are author-chosen lowercase ASCII letters and numbers separated by single hyphens. Titles are independent and may use any language: a title such as `日本語` can use the directory slug `nihongo`. Never transliterate titles automatically because pronunciation and convention are language-dependent. Article year, month, and day directories must form a real calendar date in zero-padded `YYYY/MM/DD` form. The directory date must match the article's `date` metadata. The hierarchy organizes source content only and does not change public URLs.

## `meta.php`

Every `meta.php` must return the metadata shape required by its source collection:

- starts with declare(strict_types=1);
- returns one plain associative array
- contains configuration only
- has no side effects
- produces no output

Common fields:

- `title`: non-empty string
- `description`: non-empty string
- articles require `date` (a real `YYYY-MM-DD` calendar date) and `tags` (an ordered list of trimmed, non-empty tag label strings)
- articles may set boolean `cover` to `true`; it defaults to `false`, and an optional `alt` is allowed only when enabled
- an enabled cover requires exactly one root-level `cover.jpg`, `cover.png`, or `cover.webp`; the builder verifies its detected format, derives its dimensions, and enforces the 32,768-pixel default dimension ceiling
- explicit cover alt text is a trimmed non-empty string governed by the description-length limit; omission produces `alt=""`
- pages reject `date`, `tags`, `cover`, and `alt`, and may define a positive integer `menu_order`

The builder rejects missing or unknown fields, invalid values, and unexpected field types. It preserves tag order from the source and uses the content directory name as the slug. Page menu orders must be site-unique, and the number of menu pages is constrained by the internal navigation ceiling.

Example:

<?php
declare(strict_types=1);

return [
    'title' => 'Example',
    'description' => '...',
    'date' => '2026-08-02',
    'tags' => [
        'PHP 8.5',
    ],
];

Tag slugs are generated deterministically from UTF-8 labels by lowercasing them, replacing each run of characters other than Unicode letters, combining marks, and numbers with one hyphen, and trimming edge hyphens. The result must be non-empty and unique within an article. `Café` produces the Unicode slug and output directory `café`, and `日本語` remains `日本語`. Tag slugs remain Unicode in the content and output directory model, while each complete slug is UTF-8 percent-encoded as one RFC 3986 path segment whenever a URL is emitted; for example, `café` is emitted as `caf%C3%A9`.

The builder parses the returned literal array without executing the file and converts it into internal readonly DTOs. Calls, expressions, variables, interpolation, includes, duplicate keys, and output are rejected.

## Content types

Supported:

- article
- page

article -> /articles/<slug>/
page -> /<slug>/

## PHP style

Use:

- strict types
- readonly where useful
- enums when appropriate
- typed properties
- explicit return types
- match where clearer

### Modern PHP features

The project targets PHP 8.5+ and should use the best applicable features from
the latest PHP versions. Before adding custom machinery, review whether the
language or standard library now expresses the same behavior more clearly,
safely, or precisely.

Prefer, when they fit the domain:

- enums for finite value sets;
- readonly classes and properties for immutable validated data;
- constructor property promotion and asymmetric visibility for intentional
  ownership and mutation boundaries;
- property hooks for derived or guarded property behavior when they make an
  invariant clearer than ordinary methods;
- union, intersection, DNF, `never`, `static`, and standalone `true`, `false`,
  or `null` types where they make invalid states harder to represent;
- typed class constants for stable, class-owned configuration and invariants;
- `#[\Override]`, `#[\NoDiscard]`, `#[\SensitiveParameter]`, and other built-in
  attributes when they enforce a real API contract;
- first-class callables, closures in constant expressions, `match`, null-safe
  access, and named arguments when they improve reading order or remove
  incidental state;
- clone-with for purposeful immutable copy operations; and
- current standard-library APIs such as `array_all()`, `array_any()`,
  `array_find()`, `array_first()`, `array_last()`, and `mb_trim()` instead of
  hand-written equivalents.

Never use PHP's Pipe Operator. Do not introduce a feature merely to demonstrate
that it exists. Property hooks, asymmetric setters, clone-with, lazy objects,
fibers, and advanced type forms should solve a concrete problem in the code at
hand. Prefer the simplest modern construct that makes the contract more obvious
to a reader.

### PHPDoc

Use PHPDoc to add information the native declaration cannot express or a reader
cannot infer locally. Document class responsibility, important invariants, trust
and side-effect boundaries, collection shapes, template or alias types, expected
ordering, and meaningful failure conditions. Public boundary methods should
describe their contract and use `@throws` when failure is part of normal use.

Do not add comments that only restate a class name, parameter name, native type,
or implementation line. Keep precise PHPStan collection shapes such as
`list<T>`, `non-empty-string`, and array shapes where they improve analysis.
Update documentation together with behavior so it cannot silently drift.

## Code quality

- Treat CPU, network, and memory usage as requirements for every task and feature.
- Prefer the simplest correct implementation that clearly expresses the behavior.
- Write modern, idiomatic PHP and use current language features when they improve correctness or readability.
- Keep code easy to read before making it clever, generic, or highly optimized.
- Match the naming, structure, formatting, and error-handling conventions already present in the codebase.
- Keep responsibilities small, but do not create abstractions without a concrete second use or clear improvement in clarity.
- Avoid hidden state, surprising mutations, magic behavior, and unnecessary indirection.
- Make invalid states and failure paths explicit; fail early with actionable errors.
- Prefer composition of small, understandable functions and classes over deep inheritance or large multipurpose classes.
- Preserve deterministic behavior and avoid relying on ambient global state.
- Before finishing a change, review nearby code for consistency and remove unnecessary complexity introduced by the change.

## Markdown

Support only this small subset:

- paragraphs separated by blank lines;
- ATX headings from `#` through `###`;
- unordered lists using `-` or `*`;
- ordered lists using `1.`-style markers;
- fenced code blocks using triple backticks, with an optional language name;
- inline code using backticks;
- emphasis using `*text*` and strong emphasis using `**text**`;
- strikethrough using `~~text~~`;
- links using `[label](https://example.com)`; and
- thematic breaks.

Do not support raw HTML, Markdown images, tables, nested lists, HTML-style attributes, or arbitrary Markdown extensions. Plain text, link labels, URLs, and code must be escaped correctly; generated HTML must not interpret content as markup.

The first authored heading, when present, must be level one, and later headings must not skip levels. Link labels must not be blank. Validate root-relative, item-relative, and same-origin absolute links against a deterministic inventory of generated routes and copied assets. Ignore queries and fragments, percent-decode each path segment, normalize `.` and `..`, and percent-encode normalized segments before inventory matching. Raw `/tags/café/` and encoded `/tags/caf%C3%A9/` references are equivalent. Encoded dot segments such as `%2e` and `%2e%2e` receive the same traversal checks, including rejection above the root. Report missing targets with the Markdown path and line. External HTTP(S) targets and fragment identifiers remain unchecked.

## Rendering and publication

Always:

- validate first
- build in a temporary directory
- replace `public/` only after success
- make output independent of filesystem traversal order and current process state
- fail with a useful error that identifies the content item and field when possible

The build generates:

- `/index.html`, featuring the newest article in full and configured collections of older articles and popular tags;
- `/404.html`, containing the shared-layout not-found document for compatible static hosts;
- `/articles/index.html`, `/pages/index.html`, and `/tags/index.html`;
- `/articles/<slug>/index.html` for articles;
- `/<slug>/index.html` for pages;
- `/tags/<tag-slug>/index.html` for tag archives; and
- presentation assets beneath `/assets/`, plus content assets beside their generated item page.

Articles are ordered by date descending and then slug ascending. Pages are ordered by title and then slug. Popular tags are ordered by article count descending, then label and slug ascending. All routes use the same validated configuration, metadata, document model, and templates. Templates, the default CSS, theme behavior, optional site CSS, site assets, and content assets are publication inputs rather than generated source.

An article's enabled cover is rendered through `resources/templates/article-figure.html` as `<figure class="article-figure">` on its canonical page and when featured in full on the homepage. Do not render it on archive cards or tag pages. Preserve its original bytes and root-relative article asset URL; do not add resizing, compression, responsive variants, lazy-loading policy, captions, or an image-processing dependency.

Preview responses may receive a live-reload helper, but that helper must never be written into published HTML. Docker's local Caddy certificate is a preview concern only; deployment consists solely of `public/`, and the selected static host owns public HTTPS.

## Testing and verification

Use Pest 5 for focused tests, Pint and Rector for formatting and refactoring checks, PHPStan at maximum level for static analysis, and PCOV for coverage. These are approved development-only dependencies; application code remains dependency-free.

Every behavior change should add or update a focused test. `composer app:check` requires exactly 100% source line coverage, 100% type coverage, lint checks, static analysis, and content validation. If a command cannot run, report that explicitly.

Tests must not depend on network access, wall-clock time, locale, or the existing generated `public/` directory.

Tests use temporary content trees and must not use the repository's generated output. Repeated validation of identical input must produce byte-identical serialized DTO/document representations.

## Development workflow

Work inside the devcontainer and treat `pint.json`, `rector.php`, `phpstan.neon`, `phpunit.xml`, and the Composer scripts as project source. Read the relevant configuration before changing PHP or tests; do not replace or weaken it to make a check pass.

For every coherent PHP change:

1. Add or update the smallest focused Pest test first when behavior changes.
2. Run that focused test while iterating, using Pest's `--filter`, `--dirty`, or test impact analysis where appropriate.
3. Run `composer app:fix`, review the resulting Rector and Pint changes, and keep both tools convergent.
4. Run `composer app:analyse`; resolve PHPStan findings in the code or precise types instead of suppressing them or adding a baseline.
5. Run `composer app:check` before handing the change back. It is the authoritative final gate and must not be replaced by a focused test run.

Use Pest 5 idiomatically rather than writing PHPUnit-style tests inside Pest files. Prefer datasets for behavioral matrices, descriptive expectations, before/after hooks for lifecycle, and `covers()` or `uses()` metadata when they clarify intent. Maintain the PHP and security architecture presets and add focused architecture expectations for new invariants.

Actively use the relevant Pest 5 capabilities:

- use test impact analysis, `--dirty`, filtering, parallel execution, and profiling to shorten feedback loops when the affected tests are safe for them;
- use mutation testing for non-trivial validation and parsing behavior to find assertions that pass without proving the behavior;
- use strict line coverage and type coverage for all source changes, retaining the required 100% thresholds;
- use snapshots only for stable, sufficiently complex serialized structures where a snapshot is clearer than direct expectations;
- use the Agent plugin's AI verification when creating or substantially rewriting Pest tests, and use Evals when the project gains behavior involving an LLM or agent;
- keep the Pest PHPStan and Rector plugins enabled so Pest test syntax participates in static analysis and automated refactoring.

Pest features are selected for the behavior under test, not added ceremonially. If a new Pest 5 feature or an installed plugin applies to a change, use it; if it does not apply, retain the normal focused-test and `composer app:check` workflow.

## Engine freeze

Content discovery, metadata, route resolution, Markdown parsing, image validation, internal-reference validation, rendering, transactional publication, preview fallback, and live reload now define the finished engine. Subsequent work is limited to layout, typography, responsive behavior, themes, and visual polish unless a later explicit product need reopens engine development.

The generated 404 document and the existing Open Graph and Twitter/X metadata are explicit exceptions to this freeze. Do not add feeds, sitemaps, additional social configuration, JSON-LD, author or update metadata, image processing, Markdown images, search, pagination, drafts, plugins, deployment integrations, or additional content types without that explicit need.

## Change boundaries

Unless explicitly requested:

- don't add packages
- don't change URLs
- don't redesign the architecture
- don't implement future features
- don't perform unrelated refactoring
- don't edit generated output as if it were source
- don't delete or overwrite user content without explicit instruction

When repository files conflict, use this order: direct user instruction, this file, then README and existing code. If an implementation decision is not covered here, prefer the smallest reversible choice and document it in the code or README when it affects users.
