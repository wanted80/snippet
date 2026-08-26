<?php

declare(strict_types=1);

use Snippet\Support\ApplicationVersion;

it('defines stable least-privilege continuous integration and release workflows', function (): void {
    $root = dirname(__DIR__, 2);
    $ci = file_get_contents($root . '/.github/workflows/ci.yml');
    $release = file_get_contents($root . '/.github/workflows/release-please.yml');
    assert(is_string($ci));
    assert(is_string($release));

    expect($ci)->toContain(
        "name: CI\n",
        "permissions:\n  contents: read\n",
        'uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1',
        'run: make docker-check',
        'run: make docker-audit',
        'run: ENVIRONMENT=production make docker-validate',
        "    concurrency:\n      group: quality-\${{ github.workflow }}-\${{ github.ref }}\n      cancel-in-progress: true\n",
        "  pages:\n    name: Build GitHub Pages\n",
        "    if: github.event_name == 'workflow_dispatch' || (github.event_name == 'push' && github.ref == 'refs/heads/main')\n",
        "    needs: quality\n",
        "    permissions:\n      contents: read\n",
        'run: ENVIRONMENT=production make docker-build',
        'uses: actions/upload-pages-artifact@7b1f4a764d45c48632c6b24a0339c27f5614fb0b # v4.0.0',
        "with:\n          path: public/\n",
        "  deploy:\n    name: Deploy GitHub Pages\n",
        "    needs: pages\n",
        "    concurrency:\n      group: github-pages\n      cancel-in-progress: false\n",
        "    permissions:\n      pages: write\n      id-token: write\n",
        "    environment:\n      name: github-pages\n      url: \${{ steps.deployment.outputs.page_url }}\n",
        'uses: actions/deploy-pages@d6db90164ac5ed86f2b6aed7e0febac5b3c0c03e # v4.0.5',
    )
        ->and($ci)->not->toContain('pull_request_target', 'permissions: write-all', 'path: .', 'gh-pages')
        ->and($release)->toContain(
            "name: Release Please\n",
            "permissions:\n  contents: read\n",
            'uses: googleapis/release-please-action@45996ed1f6d02564a971a2fa1b5860e934307cf7 # v5.0.0',
            'token: ${{ secrets.RELEASE_PLEASE_TOKEN }}',
            'config-file: release-please-config.json',
            'manifest-file: .release-please-manifest.json',
        )
        ->and($release)->not->toContain('pull_request_target', 'permissions: write-all');
});
it('configures the first stable source release across bootstrap and subsequent runs', function (): void {
    $root = dirname(__DIR__, 2);
    $configuration = file_get_contents($root . '/release-please-config.json');
    $manifest = file_get_contents($root . '/.release-please-manifest.json');
    assert(is_string($configuration));
    assert(is_string($manifest));
    $manifestData = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($manifestData));

    $manifestVersion = $manifestData['.'] ?? null;
    $manifestDataIsValid = $manifestData === []
        || (
            array_keys($manifestData) === ['.']
            && is_string($manifestVersion)
            && preg_match('/^[0-9]+[.][0-9]+[.][0-9]+$/', $manifestVersion) === 1
            && version_compare($manifestVersion, '1.0.0', '>=')
        );

    expect(json_decode($configuration, true, flags: JSON_THROW_ON_ERROR))->toBe([
        '$schema' => 'https://raw.githubusercontent.com/googleapis/release-please/main/schemas/config.json',
        'bootstrap-sha' => '412bdce48d348050976621f638bcf3dfdaf71b14',
        'packages' => [
            '.' => [
                'release-type' => 'php',
                'package-name' => 'wanted80/snippet',
                'component' => 'snippet',
                'initial-version' => '1.0.0',
                'include-component-in-tag' => false,
                'include-v-in-tag' => true,
                'include-v-in-release-name' => true,
                'extra-files' => [
                    [
                        'type' => 'generic',
                        'path' => 'src/Support/ApplicationVersion.php',
                    ],
                ],
            ],
        ],
    ])->and($manifestDataIsValid)->toBeTrue()
        ->and(ApplicationVersion::CURRENT)->toBe($manifestData === [] ? '0.0.0' : $manifestVersion);
});

it('keeps dependency automation complete and release-neutral', function (): void {
    $dependabot = file_get_contents(dirname(__DIR__, 2) . '/.github/dependabot.yml');
    assert(is_string($dependabot));

    expect($dependabot)->toContain(
        'package-ecosystem: composer',
        'package-ecosystem: github-actions',
        'package-ecosystem: docker',
        "    directories:\n      - /\n      - /docker\n",
        "    commit-message:\n      prefix: build\n      include: scope\n",
    );
});

it('exposes separate deterministic and network-dependent maintenance gates', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = file_get_contents($root . '/composer.json');
    $makefile = file_get_contents($root . '/Makefile');
    assert(is_string($composer));
    assert(is_string($makefile));

    expect($composer)->toContain('"app:audit": "composer audit --locked"')
        ->and($makefile)->toContain(
            "docker-audit:\n\t$(MAKE) ENVIRONMENT=development docker-install",
            'shellcheck .devcontainer/post-create.sh docker/devcontainer-entrypoint docker/trust-caddy-ca',
            'node --check resources/theme.js',
        );
});
