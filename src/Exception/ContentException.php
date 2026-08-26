<?php

declare(strict_types=1);

namespace Snippet\Exception;

use RuntimeException;

/** Reports invalid or unreadable author-controlled content with actionable context. */
final class ContentException extends RuntimeException {}
