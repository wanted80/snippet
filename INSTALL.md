# Installing and running Snippet

Docker with GNU Make is the recommended way to run Snippet. It provides the exact PHP version and extensions, keeps Composer dependencies in isolated volumes, and offers an HTTPS preview without installing PHP on the host. A devcontainer and a native PHP workflow are available as alternatives.

## Prerequisites

### Linux

Install Git, GNU Make, Docker Engine, and the Docker Compose plugin. Configure Docker so your user can run `docker compose` without prefixing every command with `sudo`.

### macOS

Install Git and Make through the Xcode Command Line Tools, install Docker Compose as either the Docker CLI plugin or the standalone `docker-compose` command, and install Docker Desktop or another Docker-compatible engine such as Colima. Start the selected engine before running any Make target.

### Windows

Use a WSL 2 Linux distribution with Git and GNU Make installed inside it. Install Docker Desktop, enable its WSL integration for that distribution, and run all repository and Make commands from the WSL shell. Keeping the clone in the WSL filesystem avoids cross-filesystem permission and performance problems.

Native Windows shells are not a supported workflow. The Docker preview remains reachable from the Windows browser.

## Get the project

Clone the public starter when evaluating Snippet or contributing to it:

```bash
git clone https://github.com/wanted80/snippet.git
cd snippet
```

For a personal site, keep private content and customization in a separate private downstream repository. Create an empty private repository first, without an initial README or license, then run:

```bash
git clone https://github.com/wanted80/snippet.git my-site
cd my-site
git remote rename origin upstream
git remote add origin git@github.com:your-name/my-private-site.git
git push -u origin main
```

The private `origin` owns the site. The public starter remains available as `upstream`:

```bash
git fetch upstream
git merge upstream/main
```

Resolve updates as ordinary downstream changes. Personal content and customizations can stay private without changing the visibility of the public repository.

## Recommended Docker setup

The root `compose.yaml` and `Makefile` are the supported Docker interfaces. Copy the example environment file if you want persistent local defaults:

```bash
cp .env.example .env
```

This step is optional because Compose supplies the same defaults. `.env` controls local Docker orchestration only: Git and Docker build contexts ignore it, the builder does not read it, and it is never copied into `public/`.

The Makefile prefers the `docker compose` plugin and falls back to the standalone `docker-compose` command. Override detection explicitly when needed, for example with `make COMPOSE=docker-compose docker-config`.

The available settings are:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ENVIRONMENT` | `development` | Selects the development or production image and dependency volume. |
| `PREVIEW_PORT` | `8443` | Sets the host port for Docker HTTPS preview. |
| `IMAGE` | `snippet` | Sets the local application image name. |
| `TAG` | `local` | Sets the local image tag prefix. |
| `LOCAL_UID` | `1000` | Creates the container user with this host user ID. |
| `LOCAL_GID` | `1000` | Creates the container group with this host group ID. |

On Linux or WSL, use `id -u` and `id -g` to find the current IDs and update `.env` when they differ from the defaults. Matching them lets generated files remain owned by the host user.

Shell environment values and Make command-line assignments take precedence over `.env`. A one-shot assignment is useful when testing a different environment or port:

```bash
ENVIRONMENT=production make docker-build
make docker-preview PREVIEW_PORT=9443
```

Development is the default and contains the complete locked quality toolchain. Production installs only non-development Composer dependencies and is intended for checking runtime validation, builds, and previews. Development and production use separate named `vendor` volumes, so switching environments cannot mix their dependency sets.

### Official builder image

Stable releases publish a dependency-free Snippet builder for `linux/amd64` and `linux/arm64` at `ghcr.io/wanted80/snippet`. This is an alternative for repositories that contain only their publication inputs: `content/`, `site/`, and `resources/`. The image keeps the engine at `/app`, reads those inputs from `/workspace`, and writes the generated `public/` directory back to the mounted repository.

Use an exact release such as `v1.3.0` for reproducible builds, or pin an immutable image digest. The `vX.Y`, `vX`, and `latest` tags intentionally move to newer stable releases.

```bash
docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v1.3.0 \
  build
```

Replace `build` with `validate` to check the publication without changing `public/`, or with `--version` to report the packaged Snippet version. The official image does not provide preview or draft-creation commands.

After the first image is published, a repository owner must open the package under the repository's **Packages** section and change its visibility to public once in **Package settings**. Subsequent versions can then be pulled anonymously. Container packages appear under Packages, not Deployments.

### Build, validate, and preview

Create a minimal incomplete draft from the development shell:

```bash
make docker-shell
bin/snippet new page contact
bin/snippet new article first-post
bin/snippet new article older-note --date=2026-07-01
```

There is no dedicated Make target for draft creation. Enter `make docker-shell` and use the canonical command so its page and article arguments remain available. An omitted article date means today in UTC. The command creates only the new content unit, refuses an existing destination, leaves `public/` unchanged, and warns that its empty Markdown, title, and description must be completed before validation or building can succeed.

Every operational target that needs the application automatically builds the selected image and synchronizes its dependency volume. Validate the configuration and content without changing `public/`:

```bash
make docker-validate
```

Generate the site:

```bash
make docker-build
```

`build` validates first, renders into a unique temporary sibling directory, and replaces `public/` only after the complete build succeeds. An existing valid publication remains intact after a failed build.

The first time, trust Snippet's local development certificate and start the live preview:

```bash
make docker-preview-trust
```

This may ask for your computer password. When it is ready, close every browser window, reopen the browser, and visit `https://localhost:8443/snippet/` for the repository's configured site URL. Use `make docker-preview` on later runs. Both commands stay attached and report rebuilds or invalid edits. Press Ctrl+C to stop the attached services. To ensure the preview containers and network are removed, including from another terminal, run:

```bash
make docker-preview-down
```

Shutdown retains the dependency volumes and Caddy's local certificate authority. Preview startup and shutdown remove orphan containers by default; preserve them for an exceptional Compose workflow with the same override on both commands:

```bash
make docker-preview REMOVE_ORPHANS=0
make docker-preview-down REMOVE_ORPHANS=0
```

The preview watches `content/`, `site/`, and `resources/`. After a successful edit it rebuilds `public/` and reloads open pages. Changes beneath `bin/` or `src/` restart the preview with freshly loaded runtime code when PCNTL is available; otherwise the terminal asks you to restart it manually. Invalid edits are reported in the terminal while the last valid site remains available. The reload helper is served only during preview and is never written into published HTML.

Other useful targets are:

```bash
make docker-image
make docker-install
make docker-shell
make docker-config
make docker-test
make docker-analyse
make docker-audit
make docker-lint
make docker-fix
make docker-check
```

`docker-shell` always opens the development Zsh environment, even if `.env` selects production. The test, analysis, audit, lint, fix, and complete-check targets make the same development selection because the production image intentionally omits those tools. `docker-config` renders and validates the currently selected Compose configuration. `docker-check` also checks the repository shell scripts with ShellCheck and verifies the theme JavaScript syntax with Node. `docker-audit` queries current Composer advisory data and therefore requires network access; it remains separate from the deterministic complete gate. Run `make help` for the complete target summary.

### Image cache and refresh controls

Application image builds use Docker's local layer cache and existing base images by default. Refresh base images with `PULL=1`, bypass every application build layer with `NO_CACHE=1`, or combine them:

```bash
make docker-image PULL=1
make docker-image NO_CACHE=1
make docker-image PULL=1 NO_CACHE=1
```

These controls also apply when an image build is reached through another target. With `PULL=1`, either preview command refreshes the Caddy image as well.

### Local HTTPS

Docker preview starts Caddy only for the preview profile. Caddy proxies `https://localhost:8443` to Snippet's internal HTTP server, generates a local certificate, and sends the same framing, MIME-sniffing, and referrer protections recommended for deployment. `make docker-preview-trust` installs its root certificate on macOS, Windows through WSL, and Linux systems using the Debian, Ubuntu, Alpine, Arch, Fedora, Red Hat, or SUSE trust-store layouts. Other Linux systems with p11-kit use its `trust` interface. On an unknown host, the command preserves the certificate and prints its path for manual import instead of guessing a system directory. Named Docker volumes preserve the same local authority between previews. The certificate protects only local preview traffic and is not a deployment certificate.

The static hosting provider owns the public domain, upload configuration, HTTPS termination, and certificate renewal. Snippet does not deploy Caddy or any PHP runtime; publish only the generated contents of `public/`.

## Devcontainer alternative

The devcontainer uses the root `compose.yaml` plus `compose.dev.yaml`. The override selects the same development image, `/app` workspace, PHP extensions, Composer dependency set, and command-line tools as the Docker development workflow. It keeps the application container alive for the editor and adds editor lifecycle setup; it is not a separate build environment. Its `snippet-dev` Compose project name isolates the editor container from the host `snippet` project, so starting or stopping the Docker preview cannot replace the editor container.

Install Docker, Visual Studio Code, and the Dev Containers extension. If you use the included Codex integration, ensure the host `~/.codex` directory exists and uses file-based authentication. The devcontainer mounts the complete directory read/write, then presents it to the non-root container user through an ownership-mapped FUSE view. The host and container therefore use the same Codex configuration, authentication, sessions, and local state without copying them or requiring another login. Keep Codex current on both sides because both installations access the same state files, and do not remove the FUSE capability or device from `compose.dev.yaml`.

The Docker engine must be able to access `~/.codex`. Docker Desktop normally shares the user profile. Engines backed by a separately configured VM may expose fewer paths. For Colima, add the Codex directory as a writable mount with `colima start --edit`, alongside any existing mounts, and restart Colima before opening the devcontainer:

```yaml
mounts:
  - location: ~/Projects
    writable: true
  - location: ~/.codex
    writable: true
```

Then open the clone in Visual Studio Code and choose **Dev Containers: Reopen in Container**. If the engine exposes an empty or read-only Codex directory, the container stops with an actionable error instead of silently creating separate state. The post-create step repairs the shared development vendor volume, trusts the container workspace in the shared Codex configuration, and synchronizes the locked dependencies. The persistent Codex home is shared, while its ephemeral `tmp/` directory is container-local so Codex's arg0 helper cleanup does not cross the bindfs mount.

Inside the container, use the canonical commands directly:

```bash
bin/snippet new page contact
bin/snippet new article first-post
bin/snippet validate
bin/snippet build
bin/snippet preview
```

The direct preview is available at `http://127.0.0.1:8080/snippet/` for the repository's configured site URL; editor port forwarding may expose it through an equivalent local address. Press Ctrl+C to stop it.

The direct and Docker HTTPS previews both watch the same source tree and publish the same `public/` directory. Run only one preview at a time even though their containers are isolated.

## Native PHP alternative

Install PHP 8.5 or newer, Composer, and the production PHP extensions listed under `require` in `composer.json`: Date, Filter, Hash, Mbstring, PCRE, Random, and Tokenizer. A contributor installation also needs the development extensions listed under `require-dev`: JSON, PCNTL, PCOV, and POSIX. The CLI must permit process creation for preview. PCNTL provides graceful preview signal handling, PCOV drives coverage, and POSIX supports the process and filesystem integration tests. Then install the locked dependencies:

```bash
composer install
```

Use the CLI directly:

```bash
bin/snippet new page contact
bin/snippet new article first-post
bin/snippet validate
bin/snippet build
bin/snippet preview
```

Native preview builds the site, serves it over direct HTTP at the complete address it prints—`http://127.0.0.1:8080/snippet/` for this repository—watches the source directories, and reloads after successful changes. When a deployment path is configured, `/` redirects to that mount, files outside it are not served, and live-reload endpoints stay beneath it. Override its validated bind address when necessary:

```bash
bin/snippet preview --host=127.0.0.1 --port=9000
```

Composer aliases include `composer app:new -- article first-post`, `composer app:build`, `composer app:content:validate`, and `composer app:preview`. Contributors can run the complete deterministic PHP gate with `composer app:check` and the network-dependent locked dependency audit with `composer app:audit`. The Docker `make docker-check` target additionally checks the project shell scripts and theme JavaScript.

## Contributing and releases

All pull requests run the Docker development gate and production validation in GitHub Actions. Before opening one, follow the focused-test, fix, analysis, check, and audit workflow in [CONTRIBUTING.md](CONTRIBUTING.md).

Snippet uses squash merges and conventional pull-request titles. Release Please converts `fix`, `feat`, and breaking-change commits into Semantic Versioning updates, maintains `CHANGELOG.md`, and creates `vX.Y.Z` releases. Each stable published release builds the tagged source and publishes `ghcr.io/wanted80/snippet` for AMD64 and ARM64 with OCI metadata and provenance. Generated `public/` output is not attached to releases and remains a local publication artifact.

## Content validation during writing

An article may set `'cover' => true` in `meta.php` and optionally provide a trimmed non-empty `alt` string. Cover defaults to `false`; when enabled, exactly one `cover.jpg`, `cover.png`, or `cover.webp` must exist directly in the article directory. Snippet verifies the detected format, derives its dimensions, copies the original bytes, and renders the shared `.article-figure` template only on the article page and the full featured-home article. Missing or ambiguous covers fail validation. Pages reject `cover` and `alt`, and Markdown image syntax remains unsupported.

Authored headings must begin at level one and must not skip levels. Link labels cannot be blank. Internal root-relative, item-relative, and same-origin absolute links beneath the configured deployment path must resolve to a generated route, explicit generated `index.html`, or copied asset. Root-relative Markdown remains portable: `/about/` renders as `/snippet/about/` when `site.url` ends in `/snippet`; item-relative links remain relative. Same-origin absolute links outside the configured deployment path are outside this publication. After removing the query and fragment, validation percent-decodes each path segment, normalizes `.` and `..`, and percent-encodes the normalized segments before matching the generated inventory. Raw `/tags/café/` and encoded `/tags/caf%C3%A9/` are equivalent; encoded dot segments such as `%2e` and `%2e%2e` still participate in traversal checks, including rejection above the site root. External HTTP(S) targets and fragment identifiers are not checked. A failure reports the source Markdown path and line, and both build and preview preserve the last valid publication.

Page and article directory slugs use author-chosen lowercase ASCII letters and numbers separated by single hyphens. Titles may use any language: a title such as `日本語` can use the directory slug `nihongo`, and Snippet never transliterates it automatically.

Tag labels may also use any language, but their slugs are generated rather than author-chosen. `Café` produces the Unicode slug and output directory `café`, while its emitted URL segment is UTF-8 percent-encoded as `caf%C3%A9`; `日本語` remains Unicode in the slug and output directory and is likewise encoded whenever its URL is emitted.

The engine is frozen after these content-integrity rules. Unless an explicit product need reopens it, continue customization through layout, typography, responsive behavior, themes, and visual polish rather than adding feeds, sitemaps, metadata systems, image processing, or new content types.

## Normal writing and publishing workflow

1. Edit `site/config.php`. Set `url` to the complete public HTTPS site URL, including a deployment path such as `https://wanted80.github.io/snippet`, because it drives canonical URLs and public paths. Omit the path only for a root-hosted site.
2. Replace the example directories under `content/pages/` and `content/articles/` with your own self-contained content units. Use `bin/snippet new page <slug>` or `bin/snippet new article <slug> [--date=YYYY-MM-DD]` to start an intentionally incomplete skeleton, then fill both generated files.
3. Customize `site/assets/`, `site/theme.css`, and `resources/templates/` as needed.
4. Run `make docker-preview` while writing and review successful rebuilds in the browser.
5. Run `make docker-validate`, then `make docker-build` for the final static output.
6. Upload only the contents of `public/` to the chosen static host and configure the response headers documented in README. For this repository, a successful `Quality` workflow on `main` builds and deploys that directory automatically. Dispatch `Quality` manually when a manual publication is needed.

For the devcontainer or native workflow, substitute `bin/snippet preview`, `bin/snippet validate`, and `bin/snippet build`. Do not upload the source repository, Composer dependencies, `.env`, Docker configuration, or PHP files. A production Docker build changes the dependency set used to generate the same static output; it is not a long-running production server.

### GitHub Pages

Before publishing this repository, review the complete Git history and existing Actions logs for private content, personal data, and credentials: public visibility exposes both. Then make the repository public and select **Settings → Pages → Build and deployment → GitHub Actions**.

The separate `Quality` workflow keeps pull requests quality-only. After it succeeds on `main`, the `Pages` workflow checks out the tested revision, builds with `ENVIRONMENT=production make docker-build`, uploads only `public/`, and deploys it to the protected `github-pages` environment. Dispatch `Quality` manually when a manual publication is needed. Keep `public/` ignored; do not create a `gh-pages` branch or commit generated output. With the checked-in configuration, the published address is `https://wanted80.github.io/snippet/`.

GitHub Pages owns HTTPS and does not offer project-controlled response headers. Snippet's meta CSP remains effective for supported directives, but Pages cannot be configured here to add every recommended framing, MIME-sniffing, and referrer response header.

## Troubleshooting

### A preview port is already in use

Choose another Docker host port:

```bash
make docker-preview PREVIEW_PORT=9443
```

Then open `https://localhost:9443/snippet/` for the checked-in configuration. For a direct preview, use `bin/snippet preview --port=9000`. Run `make docker-preview-down` if an earlier Docker preview still owns the configured port.

### An image is unexpectedly stale

Force an application rebuild and refresh its base image:

```bash
make docker-image PULL=1 NO_CACHE=1
```

Use `PULL=1 make docker-preview` when the Caddy image must also be refreshed.

### The browser still warns about the local certificate

Close every browser window and reopen the browser after running `make docker-preview-trust`. In Firefox, also ensure **Settings > Privacy & Security > Certificates > Allow Firefox to automatically trust third-party root certificates you install** is selected, then restart Firefox.

### Compose is using unexpected values

Render the resolved configuration:

```bash
make docker-config
ENVIRONMENT=production make docker-config
```

Check Make command-line assignments, the shell environment, and `.env` in that precedence order. Ensure Docker Compose is reading commands from the repository root.

### Generated files have the wrong owner

Set `LOCAL_UID` and `LOCAL_GID` in `.env` to the values reported by `id -u` and `id -g`, then rebuild the image:

```bash
make docker-image NO_CACHE=1
```

If an existing dependency volume was created with different IDs, recreate only that disposable volume as described below.

### Composer dependencies differ between environments

The Compose project-specific volumes for development and production are intentionally isolated and contain dependencies only. Synchronize the selected one with `make docker-install`. If a volume is corrupt or has obsolete ownership, stop preview, identify its exact name with `docker volume ls`, and remove only that volume before reinstalling:

```bash
make docker-preview-down
docker volume rm snippet_vendor-development
make docker-install
```

Use `snippet_vendor-production` for the default production volume. The devcontainer uses the separate `snippet-dev_vendor-development` volume. Removing these volumes does not remove authored content or `public/`; Composer recreates the selected dependency set from `composer.lock`. The separate `snippet_caddy-data` volume contains the local certificate authority; removing it creates a new authority that must be trusted again.
