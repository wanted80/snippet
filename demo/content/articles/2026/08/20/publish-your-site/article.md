# Publish your site from your own repository

The publishing loop is simple: edit your source, push it to Git, and let CI build and upload `public/`. Your repository can be public or private. Snippet's public Docker image supplies the engine, so your site does not need to carry a copy of the builder source.

## Source visibility and website visibility

A private repository can keep your source history and project instructions private while publishing a public website. Everything built into `public/`, including content attachments, is intended for visitors.

GitHub Pages supports public repositories on GitHub Free; Pages from a private repository requires an eligible paid plan. A private source repository does not by itself restrict access to the website. Check [GitHub's availability and visibility guidance](https://docs.github.com/en/pages/getting-started-with-github-pages/creating-a-github-pages-site) for your account. You can also upload a build from a private repository to another static host.

## Copy the complete workflow

Use the [GitHub Pages workflow example](https://github.com/wanted80/snippet/blob/main/examples/github-pages.yml). Save it in your site's repository as `.github/workflows/site.yml`.

The example contains both build and deployment jobs, uses pinned actions and a pinned Snippet image, and verifies the image's provenance. It uses GitHub's built-in token; no personal access token or personal domain is embedded in the file.

1. Set the final HTTPS `url` in `site/config.php`, without a trailing slash.
2. Enable GitHub Actions as the source under Settings, Pages, Build and deployment.
3. Keep `/public/` ignored and commit your content, site files, instructions, and workflow.
4. Push to `main`, then follow the run in the Actions tab.
5. Open the published address shown in Pages settings.

For a project site, use `https://YOUR-NAME.github.io/YOUR-REPOSITORY`, substituting your account and repository. The workflow assumes the default branch is `main`; adjust its branch checks if yours differs.

Pull requests validate and build without deploying. A push to `main` builds and deploys, and a manual workflow run on `main` can republish. Only the generated artifact is uploaded. There is no generated-output branch to maintain.

## Use your own domain

For an address such as `https://example.com`, add the domain in Pages settings, configure DNS using [GitHub's domain guide](https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site/managing-a-custom-domain-for-your-github-pages-site), and set that address in `site/config.php`. Rebuild so links and canonical URLs match. Custom GitHub Actions publication does not require a `CNAME` file in the build.

Your static host owns public HTTPS. Docker's local preview and its certificates are unrelated to the production domain.

## Use a different static host

The build step stays the same: run the pinned image with your repository mounted at `/workspace` and the `build` command. Replace the Pages artifact and deployment steps with your host's upload mechanism, using `public/` as the source. Keep provider credentials in CI secrets.

The generated site needs only static hosting. Configure the host to use the generated `404.html` for missing routes if it does not do so automatically. There is no PHP process, database, or Docker service to keep running on the web server.

Keep local and CI image versions aligned. For stricter reproducibility, pin the release's multi-platform image digest, then review updates before adopting them. See the [deployment reference](https://github.com/wanted80/snippet/blob/main/INSTALL.md#ci-validation-build-and-github-pages) and [GitHub workflow documentation](https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages) for details.
