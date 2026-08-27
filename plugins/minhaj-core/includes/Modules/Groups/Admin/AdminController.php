<?php
/**
 * Admin UI controller for the Groups module.
 *
 * Every write path goes through GroupService — this controller is a thin
 * translator between HTTP form fields and typed service calls, plus a
 * capability + nonce gate on the way in. Every response either succeeds
 * with a POST-Redirect-GET carrying a notice code, or is aborted with
 * wp_die on a capability failure.
 *
 * Read queries go through GroupRepository (list_groups, list_members,
 * list_audit). The service layer stays untouched by design.
 *
 * @package Minhaj\Modules\Groups\Admin
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Admin;

use Minhaj\Modules\Groups\Domain\GroupCapacity;
use Minhaj\Modules\Groups\Domain\GroupStatus;
use Minhaj\Modules\Groups\Domain\GroupType;
use Minhaj\Modules\Groups\GroupService;
use Minhaj\Modules\Groups\Repository\GroupRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended  -- GET reads are non-destructive; POST paths call check_admin_referer explicitly.

final class AdminController {

	public const MENU_SLUG     = 'minhaj-groups';
	public const CAPABILITY    = 'minhaj_manage_groups';
	private const NONCE_ACTION = 'minhaj_groups_action';
	private const NOTICE_KEY   = 'minhaj_notice';
	private const NOTICE_TYPE  = 'minhaj_notice_type';

	public function __construct(
		private readonly GroupService $service,
		private readonly GroupRepository $repo
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'route_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Minhaj', 'minhaj-core' ),
			__( 'Minhaj', 'minhaj-core' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'route_view' ),
			'dashicons-groups',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Groups', 'minhaj-core' ),
			__( 'Groups', 'minhaj-core' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'route_view' )
		);
	}

	// =============================================================== Router.

	public function route_view(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view groups.', 'minhaj-core' ), 403 );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		switch ( $view ) {
			case 'new':
				$this->render_new_page();
				break;
			case 'single':
				$this->render_single_page();
				break;
			default:
				$this->render_list_page();
				break;
		}
	}

	public function route_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing  -- Nonce checked below via check_admin_referer.
		if ( ! isset( $_POST['minhaj_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing  -- Nonce checked below via check_admin_referer.
		$action = sanitize_key( wp_unslash( $_POST['minhaj_action'] ) );
		if ( '' === $action ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'minhaj-core' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$actor           = (int) get_current_user_id();
		$capacity_notice = '';

		switch ( $action ) {
			case 'create':
				$result = $this->do_create( $actor, $capacity_notice );
				break;
			case 'add_member':
				$result = $this->do_add_member( $actor );
				break;
			case 'remove_member':
				$result = $this->do_remove_member( $actor );
				break;
			case 'transfer_member':
				$result = $this->do_transfer_member( $actor );
				break;
			case 'assign_teacher':
				$result = $this->do_assign_teacher( $actor );
				break;
			case 'transition':
				$result = $this->do_transition( $actor );
				break;
			default:
				$result = new WP_Error( 'invalid_arg', __( 'Unknown action.', 'minhaj-core' ) );
				break;
		}

		$this->redirect_with_notice( $action, $result, $capacity_notice );
	}

	// ============================================================== Actions.

	/**
	 * @param-out string $capacity_notice
	 * @return int|WP_Error
	 */
	private function do_create( int $actor, string &$capacity_notice ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing  -- Nonce validated in route_action().
		$args = array(
			'code'              => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'type'              => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : GroupType::GROUP,
			'level'             => isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '',
			'teaching_language' => isset( $_POST['teaching_language'] ) ? sanitize_key( wp_unslash( $_POST['teaching_language'] ) ) : '',
			'timezone'          => isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : 'UTC',
			'capacity_min'      => isset( $_POST['capacity_min'] ) ? absint( wp_unslash( $_POST['capacity_min'] ) ) : GroupCapacity::GROUP_DEFAULT_MIN,
			'capacity_max'      => isset( $_POST['capacity_max'] ) ? absint( wp_unslash( $_POST['capacity_max'] ) ) : GroupCapacity::GROUP_DEFAULT_MAX,
			'batch_id'          => isset( $_POST['batch_id'] ) ? absint( wp_unslash( $_POST['batch_id'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $args['capacity_max'] > GroupCapacity::GROUP_DEFAULT_MAX ) {
			$capacity_notice = 'capacity_over_promise';
		}

		return $this->service->create( $actor, $args );
	}

	/**
	 * @return int|WP_Error
	 */
	private function do_add_member( int $actor ) {
		$group_id   = $this->post_int( 'group_id' );
		$student_id = $this->post_int( 'student_id' );

		return $this->service->add_member( $actor, $group_id, $student_id );
	}

	/**
	 * @return true|WP_Error
	 */
	private function do_remove_member( int $actor ) {
		$membership_id = $this->post_int( 'membership_id' );
		$reason        = $this->post_reason();

		return $this->service->remove_member( $actor, $membership_id, $reason );
	}

	/**
	 * @return int|WP_Error
	 */
	private function do_transfer_member( int $actor ) {
		$membership_id = $this->post_int( 'membership_id' );
		$to_group_id   = $this->post_int( 'to_group_id' );
		$reason        = $this->post_reason();

		return $this->service->transfer_member( $actor, $membership_id, $to_group_id, $reason );
	}

	/**
	 * @return true|WP_Error
	 */
	private function do_assign_teacher( int $actor ) {
		$group_id   = $this->post_int( 'group_id' );
		$teacher_id = $this->post_int( 'teacher_id' );
		$reason     = $this->post_reason();

		return $this->service->assign_teacher( $actor, $group_id, $teacher_id, $reason );
	}

	/**
	 * @return true|WP_Error
	 */
	private function do_transition( int $actor ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$group_id  = $this->post_int( 'group_id' );
		$to_status = isset( $_POST['to_status'] ) ? sanitize_key( wp_unslash( $_POST['to_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$reason = $this->post_reason();

		return $this->service->transition( $actor, $group_id, $to_status, $reason );
	}

	// ============================================================== Notices.

	/**
	 * @param int|true|WP_Error $result
	 */
	private function redirect_with_notice( string $action, $result, string $capacity_notice ): void {
		$is_error = is_wp_error( $result );
		$code     = $is_error ? $result->get_error_code() : $action . '_ok';
		$type     = $is_error ? 'error' : 'success';

		$args = array(
			'page'            => self::MENU_SLUG,
			self::NOTICE_KEY  => $code,
			self::NOTICE_TYPE => $type,
		);

		if ( ! $is_error ) {
			if ( 'create' === $action ) {
				$args['view']     = 'single';
				$args['group_id'] = (int) $result;
			} elseif ( $this->post_int( 'group_id' ) > 0 ) {
				$args['view']     = 'single';
				$args['group_id'] = $this->post_int( 'group_id' );
			}
		}

		if ( '' !== $capacity_notice && ! $is_error ) {
			$args['minhaj_secondary_notice'] = $capacity_notice;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_notices(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		if ( isset( $_GET[ self::NOTICE_KEY ] ) ) {
			$code    = sanitize_key( wp_unslash( $_GET[ self::NOTICE_KEY ] ) );
			$resolve = ErrorMap::resolve( $code );
			$this->echo_notice( $resolve['message'], $resolve['type'] );
		}

		if ( isset( $_GET['minhaj_secondary_notice'] ) ) {
			$secondary = sanitize_key( wp_unslash( $_GET['minhaj_secondary_notice'] ) );
			$resolve   = ErrorMap::resolve( $secondary, 'warning' );
			$this->echo_notice( $resolve['message'], $resolve['type'] );
		}
	}

	private function echo_notice( string $message, string $type ): void {
		$allowed = array( 'success', 'error', 'warning', 'info' );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'info';
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	// ================================================================ Views.

	public function render_list_page(): void {
		$table = new GroupsListTable( $this->repo );
		$table->prepare_items();

		$new_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'view' => 'new',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Minhaj Groups', 'minhaj-core' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'minhaj-core' ); ?>
			</a>
			<hr class="wp-header-end"/>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>"/>
				<?php $table->search_box( __( 'Search codes', 'minhaj-core' ), 'minhaj-groups-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function render_new_page(): void {
		$nonce_action = self::NONCE_ACTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Create Group', 'minhaj-core' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" name="minhaj_action" value="create"/>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="code"><?php esc_html_e( 'Code', 'minhaj-core' ); ?></label></th>
						<td>
							<input type="text" id="code" name="code" class="regular-text" required
								placeholder="NL-B2609-A1-03"/>
						</td>
					</tr>
					<tr>
						<th><label for="type"><?php esc_html_e( 'Type', 'minhaj-core' ); ?></label></th>
						<td>
							<select id="type" name="type">
								<option value="<?php echo esc_attr( GroupType::GROUP ); ?>">
									<?php esc_html_e( 'Group (3–5 students)', 'minhaj-core' ); ?>
								</option>
								<option value="<?php echo esc_attr( GroupType::INDIVIDUAL ); ?>">
									<?php esc_html_e( 'Individual (1 student)', 'minhaj-core' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="level"><?php esc_html_e( 'Level', 'minhaj-core' ); ?></label></th>
						<td><input type="text" id="level" name="level" class="regular-text"/></td>
					</tr>
					<tr>
						<th><label for="teaching_language"><?php esc_html_e( 'Teaching language', 'minhaj-core' ); ?></label></th>
						<td>
							<input type="text" id="teaching_language" name="teaching_language" maxlength="5"
								class="small-text" placeholder="nl"/>
							<p class="description">
								<?php esc_html_e( 'ISO code — the teacher\'s bridge language.', 'minhaj-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="capacity_min"><?php esc_html_e( 'Capacity min', 'minhaj-core' ); ?></label></th>
						<td>
							<input type="number" id="capacity_min" name="capacity_min" min="1"
								max="<?php echo esc_attr( (string) GroupCapacity::HARD_CAP ); ?>"
								value="<?php echo esc_attr( (string) GroupCapacity::GROUP_DEFAULT_MIN ); ?>"/>
						</td>
					</tr>
					<tr>
						<th><label for="capacity_max"><?php esc_html_e( 'Capacity max', 'minhaj-core' ); ?></label></th>
						<td>
							<input type="number" id="capacity_max" name="capacity_max" min="1"
								max="<?php echo esc_attr( (string) GroupCapacity::HARD_CAP ); ?>"
								value="<?php echo esc_attr( (string) GroupCapacity::GROUP_DEFAULT_MAX ); ?>"/>
							<p class="description" id="capacity-max-hint">
								<?php
								printf(
									/* translators: %d: hard cap enforced by the domain layer. */
									esc_html__( 'The published promise is 3–5. Values above 5 are allowed up to %d but will show a warning after saving.', 'minhaj-core' ),
									(int) GroupCapacity::HARD_CAP
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="batch_id"><?php esc_html_e( 'Batch ID', 'minhaj-core' ); ?></label></th>
						<td>
							<input type="number" id="batch_id" name="batch_id" min="0" value="0"/>
							<p class="description"><?php esc_html_e( 'Leave 0 if not yet assigned.', 'minhaj-core' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create Group', 'minhaj-core' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function render_single_page(): void {
		$group_id = isset( $_GET['group_id'] ) ? absint( wp_unslash( $_GET['group_id'] ) ) : 0;
		if ( $group_id <= 0 ) {
			wp_die( esc_html__( 'Invalid group id.', 'minhaj-core' ), 400 );
		}

		$group = $this->repo->find_group( $group_id );
		if ( null === $group ) {
			wp_die( esc_html__( 'Group not found.', 'minhaj-core' ), 404 );
		}

		$members = $this->repo->list_members( $group_id );
		$audit   = $this->repo->list_audit( $group_id, 50 );
		$active  = $this->repo->count_active_members( $group_id );

		$list_url = add_query_arg(
			array( 'page' => self::MENU_SLUG ),
			admin_url( 'admin.php' )
		);

		$post_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Group', 'minhaj-core' ); ?>
				<code><?php echo esc_html( (string) $group['code'] ); ?></code>
			</h1>
			<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">
				<?php esc_html_e( '← All groups', 'minhaj-core' ); ?>
			</a>
			<hr class="wp-header-end"/>

			<h2><?php esc_html_e( 'Overview', 'minhaj-core' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr><th><?php esc_html_e( 'Type', 'minhaj-core' ); ?></th><td><?php echo esc_html( (string) $group['type'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'minhaj-core' ); ?></th><td><code><?php echo esc_html( (string) $group['status'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Seats', 'minhaj-core' ); ?></th>
						<td>
							<code><?php echo esc_html( sprintf( '%d/%d', $active, (int) $group['capacity_max'] ) ); ?></code>
							&nbsp;<?php esc_html_e( 'min:', 'minhaj-core' ); ?> <?php echo (int) $group['capacity_min']; ?>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Teacher', 'minhaj-core' ); ?></th>
						<td>
							<?php
							$teacher_id = (int) ( $group['teacher_id'] ?? 0 );
							if ( $teacher_id > 0 ) {
								$user = get_user_by( 'id', $teacher_id );
								echo $user ? esc_html( $user->display_name ) : '#' . (int) $teacher_id;
							} else {
								echo '<em>' . esc_html__( 'Unassigned', 'minhaj-core' ) . '</em>';
							}
							?>
						</td>
					</tr>
					<tr><th><?php esc_html_e( 'Language', 'minhaj-core' ); ?></th><td><?php echo esc_html( strtoupper( (string) $group['teaching_language'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Batch', 'minhaj-core' ); ?></th><td><?php echo $group['batch_id'] ? '#' . (int) $group['batch_id'] : '—'; ?></td></tr>
					<tr>
						<th><?php esc_html_e( 'Planned start', 'minhaj-core' ); ?></th>
						<td><?php echo esc_html( $group['planned_start_date'] ? (string) $group['planned_start_date'] : '—' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Actual start', 'minhaj-core' ); ?></th>
						<td><?php echo esc_html( $group['actual_start_date'] ? (string) $group['actual_start_date'] : '—' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Expected end', 'minhaj-core' ); ?></th>
						<td>
							<?php echo esc_html( $group['expected_end_date'] ? (string) $group['expected_end_date'] : '—' ); ?>
							<?php if ( ! empty( $group['has_unscheduled_makeup'] ) ) : ?>
								&nbsp;<span class="mj-badge-warning" title="<?php esc_attr_e( 'A pending make-up has no time yet — the expected end date may shift once it is scheduled.', 'minhaj-core' ); ?>">⚠ <?php esc_html_e( 'pending make-up', 'minhaj-core' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Members', 'minhaj-core' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Seat', 'minhaj-core' ); ?></th>
						<th><?php esc_html_e( 'Student', 'minhaj-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'minhaj-core' ); ?></th>
						<th><?php esc_html_e( 'Joined', 'minhaj-core' ); ?></th>
						<th><?php esc_html_e( 'Left', 'minhaj-core' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'minhaj-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $members ) : ?>
						<tr><td colspan="6"><em><?php esc_html_e( 'No members yet.', 'minhaj-core' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $members as $m ) : ?>
							<tr>
								<td><code><?php echo (int) $m['seat_index']; ?></code></td>
								<td>#<?php echo (int) $m['student_id']; ?>
									<?php
									$student = get_user_by( 'id', (int) $m['student_id'] );
									if ( $student ) {
										echo ' ' . esc_html( $student->display_name );
									}
									?>
								</td>
								<td><code><?php echo esc_html( (string) $m['status'] ); ?></code></td>
								<td><?php echo esc_html( (string) $m['joined_at'] ); ?></td>
								<td><?php echo esc_html( (string) ( $m['left_at'] ?? '' ) ); ?></td>
								<td>
									<?php if ( 'active' === $m['status'] ) : ?>
										<?php
										$remove_form_id   = 'mj-remove-form-' . (int) $m['id'];
										$remove_panel     = 'mj-remove-panel-' . (int) $m['id'];
										$transfer_form_id = 'mj-transfer-form-' . (int) $m['id'];
										$transfer_panel   = 'mj-transfer-panel-' . (int) $m['id'];
										$to_hidden_id     = 'mj-to-group-' . (int) $m['id'];
										?>
									<div class="row-actions visible">
										<span class="delete"><a href="#" class="mj-row-remove"
											data-panel="<?php echo esc_attr( $remove_panel ); ?>"><?php esc_html_e( 'Remove', 'minhaj-core' ); ?></a> | </span>
										<span class="edit"><a href="#" class="mj-row-transfer"
											data-panel="<?php echo esc_attr( $transfer_panel ); ?>"><?php esc_html_e( 'Transfer', 'minhaj-core' ); ?></a></span>
									</div>
									<form id="<?php echo esc_attr( $remove_form_id ); ?>" method="post" action="<?php echo esc_url( $post_url ); ?>">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="minhaj_action" value="remove_member"/>
										<input type="hidden" name="membership_id" value="<?php echo (int) $m['id']; ?>"/>
										<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>"/>
										<div id="<?php echo esc_attr( $remove_panel ); ?>" class="mj-remove-panel" style="display:none;padding:4px 0">
											<label>
												<?php esc_html_e( 'Reason', 'minhaj-core' ); ?>
												<input type="text" name="reason" required/>
											</label>
											<button type="submit" class="button button-small button-link-delete">
												<?php esc_html_e( 'Remove', 'minhaj-core' ); ?>
											</button>
											<button type="button" class="button button-small mj-row-remove-cancel">
												<?php esc_html_e( 'Cancel', 'minhaj-core' ); ?>
											</button>
										</div>
									</form>
									<form id="<?php echo esc_attr( $transfer_form_id ); ?>" method="post" action="<?php echo esc_url( $post_url ); ?>">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="minhaj_action" value="transfer_member"/>
										<input type="hidden" name="membership_id" value="<?php echo (int) $m['id']; ?>"/>
										<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>"/>
										<input type="hidden" id="<?php echo esc_attr( $to_hidden_id ); ?>" name="to_group_id" value=""/>
										<div id="<?php echo esc_attr( $transfer_panel ); ?>" class="mj-transfer-panel" style="display:none;padding:4px 0">
											<label>
												<?php esc_html_e( 'Target group', 'minhaj-core' ); ?>
												<input type="text" class="mj-group-search regular-text"
													data-target="<?php echo esc_attr( $to_hidden_id ); ?>"
													data-exclude="<?php echo (int) $group['id']; ?>"
													placeholder="<?php echo esc_attr__( 'Search by code…', 'minhaj-core' ); ?>"/>
											</label>
											<label>
												<?php esc_html_e( 'Reason', 'minhaj-core' ); ?>
												<input type="text" name="reason" required/>
											</label>
											<button type="submit" class="button button-small button-primary">
												<?php esc_html_e( 'Transfer', 'minhaj-core' ); ?>
											</button>
											<button type="button" class="button button-small mj-row-transfer-cancel">
												<?php esc_html_e( 'Cancel', 'minhaj-core' ); ?>
											</button>
										</div>
									</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Add member', 'minhaj-core' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="minhaj_action" value="add_member"/>
				<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>"/>
				<input type="hidden" id="mj-add-student-id" name="student_id" value=""/>
				<label>
					<?php esc_html_e( 'Student', 'minhaj-core' ); ?>
					<input type="text" class="mj-user-search regular-text"
						data-role="student" data-target="mj-add-student-id"
						placeholder="<?php echo esc_attr__( 'Search by name…', 'minhaj-core' ); ?>"/>
				</label>
				<?php submit_button( __( 'Add member', 'minhaj-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<h3><?php esc_html_e( 'Assign teacher', 'minhaj-core' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="minhaj_action" value="assign_teacher"/>
				<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>"/>
				<input type="hidden" id="mj-assign-teacher-id" name="teacher_id" value=""/>
				<label>
					<?php esc_html_e( 'Teacher', 'minhaj-core' ); ?>
					<input type="text" class="mj-user-search regular-text"
						data-role="teacher" data-target="mj-assign-teacher-id"
						placeholder="<?php echo esc_attr__( 'Search by name…', 'minhaj-core' ); ?>"/>
				</label>
				<label>
					<?php esc_html_e( 'Reason', 'minhaj-core' ); ?>
					<input type="text" name="reason" required/>
				</label>
				<?php submit_button( __( 'Assign', 'minhaj-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<h3><?php esc_html_e( 'Transition state', 'minhaj-core' ); ?></h3>
			<?php
			$allowed = GroupStatus::allowed_transitions( (string) $group['status'] );
			if ( array() === $allowed ) :
				?>
				<p><em><?php esc_html_e( 'Terminal state — no further transitions available.', 'minhaj-core' ); ?></em></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="minhaj_action" value="transition"/>
					<input type="hidden" name="group_id" value="<?php echo (int) $group['id']; ?>"/>
					<label>
						<?php esc_html_e( 'To status', 'minhaj-core' ); ?>
						<select name="to_status" required>
							<?php foreach ( $allowed as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Reason', 'minhaj-core' ); ?>
						<input type="text" name="reason" required/>
					</label>
					<?php submit_button( __( 'Transition', 'minhaj-core' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Audit log', 'minhaj-core' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'minhaj-core' ); ?></th>
						<th><?php esc_html_e( 'What happened', 'minhaj-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $audit ) : ?>
						<tr><td colspan="2"><em><?php esc_html_e( 'No audit entries yet.', 'minhaj-core' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $audit as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
								<td>
									<span class="mj-audit-sentence"><?php echo AuditFormatter::sentence( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AuditFormatter::sentence() returns HTML-safe, translated text. ?></span>
									&nbsp;<a href="#" class="mj-audit-details-toggle"><?php esc_html_e( 'Details', 'minhaj-core' ); ?></a>
									<pre class="mj-audit-payload" style="display:none;white-space:pre-wrap;background:#f6f7f7;padding:6px;margin:6px 0;border:1px solid #dcdcde">
									<?php
									echo esc_html(
										wp_json_encode(
											array(
												'action'  => (string) $row['action'],
												'subject_id' => (int) ( $row['subject_id'] ?? 0 ),
												'payload' => json_decode( (string) ( $row['payload_json'] ?? '{}' ), true ),
											),
											JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
										)
									);
									?>
									</pre>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ============================================================== Helpers.

	private function post_int( string $key ): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce validated in route_action() before any helper runs.
		if ( ! isset( $_POST[ $key ] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_POST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function post_reason(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce validated in route_action() before any helper runs.
		if ( ! isset( $_POST['reason'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST['reason'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
