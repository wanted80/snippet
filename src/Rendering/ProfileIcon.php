<?php

declare(strict_types=1);

namespace Snippet\Rendering;

/** Small bundled profile marks, decorative within links that supply their accessible name. */
final readonly class ProfileIcon
{
    public static function render(string $name): string
    {
        $path = match ($name) {
            'github' => 'M12 2a10 10 0 0 0-3.2 19.5v-2.8c-2.5.5-3-1.1-3-1.1-.4-1-1-1.3-1-1.3-.8-.6.1-.6.1-.6.9.1 1.4.9 1.4.9.8 1.4 2.1 1 2.6.8.1-.6.3-1 .6-1.2-2-.3-4.2-1-4.2-4.5 0-1 .4-1.8 1-2.5-.1-.3-.4-1.2.1-2.4 0 0 .8-.3 2.5.9a8 8 0 0 1 4.6 0c1.7-1.2 2.5-.9 2.5-.9.5 1.2.2 2.1.1 2.4.6.7 1 1.5 1 2.5 0 3.5-2.2 4.2-4.2 4.5.4.3.6.9.6 1.7v3.6A10 10 0 0 0 12 2Z',
            'mastodon' => 'M4 4c3-3 13-3 16 0 2 3 1 10-1 11-3 2-8 2-12 1 0 3 4 3 8 2v3c-6 2-11 0-12-5C2 12 2 7 4 4Zm3 3v7h2v-4c0-2 2-2 2 0v3h2v-3c0-2 2-2 2 0v4h2V7c-2-2-4-1-5 0-1-1-3-2-5 0Z',
            'bluesky' => 'M12 10C8 4 2 1 2 5c0 5 1 8 5 8-6 1-3 7 1 6 2-1 3-3 4-5 1 2 2 4 4 5 4 1 7-5 1-6 4 0 5-3 5-8 0-4-6-1-10 5Z',
            'linkedin' => 'M3 8h4v13H3Zm2-6a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm5 6h4v2c2-4 8-3 8 3v8h-4v-7c0-4-4-3-4 0v7h-4Z',
            'instagram' => 'M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3Zm5 3a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm6-4a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z',
            'youtube' => 'M4 5h16c3 0 3 14 0 14H4C1 19 1 5 4 5Zm6 3v8l7-4Z',
            'x' => 'M3 3h5l5 7 6-7h2l-7 8 8 10h-5l-6-8-7 8H2l8-9Zm3 2 12 14h2L8 5Z',
            default => 'M10 4h10v10h-2V7l-9 9-1-1 9-9h-7ZM4 8h4v2H6v10h10v-2h2v4H4Z',
        };
        return '<svg class="profile-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="' . $path . '"></path></svg>';
    }
}
