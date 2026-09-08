<?php

declare(strict_types=1);

namespace Snippet\Inspection;

use Snippet\Exception\ContentException;

/** Extracts only the public defaults from the installed token block, never author CSS. */
final readonly class ThemeContract
{
    public const array TOKENS = [
        'colors' => ['--color-background', '--color-surface', '--color-interactive', '--color-text', '--color-muted', '--color-accent', '--color-border'],
        'fonts' => ['--font-reading', '--font-interface', '--font-wordmark', '--font-code'],
        'sizing' => ['--measure-prose', '--measure-shell', '--space-1', '--space-2', '--space-3', '--space-4', '--space-5', '--space-6', '--space-section'],
    ];

    public const array CLASS_HOOKS = ['.site-header', '.site-brand', '.site-wordmark', '.site-navigation', '.site-main', '.article-list', '.article-figure', '.content-header', '.prose', '.tag-list', '.site-footer'];

    public const array LAYERS = ['reset', 'tokens', 'base', 'layout', 'components', 'overrides'];

    /**
     * Parse the engine's deliberately restricted CSS token grammar.
     *
     * @return array<string, array<string, string>>
     * @throws ContentException when a token is missing or its declaration is ambiguous or unsupported
     */
    public function defaults(string $css): array
    {
        $values = [];
        foreach (explode(';', $this->tokenBlock($css)) as $declaration) {
            $declaration = mb_trim($declaration, encoding: 'UTF-8');
            if ($declaration === '') {
                continue;
            }
            if (preg_match('/^(--[a-z0-9-]+)\s*:\s*([a-zA-Z0-9#(),.%\/ "\'\s_-]+)$/D', $declaration, $matches) !== 1) {
                throw new ContentException('Installed theme contains an unsupported token declaration.');
            }
            $name = $matches[1];
            if (isset($values[$name])) {
                throw new ContentException("Installed theme contains duplicate token '{$name}'.");
            }
            $value = mb_trim($matches[2], encoding: 'UTF-8');
            $this->validateValue($value);
            $values[$name] = $value;
        }
        $groups = [];
        foreach (self::TOKENS as $group => $tokens) {
            foreach ($tokens as $token) {
                if (!isset($values[$token])) {
                    throw new ContentException("Installed theme is missing public token '{$token}'.");
                }
                $groups[$group][$token] = $values[$token];
            }
        }
        return $groups;
    }

    /** Locate the sole top-level tokens layer without interpreting comments, strings, or nested layers as defaults. */
    private function tokenBlock(string $css): string
    {
        $depth = 0;
        $statementStart = 0;
        $layerStart = null;
        $layer = null;
        $length = mb_strlen($css, '8bit');
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $css[$offset];
            if ($character === '/' && ($css[$offset + 1] ?? null) === '*') {
                $end = mb_strpos($css, '*/', $offset + 2, '8bit');
                if ($end === false) {
                    throw new ContentException('Installed theme contains an unclosed comment.');
                }
                $offset = $end + 1;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $offset = $this->quotedEnd($css, $offset);
                continue;
            }
            if ($character === '{') {
                if ($depth === 0) {
                    $header = mb_substr($css, $statementStart, $offset - $statementStart, '8bit');
                    $header = preg_replace('~/\*.*?\*/~s', ' ', $header)
                        ?? throw new ContentException('Installed theme contains an unsupported layer header.');
                    if (preg_match('/^\s*@layer\s+tokens\s*$/D', $header) === 1) {
                        if ($layer !== null) {
                            throw new ContentException('Installed theme contains duplicate top-level tokens layers.');
                        }
                        $layerStart = $offset + 1;
                    }
                }
                $depth++;
            } elseif ($character === '}') {
                if ($depth === 0) {
                    throw new ContentException('Installed theme contains unbalanced blocks.');
                }
                $depth--;
                if ($depth === 0) {
                    if ($layerStart !== null) {
                        $layer = mb_substr($css, $layerStart, $offset - $layerStart, '8bit');
                        $layerStart = null;
                    }
                    $statementStart = $offset + 1;
                }
            } elseif ($character === ';' && $depth === 0) {
                $statementStart = $offset + 1;
            }
        }
        if ($depth !== 0) {
            throw new ContentException('Installed theme contains unbalanced blocks.');
        }
        if ($layer === null || preg_match('/^\s*:root\s*\{([^{}]*)\}\s*$/D', $layer, $block) !== 1) {
            throw new ContentException('Installed theme must contain exactly one top-level tokens layer with one :root declaration block.');
        }
        return $block[1];
    }

    /** Each declaration must balance its own expression, including any quoted font names. */
    private function validateValue(string $value): void
    {
        $depth = 0;
        $length = mb_strlen($value, '8bit');
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $value[$offset];
            if ($character === '"' || $character === "'") {
                $offset = $this->quotedEnd($value, $offset);
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                if ($depth === 0) {
                    throw new ContentException('Installed theme contains an unbalanced token expression.');
                }
                $depth--;
            }
        }
        if ($depth !== 0) {
            throw new ContentException('Installed theme contains an unbalanced token expression.');
        }
    }

    /** Skip quoted text so escaped quotes and literal delimiters cannot affect the block structure. */
    private function quotedEnd(string $css, int $start): int
    {
        $length = mb_strlen($css, '8bit');
        for ($offset = $start + 1; $offset < $length; $offset++) {
            if ($css[$offset] === "\n" || $css[$offset] === "\r") {
                break;
            }
            if ($css[$offset] === $css[$start]) {
                return $offset;
            }
            if ($css[$offset] === '\\') {
                $offset++;
            }
        }
        throw new ContentException('Installed theme contains an unclosed string.');
    }
}
