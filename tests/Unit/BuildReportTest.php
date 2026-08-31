<?php

declare(strict_types=1);

use Snippet\Publishing\BuildReport;

mutates(BuildReport::class);

it('derives the total output file count without storing duplicate state', function (): void {
    $report = new BuildReport(2, 3, 4, 5, 6);

    expect($report->files)->toBe(11)
        ->and(new ReflectionProperty($report, 'files')->isVirtual())->toBeTrue();
});
