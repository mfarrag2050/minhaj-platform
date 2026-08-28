<?php
/**
 * Test / local-dev implementation of ZoomClient. Records every call and
 * returns programmable canned responses. Wp-env integration tests inject
 * this via the `minhaj_zoom_client` filter so the guardrails around the
 * Zoom boundary can be proved without hitting a real Zoom account.
 *
 * @package Minhaj\Modules\Meetings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Zoom;

defined( 'ABSPATH' ) || exit;

final class FakeZoomClient implements ZoomClient {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = array();

	private int $next_meeting_id = 1000000;
	private int $next_registrant = 500000;

	/**
	 * @var array<string, mixed>
	 */
	private array $account_settings;

	/**
	 * @param array<string, mixed> $account_settings
	 */
	public function __construct( array $account_settings = array() ) {
		$this->account_settings = $account_settings;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function create_meeting( string $zoom_user_id, array $args ): array {
		$id = 'm-' . ( ++$this->next_meeting_id );

		$this->calls[] = array(
			'method'       => 'create_meeting',
			'zoom_user_id' => $zoom_user_id,
			'args'         => $args,
		);

		return array(
			'id'        => $id,
			'uuid'      => 'uuid-' . $id,
			'start_url' => 'https://zoom.example/start/' . $id,
			'join_url'  => 'https://zoom.example/join/' . $id,
			'password'  => 'test-pw',
		);
	}

	public function delete_meeting( string $zoom_meeting_id ): void {
		$this->calls[] = array(
			'method' => 'delete_meeting',
			'id'     => $zoom_meeting_id,
		);
	}

	/**
	 * @param array<string, mixed> $registrant
	 * @return array<string, mixed>
	 */
	public function add_registrant( string $zoom_meeting_id, array $registrant ): array {
		$rid = 'r-' . ( ++$this->next_registrant );

		$this->calls[] = array(
			'method'     => 'add_registrant',
			'meeting_id' => $zoom_meeting_id,
			'registrant' => $registrant,
		);

		return array(
			'registrant_id' => $rid,
			'join_url'      => 'https://zoom.example/j/' . $zoom_meeting_id . '/' . $rid,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_meeting( string $zoom_meeting_id ): array {
		$this->calls[] = array(
			'method' => 'get_meeting',
			'id'     => $zoom_meeting_id,
		);

		return array( 'id' => $zoom_meeting_id );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_account_settings(): array {
		$this->calls[] = array( 'method' => 'get_account_settings' );

		return $this->account_settings;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function set_account_settings( array $settings ): void {
		$this->account_settings = $settings;
	}
}
