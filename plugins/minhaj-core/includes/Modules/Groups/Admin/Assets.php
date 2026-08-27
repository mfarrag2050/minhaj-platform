<?php
/**
 * Enqueues jQuery UI Autocomplete + the module's admin JS on the Groups
 * pages only, and localizes the AJAX URL + search nonce so the script
 * can talk to AjaxSearchController. jQuery UI ships with core WordPress.
 *
 * @package Minhaj\Modules\Groups\Admin
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private const HANDLE = 'minhaj-groups-admin';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_groups_page( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/groups-admin.js', MINHAJ_CORE_FILE ),
			array( 'jquery', 'jquery-ui-autocomplete' ),
			MINHAJ_CORE_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'MinhajGroupsAdmin',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'searchNonce'       => wp_create_nonce( AjaxSearchController::NONCE_ACTION ),
				'actionUsers'       => AjaxSearchController::ACTION_USERS,
				'actionGroups'      => AjaxSearchController::ACTION_GROUPS,
				'reasonPrompt'      => __( 'Reason (required):', 'minhaj-core' ),
				'reasonRequired'    => __( 'A reason is required.', 'minhaj-core' ),
				'selectionRequired' => __( 'Please pick an option from the suggestions.', 'minhaj-core' ),
				'showDetails'       => __( 'Details', 'minhaj-core' ),
				'hideDetails'       => __( 'Hide details', 'minhaj-core' ),
			)
		);
	}

	private function is_groups_page( string $hook_suffix ): bool {
		if ( ! is_string( $hook_suffix ) || '' === $hook_suffix ) {
			return false;
		}

		return str_contains( $hook_suffix, AdminController::MENU_SLUG );
	}
}
