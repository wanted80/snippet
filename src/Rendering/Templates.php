<?php

declare(strict_types=1);

namespace Snippet\Rendering;

use NoDiscard;

/** Holds validated templates and performs allocation-light substitutions. */
final readonly class Templates
{
    /** @param array<string, string> $templates */
    public function __construct(private array $templates) {}

    /**
     * @param array<string, string> $values pre-escaped text or trusted rendered HTML
     */
    #[NoDiscard('the rendered template should be consumed')]
    public function render(Template $template, array $values): string
    {
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{{' . $name . '}}'] = $value;
        }

        return strtr($this->templates[$template->value], $replacements);
    }
}
