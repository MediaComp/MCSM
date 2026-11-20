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

## Data

Plugin data is preserved by default. Enable **Delete data on uninstall** in Settings only when snippets, local snippet metadata, activity history, and plugin options should be removed.

## License

MCSM is proprietary software owned by Media Components LLC. Use is limited to authorized Media Components LLC personnel and approved internal business operations. See `LICENSE.md` for the full license terms.

## Changelog

### Unreleased

- Show the changes for a new version in the WordPress update details.

### 1.4.6

- See the [release notes](https://github.com/MediaComp/MCSM/releases/tag/v1.4.6) for the list of changes.

### 1.4.5

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
