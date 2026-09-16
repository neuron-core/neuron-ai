# Releasing @neuron-core/streaming

The PHP framework and TypeScript library share this repository, one npm workspace lockfile, and integration tests. They have independent versions. PHP tags keep their existing format; npm releases use `frontend-v<package version>`. Composer does not recognize `frontend-v*` as package version tags, so they do not create PHP releases. No Packagist filter is needed. The TypeScript workspace is excluded from Composer source archives through `.gitattributes`.

## One-time npm setup

1. Own or obtain publish access to the npm `@neuron-core` scope.
2. If the package does not yet exist on npm, bootstrap it from a locally tested archive using an authorized npm account. Run the checks below, then `npm publish ./artifacts/streaming.tgz --access public --ignore-scripts`. This publishes 0.1.0; the first automated release must use a new version.
3. In the npm package's **Settings → Trusted publishing**, configure GitHub Actions with owner `neuron-core`, repository `neuron-ai`, and workflow filename `frontend-release.yml`. No environment is configured in this workflow.
4. Once automation works, restrict token-based publishing according to the organization's policy.

The dedicated workflow uses a GitHub-hosted runner, Node 24 (with npm >=11.5.1), `id-token: write` only in the publishing job, and npm Trusted Publishing. No `NPM_TOKEN` secret is required. The repository URL in `package.json` must remain consistent with the publishing repository for provenance. See [npm's setup guide](https://docs.npmjs.com/trusted-publishers/).

## Each release

From the repository root, update the library version and its integration dependency together:

```sh
npm version 0.X.X --workspace @neuron-core/streaming --no-git-tag-version
npm pkg set 'dependencies.@neuron-core/streaming=0.X.X' --workspace neuron-frontend-integration
npm install --package-lock-only --ignore-scripts
```

Update `packages/streaming/CHANGELOG.md`, then verify:

```sh
npm ci
npm test
npm run typecheck
npx playwright install --with-deps chromium
npm run pack:streaming
npm run test:packed
npm publish ./artifacts/streaming.tgz --dry-run --access public --ignore-scripts
```

Commit the package version, integration dependency, changelog, and root lockfile. Tag that commit and push the tag:

```sh
git tag frontend-v0.X.X
git push origin frontend-v0.X.X
```

`.github/workflows/frontend-release.yml` rejects tags that do not match the package version. It builds the library, checks declarations, creates an npm archive, installs it in an isolated directory, reruns consumer tests and the complete frontend integration suite against that installation, then transfers that exact archive to a separate publishing job. Stable versions use npm's `latest` tag; prereleases such as `0.2.0-beta.1` use `next`.

Do not move published tags or reuse published npm versions. Fix a failed release and use a new version if publication already succeeded. Changing the wire contract requires updating both PHP and TypeScript tests before release; matching version numbers are not a substitute for compatibility tests.
