<?php

declare(strict_types=1);

namespace Snippet\Tests;

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

    protected function publication(bool $minify, string $css = ''): string
    {
        $this->site(['build' => ['minify' => $minify]]);
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

    /**
     * Pest 5.0.1 does not expose media emulation. Use its existing Chromium connection
     * to send native CDP media preferences; never rewrite preference rules in CSS.
     * @param list<array{name: string, value: string}> $features
     */
    protected function media(Page $page, string $media = 'screen', array $features = []): void
    {
        $pageId = new ReflectionProperty(Page::class, 'guid')->getValue($page);
        $contextId = new ReflectionProperty(Context::class, 'guid')->getValue($page->context());
        self::assertIsString($pageId);
        self::assertIsString($contextId);
        /** @var array{result?: array{session?: array{guid: string}}} $message */
        foreach (Client::instance()->execute($contextId, 'newCDPSession', ['page' => ['guid' => $pageId]]) as $message) {
            if (isset($message['result']['session']['guid'])) {
                $session = $message['result']['session']['guid'];
                iterator_to_array(Client::instance()->execute($session, 'send', ['method' => 'Emulation.setEmulatedMedia', 'params' => ['media' => $media, 'features' => $features]]));
                // The browser context owns the session; detaching would reset emulation.
                return;
            }
        }
        throw new RuntimeException('Chromium did not create a media emulation session.');
    }
}
