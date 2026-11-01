# Preview and validation

Preview runs the real build, serves it locally, and watches your content and configuration. Save a valid edit and open pages reload. Save an invalid edit and the previous working publication remains available while you fix the error.

From your site's directory:

```bash
docker run --rm --init --user "$(id -u):$(id -g)" \
  --publish 127.0.0.1:8080:8080 --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v4.5.0 preview --host=0.0.0.0 --port=8080 # x-release-please-version
```

Open `http://127.0.0.1:8080`. The host port is restricted to your computer; `0.0.0.0` lets Docker reach the server inside its container. Stop it with Ctrl+C. If the port is busy, change the host mapping to `127.0.0.1:9000:8080` and open port 9000 instead.

## Three commands, three purposes

Using the helper from [Start your own site](/articles/start-your-site/):

- `snippet validate` checks source without changing `public/`.
- `snippet build` validates and publishes the complete static site.
- `preview` builds, serves, watches, and reloads locally, using the port-enabled Docker command above.

Validation checks configuration, metadata, dates, slugs, tags, supported Markdown, covers, assets, and internal links. Diagnostics identify the source path and field or line when possible. Fix the reported source and rerun the command.

## A failed edit does not erase a working site

Publication first validates everything, builds in a unique temporary sibling directory, and replaces `public/` only when every page and asset succeeds. An invalid edit cannot leave a half-built publication. The first preview still needs a valid build before there is anything to serve.

The live-reload helper is injected into preview responses only. It is never saved in the published HTML. The official image watches your mounted `content/` and `site/`; its engine and templates are immutable.

## Reproducible output

The same source and builder version produce the same published bytes. Article ordering, tag ordering, routes, and assets are deterministic. CSS is conservatively minified and entry assets are fingerprinted; HTML is published as rendered and JavaScript is copied unchanged.

Production hosts serve only `public/`. They do not run the preview server. For Compose examples, extra container isolation, and troubleshooting, use the [installation guide](https://github.com/wanted80/snippet/blob/main/INSTALL.md#content-only-container-preview).

Next: [Publish your site](/articles/publish-your-site/).
