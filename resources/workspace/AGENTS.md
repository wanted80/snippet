# Snippet publication

For work on this publication's content, configuration, or appearance, read the
[Snippet authoring skill](.agents/skills/snippet-authoring/SKILL.md).

Use the Snippet command or pinned Docker image chosen for this workspace. Record
that invocation here when configuring the site so future sessions can reuse it.
Start with `inspect capabilities --json` and load the relevant installed contracts.

Keep the author's language, voice, and existing work. Edit source files under
`content/` and `site/`; `public/` is generated output. Validate and build after
source changes, and report any remaining errors or warnings.

Optional ordered `profiles` links in site configuration require an HTTPS URL and at least a label or icon. Omitted labels produce icon-only links with the URL as their accessible name; supported icon names are `github`, `mastodon`, `bluesky`, `linkedin`, `instagram`, `youtube`, `x`, and `generic`.

An optional `csp` array adds trusted HTTPS origins to `script-src`, `connect-src`, `style-src`, `img-src`, or `font-src`. Each directive accepts an ordered list of at most 32 origins without paths, trailing slashes, credentials, queries, fragments, or wildcards. Load external scripts from `site/site.js`; same-origin resources remain allowed and inline scripts remain blocked.

Set `'csp' => false` to omit the CSP meta tag from all generated HTML when the host manages the complete policy through response headers. Omitting `csp` or using `[]` retains the default meta policy. Snippet does not generate replacement response headers.
