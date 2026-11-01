# Work with a coding agent

Ask your coding agent to turn notes into an article, create a permanent page, or change the look of your website. Snippet includes an authoring skill and a CLI that lets the agent inspect the installed publishing rules before editing. You keep control of the writing, configuration, and styles in your repository.

Use your own coding agent with workspace file access and permission to run your configured Snippet command. Snippet supplies the instructions and publishing tools; the engine runs without an AI service. You can also do every authoring task with a text editor and shell.

Initialization adds `AGENTS.md` and `.agents/skills/snippet-authoring/SKILL.md` to your site repository. These author-owned instructions explain where content belongs and how to validate it. They are not published to the website.

## Give the agent a concrete task

Record your pinned Docker image and usual command in the repository instructions. Start each request by asking the agent to use the snippet-authoring skill. Give it your source notes, intended tone, and any design constraints.

**Create a page.** Use the snippet-authoring skill to create an About page from these notes, keep my writing style, add the page to navigation, then validate and build the site.

**Write an article.** Use the snippet-authoring skill to turn these notes into an article. Suggest a title, description, and tags, use the publication date I supply, add links to related pages, then validate and build.

**Customize the styles.** Use the snippet-authoring skill to give the site a warm light palette and a readable dark palette. Adjust typography, spacing, and control colors in `site/site.css`. Preserve my existing content and check both themes on narrow and wide screens.

**Change the header font.** Use the snippet-authoring skill to replace the Snippet Logo wordmark with my interface font through `--font-wordmark`. Keep a short header name and the full site title, then validate, build, and review the header.

**Improve an existing article.** Use the snippet-authoring skill to make this article easier to follow, retain its meaning and publication date, and check its internal links before building.

The agent should inspect the installed version before assuming a feature or configuration option exists. With the helper from [Start your own site](/articles/start-your-site/), it can run:

```bash
snippet --version --json
snippet inspect capabilities --json
snippet inspect theme --json
snippet inspect config --json
snippet inspect content --json
```

Inspection describes commands, the stable theme API, configuration, and content rules. It reads the installed engine independently of your workspace. JSON is useful for tooling; normal command output stays readable for people.

## Edit source, then verify

The normal sequence is inspect, initialize if needed, create, edit, validate, and build. New-page and new-article commands return paths to incomplete files. The agent must fill in the Markdown and metadata before claiming that a page is ready.

For style changes, the agent can inspect the stable CSS tokens, font settings, class hooks, and layer order. Describe the appearance you want and let it apply those documented controls. The [theme guide](/articles/customize-the-theme/) shows the same customization surface.

Ask it to keep custom CSS in `site/site.css`, preserve your existing content, and review light and dark appearances. A successful build proves source validity; it does not replace your review of the writing or design.

## Keep instructions yours

Rerunning `init` adds missing instruction files but never overwrites existing ones. If your repository already has an `AGENTS.md`, add a reference to `.agents/skills/snippet-authoring/SKILL.md` yourself. Review changes to instructions just as you review content changes.

Agents do not unlock additional content types or Markdown extensions. They work within the same small publishing model. The [agent CLI reference](https://github.com/wanted80/snippet#agent-cli) documents JSON responses, exit statuses, and upgrading existing workspaces.
