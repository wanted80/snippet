<?php

declare(strict_types=1);

use Pest\Browser\Playwright\Servers\PlaywrightNpmServer;
use Pest\Browser\ServerManager;
use Snippet\Tests\BrowserTestCase;
use Snippet\Tests\TestCase;

require_once __DIR__ . '/PublisherFunctions.php';
require_once __DIR__ . '/ApplicationClock.php';
require_once __DIR__ . '/AgentCliFunctions.php';
require_once __DIR__ . '/DraftFunctions.php';
require_once __DIR__ . '/PreviewFunctions.php';
require_once __DIR__ . '/ScaffoldingFunctions.php';
require_once __DIR__ . '/PublicationFunctions.php';
require_once __DIR__ . '/MarkdownFunctions.php';

pest()
    ->extend(TestCase::class)
    ->in('Feature', 'Unit');

pest()->extend(BrowserTestCase::class)->in('Browser');

pest()->browser()->inChrome();

(static function (): void {
    // Pest Browser 5.0.1 launches through a shell. Replacing that shell with Node
    // lets its normal shutdown reap the server instead of orphaning it.
    $manager = ServerManager::instance();
    $server = $manager->playwright();
    if (!$server instanceof PlaywrightNpmServer) {
        return;
    }
    new ReflectionProperty(ServerManager::class, 'playwright')->setValue($manager, PlaywrightNpmServer::create(
        $server->baseDirectory,
        'exec ' . $server->command,
        $server->host,
        $server->port,
        $server->until,
    ));
})();

pest()->tia()
    ->locally();

expect()->extend('toBeStrictlyEqualTo', fn(mixed $expected): mixed => $this->toBe($expected));
