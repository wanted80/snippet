<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** Shared zero-allocation delimiters in the flat inline event arena. */
enum InlineMarker: string implements Inline
{
    case EmphasisStart = 'emphasis-start';
    case EmphasisEnd = 'emphasis-end';
    case StrongStart = 'strong-start';
    case StrongEnd = 'strong-end';
    case StrikethroughStart = 'strikethrough-start';
    case StrikethroughEnd = 'strikethrough-end';
    case LinkEnd = 'link-end';
}
