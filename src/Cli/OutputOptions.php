<?php

declare(strict_types=1);

namespace Snippet\Cli;

use InvalidArgumentException;

/** Recognizes JSON even in invalid input so usage failures use the requested format. */
final readonly class OutputOptions
{
    public bool $json;

    /** @param list<string> $arguments */
    public function __construct(private array $arguments)
    {
        $this->json = in_array('--json', array_slice($arguments, 1), true);
    }

    /**
     * Remove one JSON option from the command's option tail before normal parsing.
     *
     * @return list<string>
     * @throws InvalidArgumentException when JSON is duplicated, misplaced, or unsupported
     */
    public function arguments(): array
    {
        // All CLI arguments are strings; strict and loose comparison are equivalent for --json.
        $positions = array_keys(array_slice($this->arguments, 1), '--json', true); // @pest-mutate-ignore: TrueToFalse
        if (count($positions) > 1) {
            throw new InvalidArgumentException('Option --json may be provided only once.');
        }
        if (!$this->json) {
            return $this->arguments;
        }
        $command = $this->arguments[1];
        $tail = match ($command) {
            'new' => 4,
            'inspect' => 3,
            default => 2,
        };
        if ($positions[0] + 1 < $tail) {
            throw new InvalidArgumentException('Option --json must follow the command and its required arguments.');
        }
        if ($command === 'preview') {
            throw new InvalidArgumentException('Preview does not support --json; use interactive preview without it.');
        }
        $arguments = $this->arguments;
        array_splice($arguments, $positions[0] + 1, 1);
        return $arguments;
    }
}
