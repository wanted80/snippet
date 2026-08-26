<?php

declare(strict_types=1);

use Snippet\Application;

arch('uses strict types throughout the source namespace')
    ->expect('Snippet')
    ->toUseStrictTypes();

arch('uses strict equality throughout the source namespace')
    ->expect('Snippet')
    ->toUseStrictEquality();

arch('keeps concrete source classes final')
    ->expect('Snippet')
    ->classes()
    ->toBeFinal();

arch('avoids prohibited debugging and termination functions')
    ->expect(['dd', 'dump', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('keeps the source framework independent')
    ->expect('Snippet')
    ->not->toUse(['Laravel', 'Symfony', 'Illuminate']);

arch('keeps core and input namespaces independent from output and orchestration')
    ->expect([
        'Snippet\\Content',
        'Snippet\\Exception',
        'Snippet\\Markdown',
        'Snippet\\Site',
        'Snippet\\Support',
    ])
    ->not->toUse([
        'Snippet\\Rendering',
        'Snippet\\Publishing',
        'Snippet\\Preview',
        'Snippet\\Authoring',
        Application::class,
    ]);

arch('keeps rendering independent from later output and orchestration layers')
    ->expect('Snippet\\Rendering')
    ->not->toUse(['Snippet\\Publishing', 'Snippet\\Preview', 'Snippet\\Authoring', Application::class]);

arch('keeps publishing independent from preview and orchestration')
    ->expect('Snippet\\Publishing')
    ->not->toUse(['Snippet\\Preview', 'Snippet\\Authoring', Application::class]);

arch('keeps preview dependent only on earlier output layers')
    ->expect('Snippet\\Preview')
    ->not->toUse(['Snippet\\Rendering', 'Snippet\\Authoring', Application::class]);

arch('keeps authoring independent from output and orchestration')
    ->expect('Snippet\\Authoring')
    ->not->toUse(['Snippet\\Rendering', 'Snippet\\Publishing', 'Snippet\\Preview', Application::class]);

it('uses strict types in non-PSR-4 PHP files', function (): void {
    $root = dirname(__DIR__, 2);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);
        if (!$file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }
        if (str_contains($file->getPathname(), '/.')) {
            continue;
        }
        if (str_starts_with($file->getPathname(), $root . '/src/')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        assert(is_string($source));
        expect($source)->not->toBeFalse()->and($source)->toContain('declare(strict_types=1);');
    }
});

it('keeps Docker development entry points and build dependencies out of production use', function (): void {
    $root = dirname(__DIR__, 2);
    $makefile = file_get_contents($root . '/Makefile');
    $dockerfile = file_get_contents($root . '/docker/Dockerfile');
    $caddyfile = file_get_contents($root . '/docker/Caddyfile');
    assert(is_string($makefile));
    assert(is_string($dockerfile));
    assert(is_string($caddyfile));

    expect($makefile)->toContain(
        "COMPOSE ?= $(shell if docker compose version >/dev/null 2>&1; then printf '%s' 'docker compose'; elif docker-compose version >/dev/null 2>&1; then printf '%s' 'docker-compose'; else printf '%s' 'docker compose'; fi)",
        "docker-shell:\n\t$(MAKE) ENVIRONMENT=development docker-install\n\tENVIRONMENT=development $(COMPOSE) run --rm --no-deps app zsh",
    )
        ->and($dockerfile)->toContain('apt-mark manual libonig5', 'apt-get purge -y --auto-remove')
        ->and($caddyfile)->toContain('Content-Security-Policy "frame-ancestors \'none\'"', 'X-Content-Type-Options "nosniff"');
});

it('isolates the devcontainer from the host Docker Compose project', function (): void {
    $root = dirname(__DIR__, 2);
    $compose = file_get_contents($root . '/compose.yaml');
    $developmentCompose = file_get_contents($root . '/compose.dev.yaml');
    $devcontainer = file_get_contents($root . '/.devcontainer/devcontainer.json');
    $dockerfile = file_get_contents($root . '/docker/Dockerfile');
    $entrypoint = file_get_contents($root . '/docker/devcontainer-entrypoint');
    $postCreate = file_get_contents($root . '/.devcontainer/post-create.sh');
    assert(is_string($compose));
    assert(is_string($developmentCompose));
    assert(is_string($devcontainer));
    assert(is_string($dockerfile));
    assert(is_string($entrypoint));
    assert(is_string($postCreate));

    expect($compose)->toStartWith("name: snippet\n")
        ->and($developmentCompose)->toStartWith("name: snippet-dev\n")
        ->and($compose)->toContain(
            "      GIT_CONFIG_COUNT: \"1\"\n      GIT_CONFIG_KEY_0: safe.directory\n      GIT_CONFIG_VALUE_0: /app\n",
        )
        ->and($developmentCompose)->toContain(
            "entrypoint: /usr/local/bin/snippet-devcontainer-entrypoint\n",
            "- SYS_ADMIN\n",
            "- /dev/fuse:/dev/fuse\n",
        )
        ->and($devcontainer)->toContain(
            'source=${localEnv:HOME}${localEnv:USERPROFILE}/.codex',
            'target=/mnt/snippet-host-codex',
            'type=bind',
        )
        ->and($dockerfile)->toContain(
            'COPY docker/devcontainer-entrypoint /usr/local/bin/snippet-devcontainer-entrypoint',
            "        bindfs \\\n",
        )
        ->and($entrypoint)->toContain(
            'sudo --non-interactive /usr/local/bin/snippet-devcontainer-entrypoint "$@"',
            '/usr/bin/bindfs',
            'codex_tmp=/tmp/snippet-codex-tmp',
            '/usr/bin/mountpoint --quiet "${codex_home}/tmp"',
            '/usr/bin/mount --bind "${codex_tmp}" "${codex_home}/tmp"',
            '--force-user=snippet',
            '--force-group=snippet',
            'exec /usr/sbin/runuser --user snippet -- "$@"',
        )
        ->and($postCreate)->toContain(
            'git config --global --add safe.directory "${workspace}"',
        );
});

it('ships its configured interface and wordmark fonts locally', function (): void {
    $root = dirname(__DIR__, 2);
    $fontDirectory = $root . '/site/assets/fonts/atkinson-hyperlegible-next';
    $theme = file_get_contents($root . '/site/theme.css');
    $upright = file_get_contents($fontDirectory . '/atkinson-hyperlegible-next-variable.woff2');
    $italic = file_get_contents($fontDirectory . '/atkinson-hyperlegible-next-italic-variable.woff2');
    $license = file_get_contents($fontDirectory . '/OFL.txt');
    $wordmark = file_get_contents($root . '/site/assets/fonts/snippet-logo/snippet-logo.woff2');
    assert(is_string($theme));
    assert(is_string($upright));
    assert(is_string($italic));
    assert(is_string($license));
    assert(is_string($wordmark));

    expect(mb_substr($upright, 0, 4, '8bit'))->toBe('wOF2')
        ->and(mb_substr($italic, 0, 4, '8bit'))->toBe('wOF2')
        ->and(mb_substr($wordmark, 0, 4, '8bit'))->toBe('wOF2')
        ->and(hash('sha256', $wordmark))->toBe('4f296e0c70fd2832741f635cf8b3499d5257377c30522ed3d86f98b985b84026')
        ->and($theme)->toContain(
            'font-family: "Atkinson Hyperlegible Next";',
            'atkinson-hyperlegible-next-variable.woff2',
            'atkinson-hyperlegible-next-italic-variable.woff2',
            'font-weight: 200 800;',
            '--font-reading: "Atkinson Hyperlegible Next"',
            '--font-interface: "Atkinson Hyperlegible Next"',
            'font-family: "Snippet Logo";',
            'fonts/snippet-logo/snippet-logo.woff2',
            '--font-wordmark: "Snippet Logo", var(--font-interface);',
        )
        ->and($license)->toContain('Copyright 2020-2024 The Atkinson Hyperlegible Next Project Authors', 'SIL OPEN FONT LICENSE Version 1.1');
});
