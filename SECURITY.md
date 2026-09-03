# Security policy

## Supported versions

Only the latest published Snippet release receives security fixes. Downstream sites should update to that release before reporting an issue that may already be resolved.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting for this repository. Do not open a public issue, discussion, or pull request containing exploit details, private content, credentials, or an undisclosed vulnerability.

Include the affected version or commit, the environment, reproduction steps, expected impact, and any suggested mitigation. Reports will be assessed and coordinated privately; no fixed response or disclosure timeline is promised.

Snippet's security boundary covers the PHP builder, its validation and publication behavior, and the local preview. The generated site is static. Public hosting configuration, HTTPS termination, response headers, downstream content, and modifications made outside this repository remain the deployer's responsibility.

## Builder image integrity

The official builder is assembled from pinned multi-platform base-image digests, while Dependabot keeps those pins reviewable and current. Pull-request quality runs perform a report-only scan of fixed high and critical operating-system vulnerabilities using an immutable Trivy action revision in a read-only GitHub-hosted job. The scan remains informational until the project has an evidence-based exception policy; Composer's locked dependency audit remains a separate required gate.

Stable image publication runs only after a read-only job smoke-tests the released revision. A separate least-privilege job rebuilds exactly that commit, attaches maximum BuildKit provenance and an SPDX SBOM, publishes GitHub build provenance, verifies the resulting attestation against this repository's hosted release workflow, and records the immutable image reference in its job summary.

Release tags are convenient names, not immutable identities. Security-sensitive consumers should copy the published `ghcr.io/wanted80/snippet:vX.Y.Z@sha256:<release-index-digest>` reference, authenticate to GHCR, and verify it with `gh attestation verify`. Require the signer workflow `wanted80/snippet/.github/workflows/release.yml` and use `--deny-self-hosted-runners`. Pin the multi-platform index digest so Docker can select the correct amd64 or arm64 child image.
