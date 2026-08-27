# Contributing to Snippet

Snippet is a small publishing system built for one author. Focused bug fixes, documentation corrections, accessibility improvements, and presentation refinements are welcome. Discuss a change before implementing a new product capability: the engine is frozen, and feeds, search, pagination, drafts, image processing, plugins, generated 404 pages, deployment integrations, and new content types require an explicit product decision.

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

Native PHP contributors may use `composer app:fix`, `composer app:analyse`, `composer app:check`, and `composer app:audit`; see [INSTALL.md](INSTALL.md) for requirements.

## Pull requests

Use a conventional pull-request title because Snippet squash-merges the PR title and Release Please derives versions from that commit:

- `fix: ...` for a patch release;
- `feat: ...` for a minor release;
- `feat!: ...` or a `BREAKING CHANGE:` footer for a major release; and
- `docs:`, `test:`, `refactor:`, `build:`, `ci:`, or `chore:` for changes that should not publish a release by themselves.

Use `feat(ui): ...` for a user-visible presentation feature; Conventional Commits reserves `style:` for formatting-only changes. Keep the PR description clear about behavior, tests, and any user-facing migration. CI must pass and review conversations must be resolved before the PR is squash-merged.

For complete architectural and coding constraints, read [AGENTS.md](AGENTS.md).
