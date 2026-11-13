<?php
/**
 * GitHub release updater for NGG Custom Fields (Xadja).
 *
 * Uses YahnisElsts' Plugin Update Checker to offer one-click updates from the
 * GitHub releases of sawdustandcoffee/xadja-ngg-custom-fields. The repo is
 * private, so a GitHub token with read access to it must be saved under
 * NGG Custom Fields → Setup Fields → Updates (option xadja_nggcf_github_token).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once XADJA_NGGCF_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

// Other plugins may bundle a different PUC version whose autoloader does not know our vendor dir.
if ( ! class_exists( 'Parsedown', false ) ) {
	require_once XADJA_NGGCF_DIR . 'includes/lib/plugin-update-checker/vendor/Parsedown.php';
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Xadja_NGGCF_Updater {
	const REPO_URL     = 'https://github.com/sawdustandcoffee/xadja-ngg-custom-fields';
	const SLUG         = 'xadja-ngg-custom-fields';
	const TOKEN_OPTION = 'xadja_nggcf_github_token';

	/** @var self|null */
	private static $instance = null;

	/** @var \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\PluginUpdateChecker|null */
	private $checker = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( apply_filters( 'puc_is_slug_in_use-' . self::SLUG, false ) ) {
			return;
		}
		$this->checker = PucFactory::buildUpdateChecker( self::REPO_URL, XADJA_NGGCF_FILE, self::SLUG );
		$this->checker->setBranch( 'main' );
		$this->checker->getVcsApi()->enableReleaseAssets(); // download the built zip, not the source tarball
		$token = self::token();
		if ( $token ) {
			$this->checker->getVcsApi()->setAuthentication( $token );
		}
	}

	public static function token() {
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	public static function save_token( $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			delete_option( self::TOKEN_OPTION );
		} else {
			update_option( self::TOKEN_OPTION, $token, false );
		}
		delete_site_transient( 'update_plugins' );
		if ( self::$instance && self::$instance->checker ) {
			self::$instance->checker->getVcsApi()->setAuthentication( $token ?: null );
			self::$instance->checker->resetUpdateState();
		}
	}

	/** Force a check now; returns the available version or null. */
	public static function check_now() {
		if ( ! self::$instance || ! self::$instance->checker ) {
			return null;
		}
		$update = self::$instance->checker->checkForUpdates();
		return $update ? $update->version : null;
	}
}

Xadja_NGGCF_Updater::instance();
