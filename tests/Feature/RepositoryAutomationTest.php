<?php

declare(strict_types=1);

use Snippet\Support\ApplicationVersion;

it('defines stable least-privilege continuous integration and release workflows', function (): void {
    $root = dirname(__DIR__, 2);
    $quality = file_get_contents($root . '/.github/workflows/quality.yml');
    $pages = file_get_contents($root . '/.github/workflows/pages.yml');
    $release = file_get_contents($root . '/.github/workflows/release.yml');
    assert(is_string($quality));
    assert(is_string($pages));
    assert(is_string($release));

    expect($quality)->toContain(
        "name: Quality\n",
        "permissions:\n  contents: read\n",
        'uses: actions/checkout@',
        'run: make docker-check',
        'run: make docker-audit',
        'run: ENVIRONMENT=production make docker-validate',
        "    concurrency:\n      group: quality-\${{ github.workflow }}-\${{ github.ref }}\n      cancel-in-progress: true\n",
    )
        ->and($quality)->not->toContain('pull_request_target', 'permissions: write-all', 'path: .', 'gh-pages')
        ->and($quality)->toMatch('~uses: actions/checkout@[0-9a-f]{40}~')
        ->and($pages)->toContain(
            "name: Pages\n",
            "  workflow_run:\n    workflows:\n      - Quality\n    types:\n      - completed\n",
            "    if: github.event.workflow_run.conclusion == 'success' && github.event.workflow_run.head_branch == 'main'\n",
            "        with:\n          ref: \${{ github.event.workflow_run.head_sha }}\n",
            "    permissions:\n      contents: read\n",
            'run: ENVIRONMENT=production make docker-build',
            'uses: actions/upload-pages-artifact@',
            "with:\n          path: public/\n",
            "  deploy:\n    name: Deploy GitHub Pages\n",
            "    needs: pages\n",
            "    concurrency:\n      group: github-pages\n      cancel-in-progress: false\n",
            "    permissions:\n      pages: write\n      id-token: write\n",
            "    environment:\n      name: github-pages\n      url: \${{ steps.deployment.outputs.page_url }}\n",
            'uses: actions/deploy-pages@',
        )
        ->and($pages)->not->toContain('pull_request_target', 'permissions: write-all', 'path: .', 'gh-pages')
        ->and($pages)->toMatch('~uses: actions/checkout@[0-9a-f]{40}~')
        ->and($pages)->toMatch('~uses: actions/upload-pages-artifact@[0-9a-f]{40}~')
        ->and($pages)->toMatch('~uses: actions/deploy-pages@[0-9a-f]{40}~')
        ->and($release)->toContain(
            "name: Release\n",
            "permissions:\n  contents: write\n  issues: write\n  pull-requests: write\n",
            'uses: googleapis/release-please-action@',
            'token: ${{ secrets.RELEASE_PLEASE_TOKEN }}',
            'config-file: release-please-config.json',
            'manifest-file: .release-please-manifest.json',
        )
        ->and($release)->not->toContain('pull_request_target', 'permissions: write-all')
        ->and($release)->toMatch('~uses: googleapis/release-please-action@[0-9a-f]{40}~');
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
