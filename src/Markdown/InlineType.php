<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** Maps each inline arena discriminator to and from its typed domain value. */
enum InlineType: string
{
    case Text = "\x00";
    case Code = "\x01";
    case Link = "\x02";
    case EmphasisStart = "\x03";
    case EmphasisEnd = "\x04";
    case StrongStart = "\x05";
    case StrongEnd = "\x06";
    case LinkEnd = "\x07";
    case StrikethroughStart = "\x08";
    case StrikethroughEnd = "\x09";

    public static function fromMarker(InlineMarker $marker): self
    {
        return match ($marker) {
            InlineMarker::EmphasisStart => self::EmphasisStart,
            InlineMarker::EmphasisEnd => self::EmphasisEnd,
            InlineMarker::StrongStart => self::StrongStart,
            InlineMarker::StrongEnd => self::StrongEnd,
            InlineMarker::LinkEnd => self::LinkEnd,
            InlineMarker::StrikethroughStart => self::StrikethroughStart,
            InlineMarker::StrikethroughEnd => self::StrikethroughEnd,
        };
    }

    public function decode(int $sourceOffset, int $length): Inline
    {
        return match ($this) {
            self::Text => new Text($sourceOffset, $length),
            self::Code => new InlineCode($sourceOffset, $length),
            self::Link => new Link($sourceOffset, $length),
            self::EmphasisStart => InlineMarker::EmphasisStart,
            self::EmphasisEnd => InlineMarker::EmphasisEnd,
            self::StrongStart => InlineMarker::StrongStart,
            self::StrongEnd => InlineMarker::StrongEnd,
            self::LinkEnd => InlineMarker::LinkEnd,
            self::StrikethroughStart => InlineMarker::StrikethroughStart,
            self::StrikethroughEnd => InlineMarker::StrikethroughEnd,
        };
    }
}
