# MCSM

Internal WordPress plugin for authorized Media Components LLC personnel. It manages global and content-specific HTML, JavaScript, CSS, and PHP snippets.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Administrator account with `manage_options` and `unfiltered_html`

## Usage

1. Open **MCSM** in WordPress admin.
2. Create a snippet and select its type, location, hook priority, display rule, devices, and status.
3. Use **Visibility** to show frontend output to all visitors or only to logged-in users.
4. Review executable code before activation.
5. Use **Activity Log** to inspect administrative changes.

PHP snippets run inside WordPress and are not sandboxed. A PHP snippet that throws a caught runtime error or leaves a detectable fatal-error marker is automatically changed to **Inactive**.

## Safe mode

If a PHP snippet prevents normal access, add this before WordPress is loaded in `wp-config.php`:

```php
define( 'MCSM_DISABLE_PHP_SNIPPETS', true );
```

Correct or deactivate the problem snippet, then remove the constant or set it to `false`. HTML, JavaScript, and CSS snippets remain active.

## Updates

The plugin checks GitHub Releases of `MediaComp/MCSM` and offers new versions on the standard WordPress Updates screen. Automatic updates can be enabled per site from the Plugins list.

If the repository is private, define a read-only access token before WordPress is loaded in `wp-config.php`:

```php
define( 'MCSM_GITHUB_TOKEN', 'github_pat_...' );
```

Use a fine-grained personal access token limited to this repository with **Contents: Read-only** permission. Without a token, a private repository reports no updates.

### Publishing a release

1. Merge the changes to the default branch (`main`).
2. Open **Actions → Create release → Run workflow**, select `main`, and enter a new stable version, for example `1.4.1` or `v1.4.1`.
3. Run the workflow. It updates the `Version` header and `MCSM_VERSION` in `mcsm.php`, adds the changelog entry, checks PHP syntax, builds and verifies `mcsm.zip`, pushes the version commit and tag together, and publishes the release with the package attached.

This is a separate, manual-only workflow. Pushes, tags, and other releases do not trigger it. Use a version higher than the current one; published releases and existing unrelated tags are never overwritten. The release is marked **Latest** so WordPress sites can discover it.

Optionally collect human-written changelog notes under `### Unreleased` at the top of the Changelog section before releasing. The workflow moves those notes into the new version entry. Otherwise, it adds a link to the GitHub release notes, which GitHub generates from repository history.

If a run fails after pushing the version commit and tag, rerun it with the same version to finish attaching the package and publishing the draft, provided the default branch still carries that version. It reuses the original tagged commit and never moves the tag or rewrites a published release. Once a higher version has been committed, an older unfinished release cannot be resumed and marked Latest.

The workflow uses the built-in `GITHUB_TOKEN` with **Contents: write**; no additional token is required. Repository rules must allow this token to push the version commit to the default branch and create release tags. If branch or tag protection is added later, configure an allowed GitHub App or use a reviewed release PR process rather than disabling protection.

## Data

Plugin data is preserved by default. Enable **Delete data on uninstall** in Settings only when snippets, local snippet metadata, activity history, and plugin options should be removed.

## License

MCSM is proprietary software owned by Media Components LLC. Use is limited to authorized Media Components LLC personnel and approved internal business operations. See `LICENSE.md` for the full license terms.

## Changelog

### Unreleased

- Fixed the snippet table shrinking beside the search box, kept short column values on one line, and kept bulk actions, filters, and pagination outside horizontal scrolling.

### 1.4.4

- See the [release notes](https://github.com/MediaComp/MCSM/releases/tag/v1.4.4) for the list of changes.

### 1.4.3

- See the [release notes](https://github.com/MediaComp/MCSM/releases/tag/v1.4.3) for the list of changes.

### 1.4.1

- See the [release notes](https://github.com/MediaComp/MCSM/releases/tag/v1.4.1) for the list of changes.

### 1.4.0

- Added plugin updates from GitHub Releases through the standard WordPress update screen.
- Added the `Update URI` header so WordPress.org is never consulted for this plugin.

### 1.3.0

- Added per-snippet WordPress hook priorities for automatic frontend output.
- Added a Shortcode Only location for manual placement without duplicate automatic output.

### 1.2.8

- Maintenance release with package metadata updates.

### 1.2.7

- Maintenance release with package metadata updates.

### 1.2.6

- Fixed the admin sidebar **Add New** link so it opens the snippet creation screen correctly.

### 1.2.5

- Added Visibility with All Visitors and Logged-in Only options.
- Updated restricted rendering so logged-in users can see restricted snippets, not only administrators.

### 1.2.4

- Added emergency PHP safe mode.
- Added automatic recovery and deactivation for failing global and local PHP snippets.
- Added execution locking and persistent fatal-error detection.
- Removed the divider above Activity Log action buttons.
- Added internal documentation updates.

### 1.2.3

- Maintenance release.
