# Installing and running Snippet

Snippet can build a site from a content-only repository with the official image, or run directly from a full source checkout. Production hosting receives only the generated `public/` directory and needs neither PHP nor Docker.

## Content-only builder usage

Install Docker Engine or Docker Desktop, then create an empty repository directory. On Windows, run these commands inside WSL 2 and keep the repository in the WSL filesystem.

Use an exact release tag for reproducible output. The examples pin v2.1.3. <!-- x-release-please-version --> `--user` prevents root-owned output, while `--volume` exposes the current repository at the image's `/workspace` path:

```bash
mkdir my-site
cd my-site

docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v2.1.3 init # x-release-please-version
```

`init` creates empty `content/articles/` and `content/pages/` collections and copies the canonical generic files from `site/` and `resources/`. Existing files win, nothing is deleted, `public/` is untouched, and the engine-owned preview router is not copied. Demo configuration and content are never included. Repeating `init` after changing the pinned image may add newly required shared files without replacing customization.

Set the complete public HTTPS URL in `site/config.php`, then create the first page or article. Rerun the command with `init` replaced by `validate` to check the site without changing `public/`, or by `build` to create the static publication. The same image creates drafts:

```bash
docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v2.1.3 new page contact # x-release-please-version

docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/workspace" \
  ghcr.io/wanted80/snippet:v2.1.3 new article first-post # x-release-please-version
```

The image exposes `--version`, `init`, `new page`, `new article`, `validate`, `build`, and the local-development `preview` command. Draft creation requires the relevant collection created by `init`, refuses symlinked or existing destinations, and leaves `public/` unchanged. The image omits Composer, development tools, and source outside those commands' runtime paths. `validate` reports the catalog and prospective asset count. `build` measures validation plus transactional publication and reports the actual promoted asset and file counts. Failures retain the existing `public/` directory.

Moving release aliases and `latest` are convenient for evaluation but unsuitable for reproducible publication. Pin a full release such as `v2.1.3` or an immutable image digest. <!-- x-release-please-version -->

## Building a separate repository

The builder can operate on any publication repository without copying the Snippet engine into it. Mount the absolute repository path at `/workspace`:

```bash
SITE_DIRECTORY=/absolute/path/to/my-site

docker run --rm \
  --user "$(id -u):$(id -g)" \
  --volume "$SITE_DIRECTORY:/workspace" \
  ghcr.io/wanted80/snippet:v2.1.3 build # x-release-please-version
```

The mounted repository owns only publication inputs and disposable output. Commit `content/`, `site/`, and `resources/`; ignore `public/`. Do not upload the source repository or container to the web host.

If the repository is private, the builder does not need Git credentials or network access because it reads only the mounted checkout.

## Optional local container hardening

For additional isolation, run the builder without networking or capabilities and with a read-only image filesystem. This example builds the current repository; replace `build` with `init` or `validate` when needed:

```bash
docker run --rm \
  --network none \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges \
  --user "$(id -u):$(id -g)" \
  --mount type=bind,src="$PWD",dst=/workspace \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
  ghcr.io/wanted80/snippet:v2.1.3 build # x-release-please-version
```

Docker may need network access to pull the image before the container starts; `--network none` applies to the running builder.

## Content-only container preview

The same pinned image serves Snippet's live authoring preview without host PHP, Node, or a generator checkout:

```bash
docker run --rm --init \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges \
  --user "$(id -u):$(id -g)" \
  --publish 127.0.0.1:8080:8080 \
  --mount type=bind,src="$PWD",dst=/workspace \
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
  ghcr.io/wanted80/snippet:v2.1.3 preview --host=0.0.0.0 --port=8080 # x-release-please-version
```

Visit `http://127.0.0.1:8080/`, followed by the deployment path from `site/config.php` when one is configured. The container must bind PHP to `0.0.0.0` so Docker can forward the port, but Docker publishes that port only on the host's `127.0.0.1`; do not replace the host-side address with an unrestricted binding. Change both `8080` values to select another port.

Preview validates and builds before serving, watches `content/`, `site/`, and `resources/`, live-reloads after successful changes, and keeps the last valid site available after an invalid edit until it is corrected. A deployment-path change restarts the image-owned local server automatically. Ctrl+C or `docker stop` terminates the watcher and child PHP server; `--init` provides normal container process reaping. Preview opens no browser and is for local development only, never production serving.

The router remains immutable engine code at `/app/resources/preview-router.php`. It is neither copied into the mounted publication by `init` nor required beneath `/workspace`. A later ordinary `build` transaction replaces preview output and cannot retain the injected reload helper or preview-only version endpoint.

Keep isolated build commands and local preview as separate Compose services:

```yaml
services:
  snippet:
    image: ghcr.io/wanted80/snippet:v2.1.3 # x-release-please-version
    working_dir: /workspace
    network_mode: none
    read_only: true
    cap_drop:
      - ALL
    security_opt:
      - no-new-privileges:true
    volumes:
      - .:/workspace
    tmpfs:
      - /tmp:rw,noexec,nosuid,nodev,size=16m

  snippet-preview:
    image: ghcr.io/wanted80/snippet:v2.1.3 # x-release-please-version
    working_dir: /workspace
    command: preview --host=0.0.0.0 --port=8080
    init: true
    read_only: true
    cap_drop:
      - ALL
    security_opt:
      - no-new-privileges:true
    ports:
      - "127.0.0.1:8080:8080"
    volumes:
      - .:/workspace
    tmpfs:
      - /tmp:rw,noexec,nosuid,nodev,size=16m
```

Some Docker environments, including Colima configurations, suppress published host ports when a container uses an `internal: true` bridge. The example therefore uses Compose's ordinary project bridge and relies on the narrow `127.0.0.1` publication for inbound protection. Snippet preview makes no outbound requests, but this bridge does not enforce outbound isolation; add an internal network only after confirming that published loopback ports remain reachable on the target Docker platform.

`docker compose run` does not publish service ports unless `--service-ports` is present; a downstream Make target can preserve host ownership and stay attached with:

```make
preview:
	docker compose run --rm --service-ports --user "$$(id -u):$$(id -g)" snippet-preview
```

Continue using `docker compose run --rm --user "$$(id -u):$$(id -g)" snippet build` for production output; the `snippet` service remains network-disabled.

## Direct PHP usage from a full checkout

Clone Snippet when developing the engine or when using its direct PHP CLI:

```bash
git clone https://github.com/wanted80/snippet.git
cd snippet
composer install
```

Install PHP 8.5 or newer, Composer, and the production extensions declared in `composer.json`: Date, Filter, Hash, Mbstring, PCRE, Random, and Tokenizer. Contributors also need the development extensions in `require-dev`, including PCNTL, PCOV, and POSIX.

Run the canonical CLI from the publication workspace. The executable may live in the separate Snippet checkout:

```bash
/path/to/snippet/bin/snippet new page contact
/path/to/snippet/bin/snippet new article first-post
/path/to/snippet/bin/snippet new article older-note --date=2026-07-01
/path/to/snippet/bin/snippet validate
/path/to/snippet/bin/snippet build
/path/to/snippet/bin/snippet preview
```

An article without `--date` uses the current UTC date. Draft creation deliberately produces incomplete metadata and Markdown, never replaces an existing content directory, and never changes `public/`. Complete the draft before validation.

Direct preview serves HTTP at `http://127.0.0.1:8080` by default, watches `content/`, `site/`, and `resources/`, reloads fresh contributor runtime after changes beneath `bin/` or `src/`, preserves the last valid output after an invalid edit, and reloads open pages after a successful rebuild. Use a different validated address when needed:

```bash
bin/snippet preview --host=127.0.0.1 --port=9000
```

Inside the generator checkout, `composer app:content:validate` validates the composed demo site. The repository root itself is not a publication workspace.

## Contributor Docker, Make, and HTTPS preview

Docker with GNU Make is the recommended full-checkout environment. It supplies the exact PHP version and extensions, isolates Composer dependencies in named volumes, and provides an HTTPS preview through Caddy.

Install Git, GNU Make, Docker, and Docker Compose. Linux users should configure Docker for their user. macOS users may use Docker Desktop or Colima. Windows users should use WSL 2 with Docker Desktop integration.

```bash
git clone https://github.com/wanted80/snippet.git
cd snippet
cp .env.example .env
make docker-preview-trust
```

`make docker-preview-trust` may request the host password to install Caddy's local root certificate. Close all browser windows afterward, reopen the browser, and visit `https://localhost:8443` at the configured deployment path. Later previews use:

```bash
make docker-preview
make docker-preview-down
```

The preview stays attached and reports rebuilds. Ctrl+C stops the attached services; `make docker-preview-down` removes their containers and network while retaining dependency volumes and Caddy's local certificate authority. Docker's certificate is local preview material only. The static host owns public HTTPS.

Useful contributor targets are:

```bash
make docker-validate
make docker-build
make docker-shell
make docker-config
make docker-test
make docker-analyse
make docker-audit
make docker-lint
make docker-fix
make docker-check
make builder-smoke
make demo-check
```

`docker-check` runs exact source line and type coverage, Pint, Rector, PHPStan, composed-demo validation, ShellCheck, and JavaScript syntax validation. `docker-audit` remains separate because advisory data needs the network. `builder-smoke` checks the release image, its empty-workspace initialization lifecycle, and hardened content-only preview behavior; `demo-check` composes root shared files with `demo/`, validates the complete existing site, and proves its production build succeeds.

The optional `.env` controls local orchestration only. Its principal settings are:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ENVIRONMENT` | `development` | Select the development or production image and dependency volume. |
| `PREVIEW_PORT` | `8443` | Select the host HTTPS preview port. |
| `IMAGE` | `snippet` | Select the local application image name. |
| `TAG` | `local` | Select its tag prefix. |
| `LOCAL_UID` | `1000` | Match the host user's ID. |
| `LOCAL_GID` | `1000` | Match the host group's ID. |

Shell and Make assignments override `.env`, for example:

```bash
ENVIRONMENT=production make docker-build
make docker-preview PREVIEW_PORT=9443
make docker-image PULL=1 NO_CACHE=1
```

The root production and development images, Compose services, and Caddy flow are contributor tools. They remain separate from the small official builder image.

### Devcontainer

The devcontainer uses `compose.yaml` plus `compose.dev.yaml`, the same development dependency volume and toolchain, and an isolated `snippet-dev` Compose project. Install Visual Studio Code and the Dev Containers extension, then choose **Dev Containers: Reopen in Container**.

The included Codex integration bind-mounts the host `~/.codex` and exposes it through an ownership-mapped FUSE view. Ensure that directory exists, is writable by Docker, and uses file-based authentication. Colima users may need to add it as a writable VM mount before opening the container:

```yaml
mounts:
  - location: ~/Projects
    writable: true
  - location: ~/.codex
    writable: true
```

Inside the container, use the canonical `bin/snippet` commands. Run only one direct or Docker preview at a time because both publish the same host `public/` directory.

## Optional CSS and JavaScript customization

Snippet publishes assets with ownership-reflecting names:

| Source | Output | Behavior |
| --- | --- | --- |
| `resources/theme.css` | `/assets/theme.<xxh3>.css` | Required built-in theme; minified when configured, then fingerprinted from the published bytes. |
| `resources/theme.js` | `/assets/theme.<xxh3>.js` | Required progressive enhancement; copied byte-for-byte and fingerprinted. |
| `site/site.css` | `/assets/site.<xxh3>.css` | Optional site CSS; loaded after the built-in theme, minified when configured, then fingerprinted from the published bytes. |
| `site/site.js` | `/assets/site.<xxh3>.js` | Optional local script; copied byte-for-byte, fingerprinted, and loaded with `defer` after the built-in script. |

Both optional files must be regular non-symlink UTF-8 files within the asset-size ceiling. If one is absent, Snippet emits neither its output file nor its HTML tag. Custom JavaScript is a same-origin escape hatch; there is no bundler, external dependency, or extra CSP origin.

Existing workspaces initialized by an earlier v2 release may retain the exact released `/assets/theme.js` script tag and `/assets/theme.css` stylesheet tag in `resources/templates/layout.html`. During validation Snippet transparently maps those two exact tags to the current fingerprinted asset placeholders, so `init` does not need to overwrite the customized layout. Near-miss, modified, or incomplete legacy tags remain invalid; new scaffolds use `{{theme_script}}` and `{{theme_stylesheet}}` directly.

The no-JavaScript site remains readable and navigable. Authored content, links, and native-popover navigation work normally; the system color preference applies, and the inactive manual theme control stays hidden.

Each `<xxh3>` token is the complete 16-character lowercase XXH3 digest. Files beneath `site/assets/` retain their relative names beneath `/assets/site/`; `site/favicon.svg` remains `/favicon.svg`; and content assets remain beside their generated page or article.

## CI validation, build, and GitHub Pages

A content-only repository can validate and build with the exact same image as local work. This copy-pasteable workflow grants only read access to repository contents, runs the container without networking or capabilities, mounts the checked-out workspace, and uploads `public/` only:

```yaml
name: Site

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read

jobs:
  build:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - name: Validate
        run: |
          docker run --rm --network none --read-only --cap-drop ALL \
            --security-opt no-new-privileges --user "$(id -u):$(id -g)" \
            --mount type=bind,src="$GITHUB_WORKSPACE",dst=/workspace \
            --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
            ghcr.io/wanted80/snippet:v2.1.3 validate # x-release-please-version
      - name: Build
        run: |
          docker run --rm --network none --read-only --cap-drop ALL \
            --security-opt no-new-privileges --user "$(id -u):$(id -g)" \
            --mount type=bind,src="$GITHUB_WORKSPACE",dst=/workspace \
            --tmpfs /tmp:rw,noexec,nosuid,nodev,size=16m \
            ghcr.io/wanted80/snippet:v2.1.3 build # x-release-please-version
      - uses: actions/upload-pages-artifact@fc324d3547104276b827a68afc52ff2a11cc49c9 # v5.0.0
        if: github.ref == 'refs/heads/main'
        with:
          path: public/
```

For GitHub Pages, enable **Settings → Pages → Build and deployment → GitHub Actions**, then add a deployment job that depends on the build job. Because pull requests must not deploy, use the branch condition shown here:

```yaml
  deploy:
    if: github.ref == 'refs/heads/main'
    needs: build
    runs-on: ubuntu-24.04
    permissions:
      pages: write
      id-token: write
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - id: deployment
        uses: actions/deploy-pages@cd2ce8fcbc39b97be8ca5fce6e763baed58fa128 # v5.0.0
```

Deploy only `public/`. Keep it ignored; do not create a generated-output branch. The build includes a root-level `404.html` that GitHub Pages automatically uses for missing routes; its links and assets retain the configured deployment path for project sites. Other static hosts can use the same file through their custom-error-page configuration. GitHub Pages owns HTTPS and does not provide project-controlled response headers. On configurable hosts, add `Content-Security-Policy: frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin` alongside Snippet's meta CSP.

## Troubleshooting

### A preview port is already in use

```bash
make docker-preview PREVIEW_PORT=9443
```

For direct preview use `bin/snippet preview --port=9000`. For official-image preview, change both the published host port and `--port`, for example `--publish 127.0.0.1:9000:9000` with `--port=9000`. Run `make docker-preview-down` if an earlier contributor Docker preview still owns the configured port.

### Container preview is unreachable

Keep the host publication as `127.0.0.1:<host-port>:<container-port>`, but pass `--host=0.0.0.0` to `snippet preview` inside the container. Binding the container process to its default `127.0.0.1` isolates it inside that container, so Docker cannot forward the published port. Also include `--service-ports` when starting the Compose preview with `docker compose run`.

### The browser warns about the local certificate

Run `make docker-preview-trust`, close every browser window, and reopen it. Firefox may also require **Settings → Privacy & Security → Certificates → Allow Firefox to automatically trust third-party root certificates you install**.

### Docker output has the wrong owner

The builder examples always pass `--user "$(id -u):$(id -g)"`. For full-checkout Compose work, set `LOCAL_UID` and `LOCAL_GID` in `.env`, then rebuild with `make docker-image NO_CACHE=1`.

### Compose uses unexpected values

```bash
make docker-config
ENVIRONMENT=production make docker-config
```

Check Make command-line assignments, shell variables, and `.env` in that precedence order.

### Dependencies or images are stale

Synchronize the selected dependency volume with `make docker-install`. Refresh images or bypass the build cache with `PULL=1` and `NO_CACHE=1`. Dependency volumes are disposable, but identify their exact names with `docker volume ls` before removing one; never remove the Caddy data volume unless you intend to trust a newly generated local certificate authority.
