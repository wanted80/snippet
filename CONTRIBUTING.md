# Contributing to Snippet

Snippet is a small publishing system built for one author. Focused bug fixes, documentation corrections, accessibility improvements, and presentation refinements are welcome. Discuss a change before implementing a new product capability: the engine is frozen, and feeds, search, pagination, drafts, image processing, plugins, deployment integrations, and new content types require an explicit product decision.

## Development workflow

Docker with GNU Make is the supported contributor workflow. Start from a branch based on `main`, keep each change focused, and avoid committing generated `public/` output.

For a behavior change, add or update the smallest focused Pest test first. Run that test while iterating, then complete the project sequence:

```bash
make docker-fix
make docker-analyse
make docker-check
make docker-audit
```

`docker-check` runs the exact line-coverage, type-coverage, formatting, refactoring, static-analysis, content-validation, shell, and JavaScript checks used by CI. `make docker-mutations` is the deliberately separate, resource-intensive target for the complete Pest suite: it bypasses focused mutation declarations, mutates every covered source class, and requires a 100% score. `docker-audit` queries Composer's advisory data separately because it requires network access. Application code must remain free of third-party runtime packages.

Enable the tracked pre-push hook once per clone:

```bash
make hooks-install
```

Devcontainer setup enables it automatically. Every push runs `make pre-push-check` and stops on the first failure. On the host this validates both Compose environments, runs `docker-check`, smoke-tests the packaged production and release builder images, builds the demo, and audits Composer dependencies, using the same targets as the Quality workflow. Docker must be running; the audit and dependency installation require network access. The workflow's report-only Trivy scan stays in CI.

Inside the devcontainer the hook runs the same application, shell, and JavaScript checks directly, followed by the Composer audit. Run `make pre-push-check` on the host to include Compose and image checks, which require host Docker access. Checks validate the current working tree; commit the changes you intend to push and keep that tree aligned with them. The hook does not format or commit files. Git hooks are local safeguards; CI remains authoritative.

Native PHP contributors may use `composer app:fix`, `composer app:analyse`, `composer app:check`, and `composer app:audit`; see [INSTALL.md](INSTALL.md) for requirements.

## Browser regression testing

The architectural decision for browser regression testing approves
`pestphp/pest-plugin-browser` (Pest 5), its Composer development dependency
closure, and the npm development dependency `playwright` (including
`playwright-core`). Computed CSS, native popovers, media preferences, and cascade
precedence require a real browser. Pest runs the same theme suite, including
navigation, headlessly and serially in Chromium, Firefox, and WebKit against
temporary publications over container-local HTTP. Tests require no external
network and record no screenshots, videos, or traces.

Composer and npm lock files pin development tooling. Only the Docker development
stage installs Node dependencies, Chromium, Firefox, WebKit, and browser system
libraries. npm modules live in an isolated `/app/node_modules` volume, and the browser cache is
readable by the development user. Production and release-builder stages remain
browser-free. These tools do not become runtime dependencies or add publishing
capabilities.

The test harness uses direct Pest `Webpage` assertions to avoid the plugin's
automatic failure screenshots. It adapts the pinned browser plugin's process
command to `exec` Node so normal Pest shutdown also reaps the transport server.
A small adapter uses Playwright's shared protocol for native print/media emulation
and held-pointer assertions absent from Pest's PHP page API. Computed sRGB colors
are compared numerically with a 0.000001 channel tolerance, preserving alpha
checks without requiring identical decimal serialization. Touch taps are checked
separately from pressed styles, since engines clear `:active` at different points
in the tap/click sequence.

Playwright 1.65 does not expose portable reduced-transparency emulation. That
native-preference test retains its Chromium CDP adapter and is explicitly skipped
in Firefox and WebKit; opaque fallback, author overrides, printing, reduced motion,
and all other theme/navigation checks run in every engine. The suite never
rewrites preference rules or mocks `matchMedia` to claim native support. WebKit
automation does not replace real Safari/iOS device checks. Review these test-only
adapters when upgrading the plugin.

## Architecture map

The publication path is intentionally linear: `Application` dispatches the CLI, `PublicationInputLoader` creates one validated snapshot containing configuration, catalog, templates, and retained fingerprinted entry assets, `Publisher` transactionally promotes the complete output, and `HtmlRenderer` turns that same snapshot into pages. In short: `Application → PublicationInputLoader → validated inputs → Publisher → HtmlRenderer`.

`Limits` contains internal safety budgets for pathological input and output. Those values are neither author configuration nor compatibility promises; change them only when evidence shows that an implementation boundary is inappropriate, and keep the matching validation tests explicit.

## Pull requests

Use a conventional pull-request title because Snippet squash-merges the PR title and Release Please derives versions from that commit:

- `fix: ...` for a patch release;
- `feat: ...` for a minor release;
- `feat!: ...` or a `BREAKING CHANGE:` footer for a major release; and
- `docs:`, `test:`, `refactor:`, `build:`, `ci:`, or `chore:` for changes that should not publish a release by themselves.

Use `feat(ui): ...` for a user-visible presentation feature; Conventional Commits reserves `style:` for formatting-only changes. Keep the PR description clear about behavior, tests, and any user-facing migration. CI must pass and review conversations must be resolved before the PR is squash-merged.

Release Please updates the builder version tag in `examples/github-pages.yml`
alongside the installation examples.

For complete architectural and coding constraints, read [AGENTS.md](AGENTS.md).
