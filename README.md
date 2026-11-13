# NGG Custom Fields (Xadja)

Single-file WordPress plugin: a maintained replacement for the abandoned "NextGEN Custom Fields" plugin.
Keeps the legacy `nggcf_*` tables, so existing data, `$image->ngg_custom_fields` and the
`nggcf_get_field()` / `nggcf_get_gallery_field()` template functions keep working.

## Where it runs
- www.mobilia-gallery.com (Pressable site 1633277), installed as `wp-content/plugins/xadja-ngg-custom-fields.php`.

## Releases and updates
Every push to `main` runs the Release workflow: it tags `v<Version>` (auto-bumping the patch number if
that tag already exists), builds `xadja-ngg-custom-fields.zip` and publishes a GitHub release.

The plugin bundles plugin-update-checker, so WordPress offers the new version on the Plugins page.
The repo is private: paste a fine-grained GitHub token (this repo, Contents: read) under
NGG Custom Fields → Setup Fields → Updates once per site. "Check for updates now" forces a check.

Installed as a folder: `wp-content/plugins/xadja-ngg-custom-fields/xadja-ngg-custom-fields.php`.

## Admin
Menu **NGG Custom Fields**: Setup Fields (image / gallery field definitions and gallery links),
Image Values (per-gallery grid with thumbnail lightbox), Gallery Values.
