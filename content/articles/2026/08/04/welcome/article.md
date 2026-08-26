# A tour of this small place


Snippet begins with an ordinary directory and a modest promise: the writing should remain more important than the machinery around it. Each article keeps its Markdown, metadata, and related files together. The builder reads that source, checks every assumption it knows how to check, and produces a static site that can be copied to almost any web server. There is no database to maintain, no administration screen to secure, and no production PHP process waiting for requests.

That constraint is not austerity for its own sake. It creates a useful boundary. Writing can happen in any editor, revision history stays visible in Git, and the generated website can be discarded and rebuilt whenever necessary. If an experiment with typography goes wrong, the content remains plain, self-contained, and easy to move.

This welcome article is also a tour of the small vocabulary available to a Snippet author. It is deliberately longer than the other examples so the site has a realistic page for judging measure, rhythm, headings, code, lists, and the quiet details that only appear after several screens of reading.

## The content unit

An article is a leaf directory. Its name becomes the public slug, while its dated parent directories keep the source archive manageable. Inside are three kinds of material:

- `article.md`, containing the prose;
- `meta.php`, containing a literal configuration array; and
- optional local assets that belong to this article.

The arrangement makes ownership obvious. A diagram used by one essay lives beside that essay. A downloadable example does too. This article includes a [small deterministic illustration](illustration.svg) and a [plain-text note](notes/example.txt), both copied without depending on a network service.

Metadata is intentionally uneventful. A title and description introduce every item. Articles add a real calendar date and an ordered list of tags. Pages may opt into the primary menu with a positive `menu_order`. The builder reads these files as declarative arrays, not as executable programs, so an accidental function call or include fails validation instead of running during a build.

## A restrained Markdown vocabulary

Plain paragraphs do most of the work. A blank line begins a new thought, which is enough structure for essays, notes, reviews, and project journals. Within a paragraph, *emphasis* can alter cadence, **strong text** can establish a warning, and `inline code` can identify a command or filename without turning the page into documentation.

Links are explicit too. An author can point to [the PHP language](https://www.php.net/) or to a local file. Unsafe schemes are rejected, while raw HTML is treated as text rather than trusted markup. That rule removes a large ambiguous surface: content cannot quietly introduce a script, event handler, or unreviewed layout fragment.

Sometimes revision itself belongs in the record. Strikethrough can show that ~~the first idea was perfect~~ the first idea was useful because it led somewhere better.

---

A thematic break like the one above marks a genuine turn. It should be rarer than a heading and more meaningful than extra empty space.

### Lists as editorial tools

Unordered lists work well when sequence does not matter:

- keep the source readable;
- make invalid states explicit;
- prefer deterministic output; and
- let technology serve the content.

Ordered lists are better for a repeatable publishing routine:

1. Write or revise the content.
2. Run `bin/snippet validate`.
3. Inspect the focused test relevant to a builder change.
4. Run `bin/snippet build` when the source is valid.
5. Publish only the completed static directory.

A list should make a relationship easier to scan. If every sentence needs several indented qualifications, prose is usually kinder to the reader.

## Code without ceremony

Fenced code blocks preserve their whitespace and escape characters that HTML would otherwise interpret. An optional language label becomes a semantic class, ready for a future stylesheet but not coupled to a client-side highlighter.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'A clear example',
    'description' => 'Configuration remains data.',
    'date' => '2026-08-04',
    'tags' => [
        'Snippet',
        'PHP 8.5',
    ],
];
```

The browser receives escaped source inside `pre` and `code` elements. The server does not need to execute it, and the reader does not need JavaScript to see it. This is a recurring theme in Snippet: when a static representation completely solves the problem, the static representation wins.

## Validation before publication

A build starts by examining configuration and content. Slugs must be canonical. Article directories must contain a real zero-padded date, and that date must agree with metadata. Tags are normalized predictably and cannot collapse to duplicate routes. Assets must be regular files beneath their content unit; symlinks, traversal, and reserved output paths fail early.

Resource ceilings make those rules practical under imperfect input. The default site is intentionally small, but it still has explicit bounds for article and page counts, text lengths, Markdown bytes, metadata bytes, assets, templates, rendered pages, total output, memory, and execution time. Limits are checked as close as possible to discovery, before a large file is read or copied. A one-author project does not need internet-scale quotas, yet it benefits from knowing what “reasonable” means.

Only after validation does rendering begin. Output is assembled in a temporary sibling directory. If a template is missing, an asset disappears, or a write fails, the existing publication stays in place. The temporary tree replaces `public/` only after all pages and assets succeed. Repeating the same build from the same source produces the same meaningful bytes.

## Navigation with an escape hatch

Permanent pages and chronological articles have different jobs. A few pages—perhaps About, Projects, or Contact—can be selected for the primary navigation. Every page, selected or not, also appears in the complete [Pages directory](/pages/). The direct links keep their visible labels and wrap when space is limited, while the directory remains a complete fallback. The result stays understandable without a hamburger button, hidden state, or menu script.

This pattern respects the one-author scale. Four direct destinations are enough to express emphasis; the directory preserves discovery as the collection grows. Titles remain the menu labels, so navigation cannot drift into a second, competing naming system.

## The useful kind of small

Small software is not automatically simple. A short program can still mishandle paths, execute surprising configuration, emit unsafe HTML, or destroy good output after a partial failure. The aim here is a system whose boundaries can be understood: source enters through a narrow model, validation creates typed values, rendering consumes those values, and publication is transactional.

That leaves room for curiosity. You can alter the templates, tune the CSS tokens, add a page, or study the parser without first learning a framework. The code is meant to be read as much as run. Features earn their place by helping the content, and absence is a feature when it keeps the system legible.

The best next step is not to configure everything. Replace this tour with one piece you care about, keep the metadata honest, and publish it. Then change the system only when the writing gives you a concrete reason.
