<?php

declare(strict_types=1);

namespace Snippet\Tests;

use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Context;
use Pest\Browser\Playwright\Page;
use ReflectionProperty;
use RuntimeException;

use function validatePublication;

/** Temporary real publications and local HTTP; no repository output or external resources. */
abstract class BrowserTestCase extends TestCase
{
    /** @var resource|null */
    private mixed $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    protected function publication(string $css = ''): string
    {
        $this->item('about', ['title' => 'About', 'description' => 'About this site.', 'menu_order' => 1], "# About\n\n```php\necho 'hello';\n```\n\n" . str_repeat("A paragraph.\n\n", 40));
        $this->article('post', ['title' => 'Post', 'description' => 'A post.', 'date' => '2026-01-01', 'tags' => ['Theme']], "# Heading\n\n```php\necho 'hello';\n```\n\n" . str_repeat("A paragraph with [a link](/about/).\n\n", 30));
        file_put_contents($this->directory . '/site/site.css', $css);
        [$status, , $error] = validatePublication($this->directory, 'build');
        self::assertSame(0, $status, $error);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = proc_open([PHP_BINARY, '-S', $address, '-t', $this->directory . '/public'], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/server.log', 'a'],
            2 => ['file', $this->directory . '/server.log', 'a'],
        ], $pipes);
        self::assertIsResource($server);
        $this->server = $server;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            set_error_handler(static fn(): bool => true);
            try {
                $connection = stream_socket_client('tcp://' . $address, timeout: 0.1);
            } finally {
                restore_error_handler();
            }
            if (is_resource($connection)) {
                fclose($connection);
                return 'http://' . $address;
            }
            usleep(10_000);
        }
        throw new RuntimeException('Temporary publication server did not start.');
    }

    /** Native media emulation through Playwright's shared protocol, absent from Pest's Page API. */
    protected function media(Page $page, string $media = 'screen'): void
    {
        $this->pageCommand($page, 'emulateMedia', ['media' => $media, 'reducedMotion' => 'reduce']);
    }

    /** Hold a real pointer press so :active is measured before release in every engine. */
    protected function press(Page $page, string $selector): void
    {
        $page->locator($selector)->hover();
        $this->pageCommand($page, 'mouseDown', ['button' => 'left']);
    }

    /** Release away from the control without activating its link or action. */
    protected function release(Page $page): void
    {
        $this->pageCommand($page, 'mouseMove', ['x' => 0, 'y' => 0]);
        $this->pageCommand($page, 'mouseUp', ['button' => 'left']);
    }

    /**
     * Compare numeric sRGB channels and alpha; engines serialize color-mix precision differently.
     * @param array{float|int, float|int, float|int, float|int} $expected
     */
    protected function assertSrgb(Webpage $browser, string $selector, array $expected): void
    {
        $color = $browser->script('getComputedStyle(document.querySelector(' . json_encode($selector, flags: JSON_THROW_ON_ERROR) . ')).backgroundColor');
        self::assertIsString($color);
        self::assertStringStartsWith('color(srgb ', $color);
        preg_match_all('/[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?/i', $color, $matches);
        $channels = array_map(floatval(...), $matches[0]);
        if (count($channels) === 3) {
            $channels[] = 1.0;
        }
        self::assertCount(4, $channels);
        foreach ($expected as $index => $value) {
            self::assertEqualsWithDelta($value, $channels[$index], 0.000001, $selector . ' color channel ' . $index);
        }
    }

    /**
     * Playwright 1.64 has no shared reduced-transparency option. Retain native Chromium
     * coverage through CDP; callers explicitly skip this capability on other engines.
     */
    protected function reducedTransparency(Page $page): void
    {
        $pageId = new ReflectionProperty(Page::class, 'guid')->getValue($page);
        $contextId = new ReflectionProperty(Context::class, 'guid')->getValue($page->context());
        self::assertIsString($pageId);
        self::assertIsString($contextId);
        /** @var array{result?: array{session?: array{guid: string}}} $message */
        foreach (Client::instance()->execute($contextId, 'newCDPSession', ['page' => ['guid' => $pageId]]) as $message) {
            if (isset($message['result']['session']['guid'])) {
                $session = $message['result']['session']['guid'];
                iterator_to_array(Client::instance()->execute($session, 'send', ['method' => 'Emulation.setEmulatedMedia', 'params' => ['media' => 'screen', 'features' => [
                    ['name' => 'prefers-reduced-transparency', 'value' => 'reduce'],
                    ['name' => 'prefers-reduced-motion', 'value' => 'reduce'],
                ]]]));
                // The browser context owns the session; detaching would reset emulation.
                return;
            }
        }
        throw new RuntimeException('Chromium did not create a media emulation session.');
    }

    /** @param array<string, mixed> $parameters */
    private function pageCommand(Page $page, string $method, array $parameters): void
    {
        $pageId = new ReflectionProperty(Page::class, 'guid')->getValue($page);
        self::assertIsString($pageId);
        iterator_to_array(Client::instance()->execute($pageId, $method, $parameters));
    }
}
