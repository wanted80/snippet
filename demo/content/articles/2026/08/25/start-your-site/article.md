# Start your own site

Your site can live in a public or private Git repository. Snippet's Docker image supplies the publishing engine; your repository holds the writing, configuration, and assets. You do not need a fork of Snippet, a local PHP installation, or a database.

Install Docker and use a Linux or macOS shell. On Windows, use WSL 2 and keep your files in its filesystem.

## Create the workspace

Start with an empty directory, or an empty clone of your own repository:

```bash
mkdir my-site
cd my-site

snippet() {
  docker run --rm --user "$(id -u):$(id -g)" \
    --volume "$PWD:/workspace" \
    ghcr.io/wanted80/snippet:v4.5.0 "$@" # x-release-please-version
}

snippet init
snippet new page about
```

The helper lasts for this shell session. Run it from your site's directory; it mounts that directory into the container and keeps generated files owned by you. Initialization creates empty content collections and copies the site defaults. It also adds instructions for coding agents. Existing files are preserved.

## Write the first page

Open `content/pages/about/page.md` in your editor and replace its empty contents:

```markdown
# Hello, I am Alex

This is my place for notes, projects, and things I am learning.
```

Complete `content/pages/about/meta.php`:

```php
<?php

declare(strict_types=1);

return [
    'title' => 'About',
    'description' => 'A little about this site and its author.',
    'menu_order' => 1,
];
```

The page will appear at `/about/`. The optional `menu_order` adds it to navigation. New content is deliberately incomplete until you write its text and metadata; validation will tell you what is missing.

## Give the site its identity

Edit the generated `site/config.php`: set your title, site name, author, description, language, and final HTTPS URL. Keep the required homepage settings. Use `https://example.com` for a root site or `https://YOUR-NAME.github.io/YOUR-REPOSITORY` for a GitHub project site, replacing the placeholders with your own address. Do not append a slash.

Run the builder:

```bash
snippet validate
snippet build
```

The complete static website is now in `public/`. Follow [Preview and validation](/articles/preview-and-validation/) to view it with live reload.

## Keep the source in Git

Add these lines to your site's `.gitignore`:

```text
/public/
/.snippet-build-*/
/.snippet-backup-*/
```

Commit `content/`, `site/`, `.gitignore`, `AGENTS.md`, and `.agents/`. If this is a new local directory, run `git init` first. Create your own public or private remote repository, connect it using your Git provider's instructions, and push the source.

Keep your builder version recorded in your project instructions and CI workflow. The output is disposable: you can always rebuild it from source with that version.

Next: [Write pages and articles](/articles/writing-content/), or [publish with GitHub Actions](/articles/publish-your-site/).
