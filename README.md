# NGG Custom Fields (Xadja)

Single-file WordPress plugin: a maintained replacement for the abandoned "NextGEN Custom Fields" plugin.
Keeps the legacy `nggcf_*` tables, so existing data, `$image->ngg_custom_fields` and the
`nggcf_get_field()` / `nggcf_get_gallery_field()` template functions keep working.

## Where it runs
- www.mobilia-gallery.com (Pressable site 1633277), installed as `wp-content/plugins/xadja-ngg-custom-fields.php`.

## Deploy
Copy `xadja-ngg-custom-fields.php` over the file on the site (Pressable SFTP or WP-CLI). No build step.

## Admin
Menu **NGG Custom Fields**: Setup Fields (image / gallery field definitions and gallery links),
Image Values (per-gallery grid with thumbnail lightbox), Gallery Values.
