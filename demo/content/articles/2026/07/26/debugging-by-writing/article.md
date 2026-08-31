# The bug becomes smaller on paper


When a bug resists every quick fix, write the system down as if you were explaining it to someone who cannot see your screen.

```text
Expected: the newest article appears first
Observed: articles change order between builds
Known: directory traversal order is not guaranteed
```

The contradiction often appears before the explanation is finished. Writing forces vague expectations to become testable statements.

For more on reproducible builds, the [reproducible builds project](https://reproducible-builds.org/) is a useful starting point.
