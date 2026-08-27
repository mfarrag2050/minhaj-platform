<?php
/**
 * WP_List_Table for the Groups admin index.
 *
 * @package Minhaj\Modules\Groups\Admin
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Admin;

use Minhaj\Modules\Groups\Domain\GroupStatus;
use Minhaj\Modules\Groups\Repository\GroupRepository;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class GroupsListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct( private readonly GroupRepository $repo ) {
		parent::__construct(
			array(
				'singular' => 'minhaj_group',
				'plural'   => 'minhaj_groups',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'code'              => esc_html__( 'Code', 'minhaj-core' ),
			'type'              => esc_html__( 'Type', 'minhaj-core' ),
			'status'            => esc_html__( 'Status', 'minhaj-core' ),
			'batch_id'          => esc_html__( 'Batch', 'minhaj-core' ),
			'level'             => esc_html__( 'Level', 'minhaj-core' ),
			'teacher_id'        => esc_html__( 'Teacher', 'minhaj-core' ),
			'teaching_language' => esc_html__( 'Language', 'minhaj-core' ),
			'seats'             => esc_html__( 'Seats', 'minhaj-core' ),
			'actions'           => esc_html__( 'Actions', 'minhaj-core' ),
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_actions( $item ): string {
		$manage_url = add_query_arg(
			array(
				'page'     => AdminController::MENU_SLUG,
				'view'     => 'single',
				'group_id' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<a href="%s" class="button button-small">%s</a>',
			esc_url( $manage_url ),
			esc_html__( 'Manage', 'minhaj-core' )
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$filters      = $this->extract_filters();
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * self::PER_PAGE;

		$this->items = $this->repo->list_groups( $filters, self::PER_PAGE, $offset );

		$total = $this->repo->count_groups( $filters );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '—';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_code( $item ): string {
		$url = add_query_arg(
			array(
				'page'     => AdminController::MENU_SLUG,
				'view'     => 'single',
				'group_id' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $url ),
			esc_html( (string) $item['code'] )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_status( $item ): string {
		$status = (string) $item['status'];

		return sprintf(
			'<span class="minhaj-status minhaj-status-%s">%s</span>',
			esc_attr( $status ),
			esc_html( $this->status_label( $status ) )
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_seats( $item ): string {
		$active = $this->repo->count_active_members( (int) $item['id'] );
		$max    = (int) $item['capacity_max'];

		return sprintf(
			'<code>%d/%d</code>',
			$active,
			$max
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_teacher_id( $item ): string {
		$teacher_id = (int) ( $item['teacher_id'] ?? 0 );

		if ( $teacher_id <= 0 ) {
			return '<em>' . esc_html__( 'Unassigned', 'minhaj-core' ) . '</em>';
		}

		$user = get_user_by( 'id', $teacher_id );
		if ( ! $user ) {
			return '#' . esc_html( (string) $teacher_id );
		}

		return esc_html( $user->display_name );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function column_batch_id( $item ): string {
		$batch_id = (int) ( $item['batch_id'] ?? 0 );

		return $batch_id > 0 ? '#' . esc_html( (string) $batch_id ) : '—';
	}

	public function no_items(): void {
		esc_html_e( 'No groups found. Create the first one from the "Add New" button above.', 'minhaj-core' );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$batch  = isset( $_GET['batch_id'] ) ? absint( wp_unslash( $_GET['batch_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang   = isset( $_GET['teaching_language'] ) ? sanitize_key( wp_unslash( $_GET['teaching_language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tid    = isset( $_GET['teacher_id'] ) ? absint( wp_unslash( $_GET['teacher_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="alignleft actions">';

		// Status.
		echo '<label class="screen-reader-text" for="filter-status">' . esc_html__( 'Filter by status', 'minhaj-core' ) . '</label>';
		echo '<select name="status" id="filter-status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'minhaj-core' ) . '</option>';
		foreach ( GroupStatus::all() as $s ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $s ),
				selected( $status, $s, false ),
				esc_html( $this->status_label( $s ) )
			);
		}
		echo '</select>';

		// Batch.
		$batches = $this->repo->distinct_batch_ids();
		if ( array() !== $batches ) {
			echo '<label class="screen-reader-text" for="filter-batch">' . esc_html__( 'Filter by batch', 'minhaj-core' ) . '</label>';
			echo '<select name="batch_id" id="filter-batch">';
			echo '<option value="0">' . esc_html__( 'All batches', 'minhaj-core' ) . '</option>';
			foreach ( $batches as $bid ) {
				printf(
					'<option value="%d"%s>#%d</option>',
					(int) $bid,
					selected( $batch, (int) $bid, false ),
					(int) $bid
				);
			}
			echo '</select>';
		}

		// Language.
		$langs = $this->repo->distinct_teaching_languages();
		if ( array() !== $langs ) {
			echo '<label class="screen-reader-text" for="filter-lang">' . esc_html__( 'Filter by language', 'minhaj-core' ) . '</label>';
			echo '<select name="teaching_language" id="filter-lang">';
			echo '<option value="">' . esc_html__( 'All languages', 'minhaj-core' ) . '</option>';
			foreach ( $langs as $l ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $l ),
					selected( $lang, $l, false ),
					esc_html( strtoupper( $l ) )
				);
			}
			echo '</select>';
		}

		// Teacher.
		$teachers = $this->repo->distinct_teacher_ids();
		if ( array() !== $teachers ) {
			echo '<label class="screen-reader-text" for="filter-teacher">' . esc_html__( 'Filter by teacher', 'minhaj-core' ) . '</label>';
			echo '<select name="teacher_id" id="filter-teacher">';
			echo '<option value="0">' . esc_html__( 'All teachers', 'minhaj-core' ) . '</option>';
			foreach ( $teachers as $tid_option ) {
				$user  = get_user_by( 'id', (int) $tid_option );
				$label = $user ? $user->display_name : '#' . (int) $tid_option;
				printf(
					'<option value="%d"%s>%s</option>',
					(int) $tid_option,
					selected( $tid, (int) $tid_option, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		}

		submit_button( __( 'Filter', 'minhaj-core' ), 'button', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extract_filters(): array {
		// This is a read-only page. Filter values are safely sanitized before
		// being used as prepared-statement parameters — no nonce needed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'status'            => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'batch_id'          => isset( $_GET['batch_id'] ) ? absint( wp_unslash( $_GET['batch_id'] ) ) : 0,
			'teacher_id'        => isset( $_GET['teacher_id'] ) ? absint( wp_unslash( $_GET['teacher_id'] ) ) : 0,
			'teaching_language' => isset( $_GET['teaching_language'] ) ? sanitize_key( wp_unslash( $_GET['teaching_language'] ) ) : '',
			'search'            => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function status_label( string $status ): string {
		$labels = array(
			GroupStatus::DRAFT     => __( 'Draft', 'minhaj-core' ),
			GroupStatus::FORMING   => __( 'Forming', 'minhaj-core' ),
			GroupStatus::SCHEDULED => __( 'Scheduled', 'minhaj-core' ),
			GroupStatus::ACTIVE    => __( 'Active', 'minhaj-core' ),
			GroupStatus::SUSPENDED => __( 'Suspended', 'minhaj-core' ),
			GroupStatus::COMPLETED => __( 'Completed', 'minhaj-core' ),
			GroupStatus::CANCELLED => __( 'Cancelled', 'minhaj-core' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
