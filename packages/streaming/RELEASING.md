# Releasing @neuron-core/streaming

Run from the repository root. Replace `0.X.X` with the new version.

1. Update the package version, integration dependency, and lockfile:

   ```sh
   npm version 0.X.X --workspace @neuron-core/streaming --no-git-tag-version
   npm pkg set 'dependencies.@neuron-core/streaming=0.X.X' --workspace neuron-frontend-integration
   npm install --package-lock-only --ignore-scripts
   ```

2. Update `packages/streaming/CHANGELOG.md`.

3. Install the dependencies and browser, then run the release checks:

   ```sh
   npm ci --include=dev
   npx playwright install --with-deps chromium
   npm run release:check
   ```

4. Commit and push the release changes, including the package version, integration dependency, changelog, and root lockfile. Tag that commit and push the tag:

   ```sh
   git tag frontend-v0.X.X
   git push origin frontend-v0.X.X
   ```

Check **GitHub → Actions → Release frontend package**. The workflow tests and publishes the package automatically using the configured npm Trusted Publisher. No manual `npm publish` is needed.
