<?php

declare(strict_types=1);

namespace Snippet\Inspection;

use InvalidArgumentException;
use Snippet\Cli\Command;
use Snippet\Content\ContentType;
use Snippet\Exception\ContentException;
use Snippet\Site\ConfigLoader;
use Snippet\Site\Limits;
use Snippet\Support\Slug;
use Snippet\Support\TrustedPhpLoader;

/** Describes the installed engine without loading or executing workspace inputs. */
final readonly class Inspector
{
    public const array SUBJECTS = ['capabilities', 'theme', 'config', 'content'];

    private const string PHP_SYNTAX = 'Start with <?php and declare(strict_types=1); then return one literal associative array. No calls, expressions, variables, interpolation, includes, duplicate keys, side effects, or output. Unknown and missing fields are rejected.';

    public function __construct(private string $engineRoot) {}

    /**
     * @return array<string, mixed>
     * @throws ContentException when installed resources cannot be read safely or violate their contract
     * @throws InvalidArgumentException when the subject is unsupported
     */
    public function inspect(string $subject, bool $initialization = false, bool $preview = true): array
    {
        return match ($subject) {
            'capabilities' => $this->capabilities($initialization, $preview),
            'theme' => $this->theme(),
            'config' => $this->config(),
            'content' => $this->content(),
            default => throw new InvalidArgumentException("Unknown inspection subject '{$subject}'."),
        };
    }

    /** @return array<string, mixed> */
    private function capabilities(bool $initialization, bool $preview): array
    {
        $commands = [];
        foreach (Command::cases() as $command) {
            if (($command === Command::Init && !$initialization) || ($command === Command::Preview && !$preview)) {
                continue;
            }
            $commands[$command->value] = ['syntax' => $command->syntax(), 'json' => $command !== Command::Preview];
        }
        return [
            'commands' => $commands,
            'inspection_subjects' => self::SUBJECTS,
            'json' => ['option' => '--json', 'placement' => 'Once, after required arguments; may precede or follow other tail options.', 'exit_statuses' => ['success' => 0, 'failure' => 1, 'invalid_usage' => 2]],
            'customization' => ['configuration' => 'site/config.php', 'stylesheet' => 'site/site.css', 'javascript' => 'site/site.js (optional; deferred after the bundled theme script)', 'favicon' => 'site/favicon.svg', 'assets' => 'site/assets/', 'templates' => 'Installed engine templates are immutable.'],
            'workflow' => 'Inspect contracts, initialize through Docker, create content, complete source files and edit site files, validate, then build. Deploy public/ as static files.',
        ];
    }

    /** @return array<string, mixed> */
    private function theme(): array
    {
        $path = $this->resource('resources', 'theme.css');
        $maximumBytes = 131_072;
        $css = @file_get_contents($path, length: $maximumBytes + 1);
        if ($css === false || mb_strlen($css, '8bit') > $maximumBytes || !mb_check_encoding($css, 'UTF-8')) {
            throw new ContentException("Installed theme '{$path}' must be readable UTF-8 within the {$maximumBytes}-byte limit.");
        }
        return [
            'engine_defaults' => new ThemeContract()->defaults($css),
            'class_hooks' => ThemeContract::CLASS_HOOKS,
            'layers' => ThemeContract::LAYERS,
            'stylesheet' => 'site/site.css',
            'example' => '@layer overrides { :root { --color-accent: light-dark(#763524, #b9d5ff); --measure-prose: 42rem; } }',
            'guidance' => 'Read and edit author CSS directly. Defaults exclude author styles and print overrides. Tokens, hooks, and layer meanings remain stable within a major release.',
        ];
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $path = $this->resource('site', 'config.php');
        return [
            'file' => 'site/config.php',
            'syntax' => self::PHP_SYNTAX,
            'required' => ConfigLoader::FIELDS,
            'fields' => [
                'title' => ['type' => 'string', 'constraint' => 'Trimmed non-empty UTF-8; homepage title.'],
                'sitename' => ['type' => 'string', 'constraint' => 'Trimmed non-empty UTF-8; shared site identity.'],
                'author' => ['type' => 'string', 'constraint' => 'Trimmed non-empty UTF-8.'],
                'description' => ['type' => 'string', 'constraint' => 'Trimmed non-empty UTF-8.'],
                'url' => ['type' => 'string', 'constraint' => 'HTTPS URL without trailing slash, credentials, query, or fragment. Optional absolute deployment path: no empty segments, traversal, backslashes, encoded separators, or malformed percent escapes; decoded segments must be UTF-8. Encode path whitespace, controls, quotes, < and >.'],
                'language' => ['type' => 'string', 'pattern' => ConfigLoader::LANGUAGE_PATTERN],
                'home' => ['type' => 'array', 'required' => ConfigLoader::HOME_FIELDS],
                'home.articles' => ['type' => 'integer', 'minimum' => 1],
                'home.tags' => ['type' => 'integer', 'minimum' => 1],
                'build' => ['type' => 'array', 'required' => ConfigLoader::BUILD_FIELDS],
                'build.minify' => ['type' => 'boolean'],
            ],
            'starter_values' => new TrustedPhpLoader()->load($path, 'installed starter configuration', new Limits()->metadataBytes),
            'guidance' => 'All fields are required; starter values are not omission defaults. Internal resource limits are not author configuration.',
        ];
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        $limits = new Limits();
        $types = [];
        foreach (ContentType::cases() as $type) {
            $types[$type->value] = [
                'directory' => 'content/' . $type->collection() . ($type === ContentType::Article ? '/YYYY/MM/DD/<slug>/' : '/<slug>/'),
                'source' => $type->sourceFilename(),
                'metadata' => 'meta.php',
                'route' => $type === ContentType::Article ? '/articles/<slug>/' : '/<slug>/',
                'required' => $type->metadataFields(),
                'optional' => $type->optionalMetadataFields(),
                'create' => $type === ContentType::Article ? 'new article <slug> [--date=YYYY-MM-DD] [--json]' : 'new page <slug> [--json]',
            ];
        }
        return [
            'types' => $types,
            'syntax' => self::PHP_SYNTAX,
            'metadata' => [
                'title' => ['type' => 'string', 'constraint' => 'Trimmed non-empty UTF-8.', 'max_characters' => $limits->titleCharacters],
                'description' => ['type' => 'string', 'constraint' => 'Trimmed non-empty UTF-8.', 'max_characters' => $limits->descriptionCharacters],
                'date' => ['type' => 'string', 'constraint' => 'Real YYYY-MM-DD date matching the zero-padded YYYY/MM/DD directories. Omitted CLI date uses the current UTC date; metadata date is required.'],
                'tags' => ['type' => 'list<string>', 'max_items' => $limits->tagsPerArticle, 'max_label_characters' => $limits->tagCharacters, 'constraint' => 'Trimmed non-empty UTF-8 labels; empty list allowed; preserve source order. Lowercase Unicode, replace runs outside letters, combining marks, and numbers with a hyphen, trim edge hyphens. Slugs must be non-empty and unique per article. Percent-encode each entire slug in URLs.'],
                'cover' => ['type' => 'boolean', 'default' => false, 'constraint' => 'When true, exactly one root cover.jpg, cover.png, or cover.webp with matching detected format is required. Render only on the canonical article and when featured in full on the homepage; preserve original bytes.', 'max_dimension' => $limits->imageDimension],
                'alt' => ['type' => 'string', 'constraint' => 'Allowed only with cover=true; trimmed non-empty UTF-8. Omission renders alt="".', 'max_characters' => $limits->descriptionCharacters],
                'menu_order' => ['type' => 'integer', 'minimum' => 1, 'constraint' => 'Pages only; site-unique order. Pages without it are absent from navigation.', 'max_menu_pages' => $limits->menuPages],
            ],
            'slug' => ['pattern' => Slug::CONTENT_PATTERN, 'reserved' => Slug::RESERVED_CONTENT, 'guidance' => 'The leaf directory name is the author-chosen slug. Titles are independent; never transliterate automatically. Article dates do not change public URLs.'],
            'markdown' => [
                'supported' => ['Blank-line-separated paragraphs', 'ATX headings # through ###', 'Flat unordered lists using - or *', 'Flat ordered lists using 1. markers', 'Triple-backtick fenced code with optional language', 'Backtick inline code', '*emphasis*', '**strong**', '~~strikethrough~~', '[label](https://example.com) links', 'Thematic breaks'],
                'unsupported' => ['Raw HTML', 'Images', 'Tables', 'Nested lists', 'HTML-style attributes', 'Arbitrary extensions'],
                'headings' => 'First authored heading, if present, must be level 1; later headings cannot skip levels.',
                'links' => 'Labels must not be blank. Validate root-relative, item-relative, and same-origin absolute paths against generated routes and copied assets. Ignore query and fragment; decode segments, normalize dot segments, reject traversal above root, then encode segments. External HTTP(S) targets and fragment identifiers are unchecked.',
            ],
            'assets' => 'Optional regular non-symlink files inside the content unit are copied beside its generated page; reference them using links.',
            'creation' => 'New commands create empty Markdown and incomplete metadata exclusively. Complete both files before validation or publication; success does not imply valid content.',
        ];
    }

    /** @throws ContentException when an installed input is missing or uses unsafe symlinks */
    private function resource(string $directory, string $name): string
    {
        $parent = $this->engineRoot . '/' . $directory;
        $path = $parent . '/' . $name;
        if (is_link($this->engineRoot) || is_link($parent) || !is_dir($parent) || is_link($path) || !is_file($path)) {
            throw new ContentException("Installed resource '{$path}' must be a regular file beneath non-symlink engine directories.");
        }
        return $path;
    }
}
