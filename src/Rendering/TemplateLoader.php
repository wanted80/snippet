<?php

declare(strict_types=1);

namespace Snippet\Rendering;

use NoDiscard;
use Snippet\Exception\ContentException;
use Snippet\Site\Limits;

/** Loads and validates the author-editable HTML template set once per build. */
final readonly class TemplateLoader
{
    /** @throws ContentException when a required template or placeholder contract is invalid */
    #[NoDiscard('the loaded templates should be passed to the renderer')]
    public function load(string $directory, ?Limits $limits = null): Templates
    {
        $limits ??= new Limits();
        $templates = [];
        $totalBytes = 0;
        foreach (Template::cases() as $template) {
            $path = $directory . '/' . $template->value;
            if (!is_file($path) || is_link($path)) {
                throw new ContentException("Required HTML template '{$path}' must be a regular non-symlink file.");
            }
            $size = @filesize($path);
            if (!is_int($size) || $size > $limits->templateBytes || ($totalBytes += $size) > $limits->allTemplateBytes) {
                throw new ContentException("HTML template '{$path}' exceeds the configured template size limits.");
            }
            $contents = @file_get_contents($path);
            if ($contents === false || !mb_check_encoding($contents, 'UTF-8')) {
                throw new ContentException("HTML template '{$path}' must be readable UTF-8 text.");
            }
            $contents = $this->normalizeReleasedLayout($template, $contents);
            $this->validateContexts($contents, $path);
            $this->validatePlaceholders($template, $contents, $path);
            $templates[$template->value] = $contents;
        }

        return new Templates($templates);
    }

    private function normalizeReleasedLayout(Template $template, string $contents): string
    {
        if ($template !== Template::Layout) {
            return $contents;
        }

        return str_replace(
            [
                '<script src="{{base_path}}/assets/theme.js"></script>',
                '<link rel="stylesheet" href="{{base_path}}/assets/theme.css">',
            ],
            ['{{theme_script}}', '{{theme_stylesheet}}'],
            $contents,
        );
    }

    private function validateContexts(string $contents, string $path): void
    {
        $tagPrefix = '<(?:(?:"[^"]*"|\'[^\']*\'|[^\'"<>])*)';
        $forbidden = preg_match(
            '~<(?:script|style)\b[^>]*>(?:(?!</(?:script|style)>)[\s\S])*?\{\{'
            . '|' . $tagPrefix . '\b(?:on[a-z]+|style)\s*=\s*(?:"[^"]*\{\{|\'[^\']*\{\{)'
            . '|' . $tagPrefix . '\b[a-z][a-z0-9:._-]*\s*=\s*[^"\'\s>]*\{\{'
            . '|<\s*\{\{|\{\{[^}]+\}\}\s*=~i',
            $contents,
        ) === 1;
        if ($forbidden) {
            throw new ContentException("HTML template '{$path}' contains a placeholder in an executable or ambiguous context.");
        }
    }

    /** @throws ContentException when placeholders do not match the template contract */
    private function validatePlaceholders(Template $template, string $contents, string $path): void
    {
        $result = preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $contents, $matches);
        $withoutPlaceholders = preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $contents);
        $actual = $result === false ? [] : array_values(array_unique($matches[1]));
        $expected = $template->placeholders();
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected || !is_string($withoutPlaceholders) || str_contains($withoutPlaceholders, '{{') || str_contains($withoutPlaceholders, '}}')) {
            $placeholders = implode(', ', array_map(static fn(string $name): string => '{{' . $name . '}}', $template->placeholders()));
            throw new ContentException("HTML template '{$path}' must contain exactly these placeholders: {$placeholders}.");
        }
    }
}
