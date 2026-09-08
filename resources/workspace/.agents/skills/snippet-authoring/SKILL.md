---
name: snippet-authoring
description: Create and edit pages, articles, configuration, and CSS in a Snippet publication using its installed CLI contracts. Use for site authoring and build validation; engine development follows the generator repository's contributor instructions.
---

# Snippet authoring

Work within the author's requested content and design changes. The installed
Snippet CLI describes the current authoring rules; inspect it instead of copying
metadata schemas, CSS token inventories, or Markdown rules into this skill.

## Find the workspace command

Run commands from the publication root containing `content/` and `site/`.
Reuse its configured Snippet command, wrapper, or pinned Docker image from the
project instructions, README, Compose configuration, or user's setup. A content
workspace does not normally contain the engine's `bin/snippet` or PHP dependencies.
If the invocation is missing, ask which installed engine or image the author uses.

For Docker, set `SNIPPET_IMAGE` to that selected local tag, released tag, or digest
and use this helper in the shell where commands run:

```sh
snippet() {
    docker run --rm --network none \
        --user "$(id -u):$(id -g)" \
        --mount "type=bind,source=$(pwd),destination=/workspace" \
        "${SNIPPET_IMAGE:?Set the workspace's Snippet image}" "$@"
}
```

A configured direct PHP installation uses its absolute `bin/snippet` path instead.
Keep the selected engine version throughout the task; changing it is a separate
author decision.

## Discover and edit

Run `snippet inspect capabilities --json` first. Here and below, `snippet` means
the workspace invocation resolved above. Read the installed contracts needed by
the task:

- `snippet inspect content --json` for creating or editing pages and articles;
- `snippet inspect config --json` for `site/config.php` changes;
- `snippet inspect theme --json` for appearance changes in `site/site.css`.

Read the existing author files before editing. Inspection describes the installed
engine: theme values are engine defaults, and configuration starter values do
not describe the current site. Preserve the author's content and customization.

Use the advertised `new page` or `new article` command with `--json` for new
content. Its successful result lists files that are still incomplete; finish
the Markdown and metadata before validation. Choose an explicit article date
when the author's intended date differs from the command's current UTC date.

For a workspace that needs initialization, Docker's `init --json` adds missing
files and reports existing files as skipped. Existing `AGENTS.md` and skills are
author-owned; repeated initialization preserves them.

## Validate the result

After source edits, run `snippet validate --json`, then `snippet build --json`.
Check the exit status and the JSON object: status `0` means success, `1` an
operation failure, and `2` invalid usage. Responses include `schema` and
`snippet_version`; use the `snippet.agent/v1` contract. Read `error.code` and
`error.message` on failure and surface any build `warnings`. A cleanup warning
can accompany a successful publication.

When appearance changes, use the workspace's interactive preview and check light
and dark modes and a narrow viewport. Preview does not accept `--json`.
Building writes disposable static files to `public/`; deploying those files is
a separate action governed by the author's request.
