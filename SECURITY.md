# Security policy

## Supported versions

Only the latest published Snippet release receives security fixes. Downstream sites should update to that release before reporting an issue that may already be resolved.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting for this repository. Do not open a public issue, discussion, or pull request containing exploit details, private content, credentials, or an undisclosed vulnerability.

Include the affected version or commit, the environment, reproduction steps, expected impact, and any suggested mitigation. Reports will be assessed and coordinated privately; no fixed response or disclosure timeline is promised.

Snippet's security boundary covers the PHP builder, its validation and publication behavior, and the local preview. The generated site is static. Public hosting configuration, HTTPS termination, response headers, downstream content, and modifications made outside this repository remain the deployer's responsibility.
