# Configure your site

The author-owned `site/config.php` describes the publication. Like content metadata, it is a literal PHP array that Snippet reads without executing it. Keep all required fields and use only documented options.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Alex writes',
    'sitename' => 'Alex',
    'author' => 'Alex',
    'description' => 'Notes on making and learning.',
    'url' => 'https://example.com',
    'language' => 'en',
    'home' => [
        'articles' => 10,
        'tags' => 21,
    ],
];
```

## Identity and address

`title` identifies the site in document titles. `sitename` is the header wordmark; it can differ from the title. `author` supplies author metadata, and `description` summarizes the site. The language tag describes your content; interface labels and formatted dates remain English.

The starter displays `sitename` with Snippet Logo, a custom font created specifically for this project. Use a short header name and a longer `title` when needed. You can replace the header font through the [theme customization guide](/articles/customize-the-theme/).

The URL is your final public HTTPS address, with no trailing slash, query, fragment, or credentials. Include the project path when hosting below a domain, such as `https://YOUR-NAME.github.io/YOUR-REPOSITORY`. Snippet uses it for canonical addresses and the deployment path. Rebuild after changing it.

## Homepage and navigation

The homepage features the newest article in full, followed by older articles and popular tags. The two positive `home` counts limit those collections; complete indexes remain available. Articles sort by date descending and then slug. Popular tags sort by article count, then label and slug.

For a permanent page in navigation, set a positive `menu_order` in that page's `meta.php`. Each order must be unique. All pages also appear in the pages index, sorted by title and then slug.

## Footer links and assets

Add an optional ordered `profiles` list to the configuration:

```php
'profiles' => [
    ['label' => 'Code', 'url' => 'https://github.com/example', 'icon' => 'github'],
    ['label' => 'Elsewhere', 'url' => 'https://example.org'],
],
```

Every profile needs an HTTPS URL and a label or bundled icon. An icon-only link uses its URL as the accessible name. The footer also includes `llms.txt`, a metadata-only index for language-model readers.

Replace `site/favicon.svg` with your own SVG icon. Put shared fonts and other presentation assets in `site/assets/`; they publish under `/assets/site/`. Optional `site/site.js` loads with `defer` after the bundled theme script.

## Further options

The default Content Security Policy allows the site's own resources. The optional `csp` configuration can permit specific external HTTPS origins, or `false` can delegate the whole policy to your host. Use the [configuration reference](https://github.com/wanted80/snippet#site-customization) when you need these settings.

Do not edit `public/` to customize the site; the next build replaces it. Change source, then rebuild.

Next: [Make the theme yours](/articles/customize-the-theme/).
