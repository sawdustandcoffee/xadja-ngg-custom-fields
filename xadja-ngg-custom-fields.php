<?php
/*
Plugin Name: NGG Custom Fields (Xadja)
Plugin URI:  https://xadja.com
Description: Self-contained, maintained replacement for the abandoned "NextGEN Custom Fields" plugin. Manages custom field definitions, edits image AND gallery field values, and exposes values on the front end as $image->ngg_custom_fields — using the same nggcf_* tables, so existing data and shortcodes keep working. Detects the legacy plugin and only takes over the front-end read path once it is deactivated, so the two can coexist while you migrate. Once verified, deactivate + delete the old "NextGEN Custom Fields" plugin.
Version:     1.1.0
Author:      Xadja.com
Author URI:  https://xadja.com
License:     GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Constants  (values match the legacy plugin's schema, do not change)
 *   field_type: 1 = Text Input, 2 = Text Area, 3 = Select, 4 = Date
 *   ngg_type:   1 = Image field, 2 = Gallery field
 * ---------------------------------------------------------------------- */
const XADJA_NGGCF_FT_INPUT    = 1;
const XADJA_NGGCF_FT_TEXTAREA = 2;
const XADJA_NGGCF_FT_SELECT   = 3;
const XADJA_NGGCF_FT_DATE     = 4;

const XADJA_NGGCF_TYPE_IMAGE   = 1;
const XADJA_NGGCF_TYPE_GALLERY = 2;

const XADJA_NGGCF_CAP = 'manage_options';

/* -------------------------------------------------------------------------
 * Table helpers
 * ---------------------------------------------------------------------- */
function xadja_nggcf_tables() {
	global $wpdb;
	return array(
		'fields' => $wpdb->prefix . 'nggcf_fields',
		'links'  => $wpdb->prefix . 'nggcf_fields_link',
		'values' => $wpdb->prefix . 'nggcf_field_values',
		'pics'   => $wpdb->prefix . 'ngg_pictures',
		'gals'   => $wpdb->prefix . 'ngg_gallery',
	);
}

/* -------------------------------------------------------------------------
 * Activation: create tables only if they don't already exist.
 * (On your install they exist, so this is a no-op and your data is left alone.
 *  Included so the plugin also works on a fresh site.)
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, 'xadja_nggcf_activate' );
function xadja_nggcf_activate() {
	global $wpdb;
	$t = xadja_nggcf_tables();

	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t['fields'] ) );
	if ( $exists ) {
		return; // use existing tables as-is
	}

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$t['fields']} (
		id BIGINT(19) NOT NULL AUTO_INCREMENT,
		field_name VARCHAR(255) NULL,
		field_type VARCHAR(255) NULL,
		ngg_type INT NOT NULL DEFAULT 1,
		drop_options TEXT NULL,
		dateadded DATETIME NULL DEFAULT NULL,
		PRIMARY KEY  (id)
	) {$collate};" );

	dbDelta( "CREATE TABLE {$t['links']} (
		id BIGINT(19) NOT NULL AUTO_INCREMENT,
		field_id BIGINT(19) NOT NULL DEFAULT 0,
		gid BIGINT(19) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id)
	) {$collate};" );

	dbDelta( "CREATE TABLE {$t['values']} (
		id BIGINT(19) NOT NULL AUTO_INCREMENT,
		pid BIGINT(19) NOT NULL DEFAULT 0,
		fid BIGINT(19) NOT NULL DEFAULT 0,
		field_value TEXT NULL,
		ngg_type INT NOT NULL DEFAULT 1,
		dateadded DATETIME NULL DEFAULT NULL,
		PRIMARY KEY  (id)
	) {$collate};" );
}

/* =========================================================================
 * DATA LAYER
 * ====================================================================== */

function xadja_nggcf_get_fields( $ngg_type ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t['fields']} WHERE ngg_type = %d ORDER BY field_name ASC",
		$ngg_type
	) );
}

function xadja_nggcf_get_field( $field_id ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['fields']} WHERE id = %d", $field_id ) );
}

function xadja_nggcf_linked_gids( $field_id ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT gid FROM {$t['links']} WHERE field_id = %d",
		$field_id
	) ) );
}

function xadja_nggcf_get_galleries() {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_results( "SELECT gid, name, title, path FROM {$t['gals']} ORDER BY name ASC" );
}

/** Image-type fields linked to a gallery. */
function xadja_nggcf_fields_for_gallery( $gid, $ngg_type ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT f.* FROM {$t['links']} l
		 JOIN {$t['fields']} f ON l.field_id = f.id
		 WHERE f.ngg_type = %d AND l.gid = %d
		 ORDER BY f.field_name ASC",
		$ngg_type,
		$gid
	) );
}

function xadja_nggcf_images_for_gallery( $gid ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT pid, filename, alttext, description, sortorder
		 FROM {$t['pics']} WHERE galleryid = %d ORDER BY sortorder ASC, pid ASC",
		$gid
	) );
}

/** Create a field and link it to the chosen galleries. Returns true|error string. */
function xadja_nggcf_create_field( $name, $field_type, $ngg_type, $drop_options, $gids ) {
	global $wpdb;
	$t = xadja_nggcf_tables();

	$name = sanitize_text_field( $name );
	if ( '' === $name ) {
		return 'Field name is required.';
	}
	$dup = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$t['fields']} WHERE field_name = %s AND ngg_type = %d",
		$name,
		$ngg_type
	) );
	if ( $dup ) {
		return 'A field with that name already exists.';
	}
	if ( XADJA_NGGCF_FT_SELECT !== (int) $field_type ) {
		$drop_options = '';
	}

	$ok = $wpdb->insert(
		$t['fields'],
		array(
			'field_name'   => $name,
			'field_type'   => (int) $field_type,
			'ngg_type'     => (int) $ngg_type,
			'drop_options' => $drop_options,
			'dateadded'    => current_time( 'mysql' ),
		),
		array( '%s', '%d', '%d', '%s', '%s' )
	);
	if ( ! $ok ) {
		return 'Failed to save field.';
	}

	$fid = (int) $wpdb->insert_id;
	foreach ( (array) $gids as $gid ) {
		$wpdb->insert( $t['links'], array( 'field_id' => $fid, 'gid' => (int) $gid ), array( '%d', '%d' ) );
	}
	return true;
}

function xadja_nggcf_rename_field( $field_id, $name ) {
	global $wpdb;
	$t    = xadja_nggcf_tables();
	$name = sanitize_text_field( $name );
	if ( '' === $name ) {
		return;
	}
	$wpdb->update( $t['fields'], array( 'field_name' => $name ), array( 'id' => (int) $field_id ), array( '%s' ), array( '%d' ) );
}

function xadja_nggcf_update_options( $field_id, $drop_options ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	$wpdb->update( $t['fields'], array( 'drop_options' => $drop_options ), array( 'id' => (int) $field_id ), array( '%s' ), array( '%d' ) );
}

/** Re-link a field to a set of galleries, deleting values that fall out of scope. */
function xadja_nggcf_set_links( $field_id, $gids ) {
	global $wpdb;
	$t       = xadja_nggcf_tables();
	$field   = xadja_nggcf_get_field( $field_id );
	if ( ! $field ) {
		return;
	}
	$gids    = array_map( 'intval', (array) $gids );
	$current = xadja_nggcf_linked_gids( $field_id );

	// Remove links that are no longer selected (and their orphaned values).
	foreach ( $current as $gid ) {
		if ( in_array( $gid, $gids, true ) ) {
			continue;
		}
		if ( (int) $field->ngg_type === XADJA_NGGCF_TYPE_GALLERY ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$t['values']} WHERE fid = %d AND pid = %d AND ngg_type = %d",
				$field_id, $gid, XADJA_NGGCF_TYPE_GALLERY
			) );
		} else {
			$pids = $wpdb->get_col( $wpdb->prepare( "SELECT pid FROM {$t['pics']} WHERE galleryid = %d", $gid ) );
			if ( $pids ) {
				$ph   = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
				$args = array_merge( array( $field_id, XADJA_NGGCF_TYPE_IMAGE ), array_map( 'intval', $pids ) );
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$t['values']} WHERE fid = %d AND ngg_type = %d AND pid IN ($ph)",
					$args
				) );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['links']} WHERE field_id = %d AND gid = %d", $field_id, $gid ) );
	}

	// Add newly selected links.
	foreach ( $gids as $gid ) {
		if ( in_array( $gid, $current, true ) ) {
			continue;
		}
		$wpdb->insert( $t['links'], array( 'field_id' => (int) $field_id, 'gid' => $gid ), array( '%d', '%d' ) );
	}
}

function xadja_nggcf_delete_field( $field_id ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['values']} WHERE fid = %d", $field_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['links']}  WHERE field_id = %d", $field_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['fields']} WHERE id = %d", $field_id ) );
}

/** Upsert one value (pid is image pid for image fields, gid for gallery fields). */
function xadja_nggcf_save_value( $pid, $fid, $value, $ngg_type ) {
	global $wpdb;
	$t     = xadja_nggcf_tables();
	$value = wp_unslash( $value ); // preserve HTML; output escaping is the template's job

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$t['values']} WHERE fid = %d AND pid = %d AND ngg_type = %d",
		$fid, $pid, $ngg_type
	) );

	if ( $existing ) {
		$wpdb->update( $t['values'], array( 'field_value' => $value ), array( 'id' => (int) $existing ), array( '%s' ), array( '%d' ) );
		return true;
	}
	if ( '' !== trim( (string) $value ) ) {
		$wpdb->insert(
			$t['values'],
			array( 'pid' => (int) $pid, 'fid' => (int) $fid, 'field_value' => $value, 'ngg_type' => (int) $ngg_type, 'dateadded' => current_time( 'mysql' ) ),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		return true;
	}
	return false;
}

/** All saved values for a gallery's images, indexed [pid][fid] => value. */
function xadja_nggcf_image_values( $gid ) {
	global $wpdb;
	$t    = xadja_nggcf_tables();
	$pids = $wpdb->get_col( $wpdb->prepare( "SELECT pid FROM {$t['pics']} WHERE galleryid = %d", $gid ) );
	if ( empty( $pids ) ) {
		return array();
	}
	$ph   = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
	$args = array_merge( array( XADJA_NGGCF_TYPE_IMAGE ), array_map( 'intval', $pids ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT pid, fid, field_value FROM {$t['values']} WHERE ngg_type = %d AND pid IN ($ph)",
		$args
	) );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->pid ][ (int) $r->fid ] = $r->field_value;
	}
	return $out;
}

/** Gallery-field values for one gallery, indexed [fid] => value. */
function xadja_nggcf_gallery_values( $gid ) {
	global $wpdb;
	$t    = xadja_nggcf_tables();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT fid, field_value FROM {$t['values']} WHERE pid = %d AND ngg_type = %d",
		$gid, XADJA_NGGCF_TYPE_GALLERY
	) );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->fid ] = $r->field_value;
	}
	return $out;
}

/**
 * Resolve an image URL through NextGEN's own storage layer when it is loaded,
 * so gallery paths stored relative to wp-content (NextGEN 3+/4, e.g. "/gallery/x/")
 * and offloaded media resolve correctly. Falls back to the legacy
 * "<home>/<gallery path>/thumbs/thumbs_<file>" layout.
 *
 * @param string $size 'thumb' or 'full'
 */
function xadja_nggcf_image_url( $pid, $gallery_path, $filename, $size = 'thumb' ) {
	static $storage = null;
	if ( null === $storage ) {
		$storage = false;
		foreach ( array( '\\Imagely\\NGG\\DataStorage\\Manager', 'C_Gallery_Storage' ) as $cls ) {
			if ( class_exists( $cls ) && method_exists( $cls, 'get_instance' ) ) {
				$storage = $cls::get_instance();
				break;
			}
		}
	}
	if ( $storage && $pid ) {
		try {
			$url = $storage->get_image_url( (int) $pid, $size );
			if ( $url ) {
				return $url;
			}
		} catch ( \Throwable $e ) {
			// fall through to the legacy path
		}
	}
	if ( empty( $gallery_path ) || empty( $filename ) ) {
		return '';
	}
	$rel = trim( $gallery_path, '/' );
	if ( 0 !== strpos( $rel, 'wp-content/' ) && ! file_exists( ABSPATH . $rel ) && file_exists( WP_CONTENT_DIR . '/' . $rel ) ) {
		$rel = 'wp-content/' . $rel; // path stored relative to wp-content
	}
	$file = ( 'thumb' === $size ) ? 'thumbs/thumbs_' . $filename : $filename;
	return home_url( '/' . $rel . '/' . $file );
}

function xadja_nggcf_thumb_url( $gallery_path, $filename, $pid = 0 ) {
	return xadja_nggcf_image_url( $pid, $gallery_path, $filename, 'thumb' );
}

function xadja_nggcf_full_url( $gallery_path, $filename, $pid = 0 ) {
	return xadja_nggcf_image_url( $pid, $gallery_path, $filename, 'full' );
}

/* =========================================================================
 * FRONT-END READ PATH
 * Only registered once the legacy plugin is gone, so the two never collide.
 * ====================================================================== */
add_action( 'plugins_loaded', 'xadja_nggcf_maybe_register_frontend', 99 );
function xadja_nggcf_maybe_register_frontend() {

	// Legacy plugin still active? Let it serve the front end; we only add admin.
	$legacy_active = function_exists( 'nggcf_image_obj' );

	if ( ! $legacy_active ) {
		add_filter( 'ngg_image_object', 'xadja_nggcf_image_obj', 10, 2 );

		if ( ! function_exists( 'nggcf_get_field' ) ) {
			function nggcf_get_field( $pid, $fname ) {
				return xadja_nggcf_get_image_value( $pid, $fname );
			}
		}
		if ( ! function_exists( 'nggcf_get_gallery_field' ) ) {
			function nggcf_get_gallery_field( $gid, $fname ) {
				return xadja_nggcf_get_gallery_value( $gid, $fname );
			}
		}
	}
}

/** Populate $image->ngg_custom_fields["Field Name"] = value (image fields). */
function xadja_nggcf_image_obj( $image, $pid ) {
	static $cache = array();
	$pid = (int) $pid;

	if ( ! isset( $cache[ $pid ] ) ) {
		global $wpdb;
		$t            = xadja_nggcf_tables();
		$cache[ $pid ] = array();
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.field_name, v.field_value FROM {$t['values']} v
			 JOIN {$t['fields']} f ON v.fid = f.id
			 WHERE v.pid = %d AND f.ngg_type = %d AND v.ngg_type = %d",
			$pid, XADJA_NGGCF_TYPE_IMAGE, XADJA_NGGCF_TYPE_IMAGE
		) );
		foreach ( (array) $rows as $r ) {
			$cache[ $pid ][ $r->field_name ] = $r->field_value;
		}
	}

	if ( ! isset( $image->ngg_custom_fields ) || ! is_array( $image->ngg_custom_fields ) ) {
		$image->ngg_custom_fields = array();
	}
	foreach ( $cache[ $pid ] as $name => $val ) {
		$image->ngg_custom_fields[ $name ] = $val;
	}
	return $image;
}

function xadja_nggcf_get_image_value( $pid, $fname ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_var( $wpdb->prepare(
		"SELECT v.field_value FROM {$t['values']} v
		 JOIN {$t['fields']} f ON v.fid = f.id
		 WHERE v.pid = %d AND f.field_name = %s AND f.ngg_type = %d AND v.ngg_type = %d LIMIT 1",
		$pid, $fname, XADJA_NGGCF_TYPE_IMAGE, XADJA_NGGCF_TYPE_IMAGE
	) );
}

function xadja_nggcf_get_gallery_value( $gid, $fname ) {
	global $wpdb;
	$t = xadja_nggcf_tables();
	return $wpdb->get_var( $wpdb->prepare(
		"SELECT v.field_value FROM {$t['values']} v
		 JOIN {$t['fields']} f ON v.fid = f.id
		 WHERE v.pid = %d AND f.field_name = %s AND f.ngg_type = %d AND v.ngg_type = %d LIMIT 1",
		$gid, $fname, XADJA_NGGCF_TYPE_GALLERY, XADJA_NGGCF_TYPE_GALLERY
	) );
}

/* =========================================================================
 * ADMIN
 * ====================================================================== */
add_action( 'admin_menu', 'xadja_nggcf_admin_menu' );
function xadja_nggcf_admin_menu() {
	add_menu_page( 'NGG Custom Fields', 'NGG Custom Fields', XADJA_NGGCF_CAP, 'xadja-nggcf-setup', 'xadja_nggcf_page_setup', 'dashicons-list-view', 81 );
	add_submenu_page( 'xadja-nggcf-setup', 'Setup Fields',   'Setup Fields',   XADJA_NGGCF_CAP, 'xadja-nggcf-setup',  'xadja_nggcf_page_setup' );
	add_submenu_page( 'xadja-nggcf-setup', 'Image Values',   'Image Values',   XADJA_NGGCF_CAP, 'xadja-nggcf-images', 'xadja_nggcf_page_images' );
	add_submenu_page( 'xadja-nggcf-setup', 'Gallery Values', 'Gallery Values', XADJA_NGGCF_CAP, 'xadja-nggcf-gallery','xadja_nggcf_page_gallery' );
}

function xadja_nggcf_field_type_label( $type ) {
	switch ( (int) $type ) {
		case XADJA_NGGCF_FT_TEXTAREA: return 'Text Area';
		case XADJA_NGGCF_FT_SELECT:   return 'Drop Down';
		case XADJA_NGGCF_FT_DATE:     return 'Date';
		default:                      return 'Text Input';
	}
}

function xadja_nggcf_gallery_checklist( $galleries, $checked_gids, $input_name ) {
	echo '<div style="max-height:160px;overflow:auto;border:1px solid #ddd;padding:6px;">';
	foreach ( $galleries as $g ) {
		$gid = (int) $g->gid;
		printf(
			'<label style="display:block;"><input type="checkbox" name="%s[]" value="%d" %s /> %s</label>',
			esc_attr( $input_name ),
			$gid,
			checked( in_array( $gid, $checked_gids, true ), true, false ),
			esc_html( ( $g->title ? $g->title : $g->name ) . ' (#' . $gid . ')' )
		);
	}
	echo '</div>';
}

/* ---- Setup Fields page ------------------------------------------------- */
function xadja_nggcf_page_setup() {
	if ( ! current_user_can( XADJA_NGGCF_CAP ) ) { wp_die( 'Permission denied.' ); }

	$tab     = ( isset( $_GET['tab'] ) && 'gallery' === $_GET['tab'] ) ? 'gallery' : 'image';
	$type    = ( 'gallery' === $tab ) ? XADJA_NGGCF_TYPE_GALLERY : XADJA_NGGCF_TYPE_IMAGE;
	$notice  = '';

	if ( isset( $_POST['xadja_action'] ) ) {
		check_admin_referer( 'xadja_nggcf_setup', 'xadja_nggcf_nonce' );
		$action = sanitize_key( $_POST['xadja_action'] );

		if ( 'create' === $action ) {
			$res = xadja_nggcf_create_field(
				isset( $_POST['field_name'] ) ? $_POST['field_name'] : '',
				isset( $_POST['field_type'] ) ? (int) $_POST['field_type'] : XADJA_NGGCF_FT_INPUT,
				$type,
				isset( $_POST['drop_options'] ) ? wp_unslash( $_POST['drop_options'] ) : '',
				isset( $_POST['gids'] ) ? (array) $_POST['gids'] : array()
			);
			$notice = ( true === $res ) ? 'Field created.' : $res;
		} elseif ( 'rename' === $action ) {
			xadja_nggcf_rename_field( (int) $_POST['field_id'], isset( $_POST['field_name'] ) ? $_POST['field_name'] : '' );
			$notice = 'Field renamed.';
		} elseif ( 'options' === $action ) {
			xadja_nggcf_update_options( (int) $_POST['field_id'], isset( $_POST['drop_options'] ) ? wp_unslash( $_POST['drop_options'] ) : '' );
			$notice = 'Options updated.';
		} elseif ( 'links' === $action ) {
			xadja_nggcf_set_links( (int) $_POST['field_id'], isset( $_POST['gids'] ) ? (array) $_POST['gids'] : array() );
			$notice = 'Linked galleries updated.';
		} elseif ( 'delete' === $action ) {
			xadja_nggcf_delete_field( (int) $_POST['field_id'] );
			$notice = 'Field deleted.';
		}
	}

	$galleries = xadja_nggcf_get_galleries();
	$fields    = xadja_nggcf_get_fields( $type );
	$base      = admin_url( 'admin.php?page=xadja-nggcf-setup&tab=' . $tab );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'NGG Custom Fields — Setup', 'xadja-nggcf' ); ?></h1>

		<h2 class="nav-tab-wrapper">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=xadja-nggcf-setup&tab=image' ) ); ?>" class="nav-tab <?php echo 'image' === $tab ? 'nav-tab-active' : ''; ?>">Image Fields</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=xadja-nggcf-setup&tab=gallery' ) ); ?>" class="nav-tab <?php echo 'gallery' === $tab ? 'nav-tab-active' : ''; ?>">Gallery Fields</a>
		</h2>

		<?php if ( $notice ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( $fields ) : ?>
			<table class="widefat striped" style="margin-top:1em;">
				<thead><tr>
					<th>Field Name</th><th style="width:120px;">Type</th><th>Actions</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $fields as $f ) :
					$fid     = (int) $f->id;
					$linked  = xadja_nggcf_linked_gids( $fid ); ?>
					<tr>
						<td><strong><?php echo esc_html( $f->field_name ); ?></strong></td>
						<td><?php echo esc_html( xadja_nggcf_field_type_label( $f->field_type ) ); ?></td>
						<td>
							<details><summary style="cursor:pointer;">Rename</summary>
								<form method="post" action="<?php echo esc_url( $base ); ?>" style="margin:.5em 0;">
									<?php wp_nonce_field( 'xadja_nggcf_setup', 'xadja_nggcf_nonce' ); ?>
									<input type="hidden" name="xadja_action" value="rename" />
									<input type="hidden" name="field_id" value="<?php echo $fid; ?>" />
									<input type="text" name="field_name" value="<?php echo esc_attr( $f->field_name ); ?>" />
									<button class="button">Save</button>
								</form>
							</details>

							<details><summary style="cursor:pointer;">Linked galleries</summary>
								<form method="post" action="<?php echo esc_url( $base ); ?>" style="margin:.5em 0;">
									<?php wp_nonce_field( 'xadja_nggcf_setup', 'xadja_nggcf_nonce' ); ?>
									<input type="hidden" name="xadja_action" value="links" />
									<input type="hidden" name="field_id" value="<?php echo $fid; ?>" />
									<?php xadja_nggcf_gallery_checklist( $galleries, $linked, 'gids' ); ?>
									<p class="description">Unticking a gallery deletes this field's saved values for that gallery.</p>
									<button class="button">Save Links</button>
								</form>
							</details>

							<?php if ( XADJA_NGGCF_FT_SELECT === (int) $f->field_type ) : ?>
								<details><summary style="cursor:pointer;">Drop-down options</summary>
									<form method="post" action="<?php echo esc_url( $base ); ?>" style="margin:.5em 0;">
										<?php wp_nonce_field( 'xadja_nggcf_setup', 'xadja_nggcf_nonce' ); ?>
										<input type="hidden" name="xadja_action" value="options" />
										<input type="hidden" name="field_id" value="<?php echo $fid; ?>" />
										<textarea name="drop_options" cols="40" rows="2"><?php echo esc_textarea( $f->drop_options ); ?></textarea>
										<br /><i>Comma separated: Option A, Option B, Option C</i><br />
										<button class="button">Save Options</button>
									</form>
								</details>
							<?php endif; ?>

							<form method="post" action="<?php echo esc_url( $base ); ?>" style="display:inline;" onsubmit="return confirm('Permanently delete this field and all its saved values?');">
								<?php wp_nonce_field( 'xadja_nggcf_setup', 'xadja_nggcf_nonce' ); ?>
								<input type="hidden" name="xadja_action" value="delete" />
								<input type="hidden" name="field_id" value="<?php echo $fid; ?>" />
								<button class="button button-link-delete" style="color:#b32d2e;">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="margin-top:1em;">No <?php echo esc_html( $tab ); ?> fields yet.</p>
		<?php endif; ?>

		<h2 style="margin-top:2em;">Add New <?php echo 'gallery' === $tab ? 'Gallery' : 'Image'; ?> Field</h2>
		<form method="post" action="<?php echo esc_url( $base ); ?>" class="postbox" style="padding:1em;max-width:640px;">
			<?php wp_nonce_field( 'xadja_nggcf_setup', 'xadja_nggcf_nonce' ); ?>
			<input type="hidden" name="xadja_action" value="create" />
			<table class="form-table">
				<tr><th>Display Name</th><td><input type="text" name="field_name" size="35" required /></td></tr>
				<tr><th>Field Type</th><td>
					<select name="field_type">
						<option value="<?php echo XADJA_NGGCF_FT_INPUT; ?>">Text Input</option>
						<option value="<?php echo XADJA_NGGCF_FT_TEXTAREA; ?>">Text Area</option>
						<option value="<?php echo XADJA_NGGCF_FT_SELECT; ?>">Drop Down</option>
						<option value="<?php echo XADJA_NGGCF_FT_DATE; ?>">Date</option>
					</select>
				</td></tr>
				<tr><th>Drop-down Options</th><td><textarea name="drop_options" cols="40" rows="2"></textarea><br /><i>Only used for Drop Down. Comma separated.</i></td></tr>
				<tr><th>Show on Galleries</th><td>
					<?php
					if ( $galleries ) {
						$all = array_map( function ( $g ) { return (int) $g->gid; }, $galleries );
						xadja_nggcf_gallery_checklist( $galleries, $all, 'gids' );
					} else {
						echo 'No galleries found.';
					}
					?>
				</td></tr>
			</table>
			<button class="button button-primary">Create Field</button>
		</form>
	</div>
	<?php
}

/* ---- Image Values page ------------------------------------------------- */
function xadja_nggcf_page_images() {
	if ( ! current_user_can( XADJA_NGGCF_CAP ) ) { wp_die( 'Permission denied.' ); }

	$gid     = isset( $_REQUEST['gid'] ) ? (int) $_REQUEST['gid'] : 0;
	$saved_n = -1;

	if ( $gid && isset( $_POST['xadja_save_images'] ) ) {
		check_admin_referer( 'xadja_nggcf_img_' . $gid, 'xadja_nggcf_nonce' );
		$saved_n = 0;
		$posted  = isset( $_POST['nggcf_fields'] ) ? (array) $_POST['nggcf_fields'] : array();
		foreach ( $posted as $pid => $fields ) {
			foreach ( (array) $fields as $fid => $val ) {
				if ( xadja_nggcf_save_value( (int) $pid, (int) $fid, $val, XADJA_NGGCF_TYPE_IMAGE ) ) {
					$saved_n++;
				}
			}
		}
	}

	$galleries = xadja_nggcf_get_galleries();
	?>
	<div class="wrap">
		<h1>Edit Image Field Values</h1>
		<?php if ( $saved_n >= 0 ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php printf( 'Saved. %d value(s) written.', (int) $saved_n ); ?></p></div>
		<?php endif; ?>

		<form method="get">
			<input type="hidden" name="page" value="xadja-nggcf-images" />
			<strong>Gallery:</strong>
			<select name="gid">
				<option value="0">— Select —</option>
				<?php foreach ( $galleries as $g ) : ?>
					<option value="<?php echo (int) $g->gid; ?>" <?php selected( $gid, (int) $g->gid ); ?>><?php echo esc_html( ( $g->title ? $g->title : $g->name ) . ' (#' . $g->gid . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button">Load</button>
		</form>

		<?php
		if ( ! $gid ) { echo '</div>'; return; }

		$fields = xadja_nggcf_fields_for_gallery( $gid, XADJA_NGGCF_TYPE_IMAGE );
		$images = xadja_nggcf_images_for_gallery( $gid );
		$values = xadja_nggcf_image_values( $gid );
		$path   = '';
		foreach ( $galleries as $g ) { if ( (int) $g->gid === $gid ) { $path = $g->path; break; } }

		if ( ! $fields ) { echo '<div class="notice notice-warning"><p>No image fields are linked to this gallery (see Setup Fields → Image Fields).</p></div></div>'; return; }
		if ( ! $images ) { echo '<div class="notice notice-warning"><p>This gallery has no images.</p></div></div>'; return; }
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=xadja-nggcf-images&gid=' . $gid ) ); ?>">
			<?php wp_nonce_field( 'xadja_nggcf_img_' . $gid, 'xadja_nggcf_nonce' ); ?>
			<p><button name="xadja_save_images" value="1" class="button button-primary">Save All Changes</button></p>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:90px;">Image</th><th style="width:200px;">File</th>
					<?php foreach ( $fields as $f ) : ?><th><?php echo esc_html( $f->field_name ); ?></th><?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $images as $img ) :
					$pid   = (int) $img->pid;
					$thumb = xadja_nggcf_thumb_url( $path, $img->filename, $pid );
					$full  = xadja_nggcf_full_url( $path, $img->filename, $pid ); ?>
					<tr>
						<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" data-full="<?php echo esc_url( $full ); ?>" data-caption="<?php echo esc_attr( $img->filename . ( $img->alttext ? ' — ' . $img->alttext : '' ) ); ?>" alt="<?php echo esc_attr( $img->alttext ); ?>" class="xadja-nggcf-thumb" style="max-width:80px;height:auto;cursor:zoom-in;" loading="lazy" onerror="this.style.display='none';" /><?php endif; ?></td>
						<td><strong><?php echo esc_html( $img->alttext ? $img->alttext : $img->filename ); ?></strong><br /><span style="color:#666;"><?php echo esc_html( $img->filename ); ?> &middot; pid <?php echo $pid; ?></span></td>
						<?php foreach ( $fields as $f ) :
							$fid     = (int) $f->id;
							$current = isset( $values[ $pid ][ $fid ] ) ? $values[ $pid ][ $fid ] : '';
							$name    = 'nggcf_fields[' . $pid . '][' . $fid . ']';
							echo '<td>' . xadja_nggcf_value_input( $f, $name, $current ) . '</td>';
						endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button name="xadja_save_images" value="1" class="button button-primary">Save All Changes</button></p>
		</form>

		<div id="xadja-nggcf-lightbox" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:100000;background:rgba(0,0,0,.85);align-items:center;justify-content:center;flex-direction:column;cursor:zoom-out;">
			<img id="xadja-nggcf-lightbox-img" src="" alt="" style="max-width:92vw;max-height:84vh;width:auto;height:auto;box-shadow:0 0 40px rgba(0,0,0,.6);background:#fff;" />
			<div id="xadja-nggcf-lightbox-cap" style="color:#fff;margin-top:12px;font-size:14px;text-align:center;max-width:92vw;"></div>
			<div style="color:#bbb;margin-top:6px;font-size:12px;">Click anywhere or press Esc to close</div>
		</div>
		<script>
		(function () {
			var box = document.getElementById('xadja-nggcf-lightbox');
			if ( ! box || box.dataset.bound ) { return; }
			box.dataset.bound = '1';
			var img = document.getElementById('xadja-nggcf-lightbox-img');
			var cap = document.getElementById('xadja-nggcf-lightbox-cap');
			function openThumb( t ) {
				var full = t.getAttribute('data-full') || t.src;
				img.onerror = function () { img.onerror = null; img.src = t.src; }; // fall back to thumbnail
				img.src = full;
				cap.textContent = t.getAttribute('data-caption') || '';
				box.style.display = 'flex';
			}
			function closeBox() { box.style.display = 'none'; img.src = ''; }
			document.addEventListener('click', function ( e ) {
				var t = e.target;
				if ( t && t.classList && t.classList.contains('xadja-nggcf-thumb') ) { e.preventDefault(); openThumb( t ); }
			});
			box.addEventListener('click', closeBox);
			document.addEventListener('keydown', function ( e ) { if ( 'Escape' === e.key ) { closeBox(); } });
		})();
		</script>
	</div>
	<?php
}

/* ---- Gallery Values page ----------------------------------------------- */
function xadja_nggcf_page_gallery() {
	if ( ! current_user_can( XADJA_NGGCF_CAP ) ) { wp_die( 'Permission denied.' ); }

	$gid     = isset( $_REQUEST['gid'] ) ? (int) $_REQUEST['gid'] : 0;
	$saved_n = -1;

	if ( $gid && isset( $_POST['xadja_save_gallery'] ) ) {
		check_admin_referer( 'xadja_nggcf_gal_' . $gid, 'xadja_nggcf_nonce' );
		$saved_n = 0;
		$posted  = isset( $_POST['nggcf_gallery'] ) ? (array) $_POST['nggcf_gallery'] : array();
		foreach ( $posted as $fid => $val ) {
			if ( xadja_nggcf_save_value( $gid, (int) $fid, $val, XADJA_NGGCF_TYPE_GALLERY ) ) {
				$saved_n++;
			}
		}
	}

	$galleries = xadja_nggcf_get_galleries();
	?>
	<div class="wrap">
		<h1>Edit Gallery Field Values</h1>
		<?php if ( $saved_n >= 0 ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php printf( 'Saved. %d value(s) written.', (int) $saved_n ); ?></p></div>
		<?php endif; ?>

		<form method="get">
			<input type="hidden" name="page" value="xadja-nggcf-gallery" />
			<strong>Gallery:</strong>
			<select name="gid">
				<option value="0">— Select —</option>
				<?php foreach ( $galleries as $g ) : ?>
					<option value="<?php echo (int) $g->gid; ?>" <?php selected( $gid, (int) $g->gid ); ?>><?php echo esc_html( ( $g->title ? $g->title : $g->name ) . ' (#' . $g->gid . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button">Load</button>
		</form>

		<?php
		if ( ! $gid ) { echo '</div>'; return; }

		$fields = xadja_nggcf_fields_for_gallery( $gid, XADJA_NGGCF_TYPE_GALLERY );
		$values = xadja_nggcf_gallery_values( $gid );
		if ( ! $fields ) { echo '<div class="notice notice-warning"><p>No gallery fields are linked to this gallery (see Setup Fields → Gallery Fields).</p></div></div>'; return; }
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=xadja-nggcf-gallery&gid=' . $gid ) ); ?>" style="max-width:640px;">
			<?php wp_nonce_field( 'xadja_nggcf_gal_' . $gid, 'xadja_nggcf_nonce' ); ?>
			<table class="form-table">
				<?php foreach ( $fields as $f ) :
					$fid     = (int) $f->id;
					$current = isset( $values[ $fid ] ) ? $values[ $fid ] : '';
					$name    = 'nggcf_gallery[' . $fid . ']'; ?>
					<tr>
						<th><?php echo esc_html( $f->field_name ); ?></th>
						<td><?php echo xadja_nggcf_value_input( $f, $name, $current ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<button name="xadja_save_gallery" value="1" class="button button-primary">Save Changes</button>
		</form>
	</div>
	<?php
}

/** Render one value input for a field (shared by image + gallery editors). */
function xadja_nggcf_value_input( $field, $name, $current ) {
	switch ( (int) $field->field_type ) {
		case XADJA_NGGCF_FT_TEXTAREA:
			return sprintf( '<textarea name="%s" rows="4" style="width:100%%;">%s</textarea>', esc_attr( $name ), esc_textarea( $current ) );

		case XADJA_NGGCF_FT_SELECT:
			$opts = array_map( 'trim', explode( ',', (string) $field->drop_options ) );
			$html = '<select name="' . esc_attr( $name ) . '" style="width:100%;"><option value=""></option>';
			foreach ( $opts as $opt ) {
				$html .= sprintf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $opt ), selected( $opt, $current, false ) );
			}
			return $html . '</select>';

		case XADJA_NGGCF_FT_DATE:
			return sprintf( '<input type="date" name="%s" value="%s" style="width:100%%;" />', esc_attr( $name ), esc_attr( $current ) );

		default:
			return sprintf( '<input type="text" name="%s" value="%s" style="width:100%%;" />', esc_attr( $name ), esc_attr( $current ) );
	}
}
