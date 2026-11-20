# Publishing an MCSM release

1. Merge the changes to the default branch (`main`).
2. Open **Actions → Create release → Run workflow**, select `main`, and enter a new stable version, for example `1.4.1` or `v1.4.1`.
3. Run the workflow. It updates the `Version` header and `MCSM_VERSION` in `mcsm.php`, adds the changelog entry, checks PHP syntax, builds and verifies `mcsm.zip`, pushes the version commit and tag together, and publishes the release with the package attached.

This is a separate, manual-only workflow. Pushes, tags, and other releases do not trigger it. Use a version higher than the current one. Published releases and existing unrelated tags are never overwritten. The release is marked **Latest** so WordPress sites can discover it.

Optionally collect human-written changelog notes under `### Unreleased` at the top of the Changelog section in `README.md` before releasing. The workflow moves those notes into the new version entry. Otherwise, it adds a link to the GitHub release notes, which GitHub generates from repository history.

If a run fails after pushing the version commit and tag, rerun it with the same version to finish attaching the package and publishing the draft, provided the default branch still carries that version. The workflow reuses the original tagged commit and preserves the tag and any published release.

The workflow uses the built-in `GITHUB_TOKEN` with **Contents: write**. Repository rules must allow this token to push the version commit to the default branch and create release tags. If branch or tag protection is added later, configure an allowed GitHub App or use a reviewed release PR process.
