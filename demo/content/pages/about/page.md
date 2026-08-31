# Why this starter exists


Snippet is a small personal publishing system built around a plain idea: the content should outlast any particular implementation. Articles and pages live in self-contained directories, their history stays in Git, and a dependency-free PHP command turns them into static files. Production needs no application server, database, account system, or background process.

This repository is both a usable starter and a learning project. Its code favors explicit types, deterministic ordering, narrow security boundaries, and ordinary standard-library tools. The generated `public/` directory is disposable; the Markdown, metadata, templates, and assets are the source worth protecting. Builds validate everything first, render into a temporary directory, and replace an existing publication only after success.

The example content is intentionally varied. Short notes test archive-card rhythm, while the welcome article provides a long reading page with headings, lists, links, code, and local assets. The [Pages directory](/pages/) remains a complete map of permanent pages even when only a few are promoted into the primary navigation.

Use this starter by making it personal. Replace these examples with writing you want to keep, change the site identity in `site/config.php`, and adjust the documented CSS tokens before reaching for new machinery. A private downstream repository is a convenient home for personal material while builder improvements continue to arrive from the public project.

Snippet is not trying to become a general-purpose content platform. It is deliberately for one author, one catalog, and a modest static website. That limitation keeps the interesting parts—language, structure, typography, and careful publishing—close enough to understand.
