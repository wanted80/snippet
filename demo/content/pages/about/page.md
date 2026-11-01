# About Snippet

Snippet is a small personal publishing system built for learning, curiosity, experimentation, and fun. Its guiding idea is that the content is the product, the builder is an implementation detail, and the generated website is disposable.

The author created it to publish a personal website through GitHub automation and to explore building software with AI as a collaborator. The project favors readable code, deterministic builds, semantic HTML, and plain CSS. Its PHP publishing engine uses no third-party runtime packages.

## This website is the example

The articles here explain how to use Snippet. They are ordinary Markdown content, published with the same engine and theme available in the Docker image. The [welcome guide](/articles/welcome/) is a map of the documentation, and [Start your own site](/articles/start-your-site/) takes you from an empty directory to a first build.

Your own site starts with empty content collections and generic configuration. Initialization never copies these demo articles. Keep your writing in your own public or private repository, then let the versioned image build it. You do not need to maintain a fork of the engine.

## Keep the durable things simple

Markdown, metadata, configuration, and assets belong to you. The builder validates them before publishing and preserves the last valid output when a build fails. Your static host serves only the generated files.

Visit the [source repository](https://github.com/wanted80/snippet), read the [installation guide](https://github.com/wanted80/snippet/blob/main/INSTALL.md), or explore the [article index](/articles/). Snippet is released under the [MIT license](https://github.com/wanted80/snippet/blob/main/LICENSE).
