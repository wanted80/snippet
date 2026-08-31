# Welcome to Snippet

Snippet is a small publishing system for one author. You write Markdown and metadata in a Git repository; Snippet validates that source and turns it into a complete static website. The result is a good fit for GitHub Pages: the deployed site contains HTML, CSS, fonts, JavaScript, and other assets, but it does not need a database, an administration panel, or PHP running in production.

The important idea is simple: the content is the product, and the builder is an implementation detail. Your source remains readable and reviewable in Git. The generated `public/` directory can be rebuilt whenever necessary, so changing the presentation does not put the original writing at risk.

## What Snippet publishes

Snippet has two content types:

- pages for permanent destinations such as About or Projects; and
- articles for dated writing such as notes, essays, and project updates.

Every item is self-contained. Its Markdown, metadata, and optional assets live together, which makes ownership easy to see and a content unit easy to move or review. The builder generates the homepage, article and page directories, tag archives, and the final asset paths from that source.

An article such as this one is organized like this:

```text
content/articles/2026/08/26/welcome/
├── article.md
├── meta.php
└── optional assets
```

The dated directories organize the source archive. The leaf directory, `welcome`, becomes the article slug and public route: `/articles/welcome/`. The date in the directory must match the date in `meta.php`; this keeps the source and the published archive in agreement.

## Writing an article

The Markdown vocabulary is deliberately small. Use paragraphs, level-one through level-three headings, lists, fenced code blocks, inline code, emphasis, links, and thematic breaks. That is enough for thoughtful writing without making every browser-rendering edge case part of the publishing system.

Create a new article with:

```bash
bin/snippet new article my-first-note
```

Then fill in `article.md` and `meta.php`. Metadata is configuration, not a program: it is a literal PHP array containing a title, description, date, and ordered tags.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'My first note',
    'description' => 'A short introduction to the subject.',
    'date' => '2026-08-26',
    'tags' => [
        'Notes',
    ],
];
```

Use local links when a reader should be able to continue through the site. Assets beside an article are copied beside its generated page, preserving their relative paths. This article includes a [small illustration](illustration.svg) and a [plain-text example](notes/example.txt) to show that relationship.

## From a local draft to a GitHub Pages site

The normal publishing loop is short:

1. Write or revise the content in Git.
2. Run `bin/snippet validate` to check the whole publication without changing `public/`.
3. Run `bin/snippet build` to create the static site locally.
4. Inspect the result with the local preview.
5. Commit the source and push it to GitHub.

Snippet’s repository includes a GitHub Actions workflow for Pages. A successful quality workflow validates the revision first. The Pages workflow then builds the tested revision in the production image, uploads `public/` as the Pages artifact, and deploys that artifact through GitHub Pages. GitHub serves the finished files; it never needs to understand the PHP builder or execute the Markdown source.

For a downstream repository, enable GitHub Pages in the repository settings and choose GitHub Actions as the deployment source. After that, pushing a validated change to `main` lets the included workflows build and publish the site.

That separation is useful when a site is hosted as a project page, for example at `https://your-name.github.io/your-site/`. Snippet keeps the configured deployment path in generated links, so the same source can produce a root site or a project site. Set the final HTTPS address in `site/config.php` before publishing.

## Validation is part of publishing

Validation checks more than whether a Markdown file can be opened. It verifies configuration, metadata, dates, slugs, tags, templates, assets, and internal links. It also rejects unsupported Markdown and unsafe or ambiguous input before rendering begins.

The build is transactional. Snippet assembles the new site in a temporary sibling directory and replaces `public/` only after every page and asset succeeds. If an edit is invalid, the last valid publication remains available. This is especially helpful for previews: an unfinished draft should not turn a working local site into a blank one.

The same boundary makes builds deterministic. Given the same source, configuration, templates, and assets, repeated builds produce the same meaningful output. There is no runtime content lookup and no hidden database state to synchronize.

## Keep the presentation close to the content

The generated site uses semantic HTML, plain CSS, and a light/dark theme. Templates in `resources/templates/` define the structure; the default stylesheet and optional files in `site/` define the presentation. You can adjust typography, spacing, colors, and layout without changing the content model or the public URLs.

The generated website is disposable by design. If a layout experiment is unsuccessful, restore the source change, rebuild, and continue. The durable things are the writing, the history in Git, and the small set of rules that turn one into the other.

## Start with one useful page

Snippet is intentionally modest. It does not include feeds, search, pagination, drafts, or a database. Those omissions keep the system understandable and keep GitHub Pages responsible only for serving static files.

Replace this introduction with something you care about, add an About page, and publish a first note. Once the workflow feels natural, change the templates or theme only when the content gives you a concrete reason.
