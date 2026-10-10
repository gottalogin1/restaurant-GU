<?php
/**
 * Admin screens for advertiser leads.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Advertising;

defined( 'ABSPATH' ) || exit;

/**
 * Advertiser leads → list with columns, filters (status, package, budget,
 * assigned user) and a CSV export of the filtered list; and the edit screen
 * with the lead's details, pipeline status, assigned user and notes.
 *
 * Everything here needs Leads::CAP, which only administrators have.
 */
final class Admin {

	public const EXPORT_ACTION = 'gr_export_advertiser_leads';
	public const SAVE_NONCE    = 'gr_save_advertiser_lead';
	public const NOTICE        = 'gr_lead_notice_';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = Leads::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( self::class, 'sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'filter_controls' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_filters_to_list' ) );
		add_action( 'load-edit.php', array( self::class, 'maybe_export' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", array( self::class, 'bulk_actions' ) );
		add_action( "add_meta_boxes_{$type}", array( self::class, 'add_meta_boxes' ) );
		add_action( "save_post_{$type}", array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );
	}

	/**
	 * Every lead is private, so the list does not say so on each row.
	 *
	 * @param array<string, string> $states Post states.
	 * @param \WP_Post              $post   Post.
	 * @return array<string, string>
	 */
	public static function post_states( $states, $post ): array {
		if ( $post instanceof \WP_Post && Leads::POST_TYPE === $post->post_type ) {
			unset( $states['private'] );
		}
		return (array) $states;
	}

	/**
	 * List columns.
	 *
	 * @param array<string, string> $columns Default columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '<input type="checkbox" />',
			'title'         => __( 'Lead', 'guam-restaurants-core' ),
			'gr_status'     => __( 'Status', 'guam-restaurants-core' ),
			'gr_restaurant' => __( 'Restaurant', 'guam-restaurants-core' ),
			'gr_package'    => __( 'Package', 'guam-restaurants-core' ),
			'gr_budget'     => __( 'Budget', 'guam-restaurants-core' ),
			'gr_contact'    => __( 'Contact', 'guam-restaurants-core' ),
			'gr_assigned'   => __( 'Assigned to', 'guam-restaurants-core' ),
			'date'          => __( 'Received', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Prints a column cell.
	 *
	 * @param string $column  Column.
	 * @param int    $lead_id Lead.
	 * @return void
	 */
	public static function column( $column, $lead_id ): void {
		$lead = Leads::get( (int) $lead_id );
		if ( null === $lead ) {
			return;
		}
		switch ( $column ) {
			case 'gr_status':
				echo esc_html( Leads::statuses()[ $lead['status'] ] );
				break;
			case 'gr_restaurant':
				echo $lead['restaurant_id'] > 0 ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $lead['restaurant_id'] ) ), esc_html( get_the_title( $lead['restaurant_id'] ) ) ) : '&ndash;';
				break;
			case 'gr_package':
				echo esc_html( Leads::packages()[ $lead['package'] ] ?? $lead['package'] );
				break;
			case 'gr_budget':
				echo esc_html( Leads::budgets()[ $lead['budget'] ] ?? $lead['budget'] );
				break;
			case 'gr_contact':
				printf( '<a href="mailto:%1$s">%2$s</a>', esc_attr( $lead['email'] ), esc_html( $lead['email'] ) );
				if ( '' !== $lead['phone'] ) {
					echo '<br>' . esc_html( $lead['phone'] );
				}
				break;
			case 'gr_assigned':
				$user = $lead['assigned_user'] ? get_userdata( $lead['assigned_user'] ) : false;
				echo $user ? esc_html( $user->display_name ) : '&ndash;';
				break;
		}
	}

	/**
	 * Sortable columns.
	 *
	 * @param array<string, string> $columns Defaults.
	 * @return array<string, string>
	 */
	public static function sortable_columns( $columns ): array {
		$columns['date'] = 'date';
		return $columns;
	}

	/**
	 * The filters a request asks for, keeping only known values.
	 *
	 * @param array<string, mixed> $query Query args (usually $_GET).
	 * @return array{status: string, package: string, budget: string, assigned: string, search: string}
	 */
	public static function filters_from( array $query ): array {
		$pick = static function ( string $key, array $allowed ) use ( $query ): string {
			$value = sanitize_key( (string) ( $query[ $key ] ?? '' ) );
			return isset( $allowed[ $value ] ) ? $value : '';
		};

		$assigned = sanitize_key( (string) ( $query['gr_assigned'] ?? '' ) );
		if ( 'none' !== $assigned ) {
			$assigned = absint( $assigned ) > 0 ? (string) absint( $assigned ) : '';
		}

		return array(
			'status'   => $pick( 'gr_status', Leads::statuses() ),
			'package'  => $pick( 'gr_package', Leads::packages() ),
			'budget'   => $pick( 'gr_budget', Leads::budgets() ),
			'assigned' => $assigned,
			'search'   => sanitize_text_field( (string) ( $query['s'] ?? '' ) ),
		);
	}

	/**
	 * Meta query for a set of filters.
	 *
	 * @param array{status: string, package: string, budget: string, assigned: string} $filters Filters.
	 * @return array<int|string, mixed>
	 */
	public static function meta_query( array $filters ): array {
		$clauses = array();
		if ( 'new' === $filters['status'] ) {
			// Leads saved before a status was set count as new.
			$clauses[] = array(
				'relation' => 'OR',
				array(
					'key'   => Leads::META_STATUS,
					'value' => 'new',
				),
				array(
					'key'     => Leads::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( '' !== $filters['status'] ) {
			$clauses[] = array(
				'key'   => Leads::META_STATUS,
				'value' => $filters['status'],
			);
		}
		foreach ( array(
			'package' => Leads::META_PACKAGE,
			'budget'  => Leads::META_BUDGET,
		) as $filter => $meta ) {
			if ( '' !== $filters[ $filter ] ) {
				$clauses[] = array(
					'key'   => $meta,
					'value' => $filters[ $filter ],
				);
			}
		}
		if ( 'none' === $filters['assigned'] ) {
			$clauses[] = array(
				'relation' => 'OR',
				array(
					'key'     => Leads::META_ASSIGNED,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => Leads::META_ASSIGNED,
					'value' => '0',
				),
			);
		} elseif ( '' !== $filters['assigned'] ) {
			$clauses[] = array(
				'key'   => Leads::META_ASSIGNED,
				'value' => $filters['assigned'],
			);
		}
		if ( count( $clauses ) > 1 ) {
			$clauses['relation'] = 'AND';
		}
		return $clauses;
	}

	/**
	 * Filter dropdowns and the export button above the list.
	 *
	 * @param string $post_type Post type of the list.
	 * @return void
	 */
	public static function filter_controls( $post_type ): void {
		if ( Leads::POST_TYPE !== $post_type || ! current_user_can( Leads::CAP ) ) {
			return;
		}
		$filters = self::filters_from( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.

		$users = array( 'none' => __( 'Unassigned', 'guam-restaurants-core' ) );
		foreach ( Leads::assignable_users() as $user ) {
			$users[ (string) $user->ID ] = $user->display_name;
		}

		self::dropdown( 'gr_status', __( 'All statuses', 'guam-restaurants-core' ), Leads::statuses(), $filters['status'] );
		self::dropdown( 'gr_package', __( 'All packages', 'guam-restaurants-core' ), Leads::packages(), $filters['package'] );
		self::dropdown( 'gr_budget', __( 'All budgets', 'guam-restaurants-core' ), Leads::budgets(), $filters['budget'] );
		self::dropdown( 'gr_assigned', __( 'Anyone', 'guam-restaurants-core' ), $users, $filters['assigned'] );

		wp_nonce_field( self::EXPORT_ACTION, '_gr_export_nonce', false );
		printf(
			'<button type="submit" name="%1$s" value="1" class="button">%2$s</button>',
			esc_attr( self::EXPORT_ACTION ),
			esc_html__( 'Export CSV', 'guam-restaurants-core' )
		);
	}

	/**
	 * Applies the list filters to the main query of the leads list.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function apply_filters_to_list( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || Leads::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta = self::meta_query( self::filters_from( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		if ( $meta ) {
			$query->set( 'meta_query', $meta );
		}
	}

	/**
	 * Streams the CSV when the export button was pressed.
	 *
	 * @return void
	 */
	public static function maybe_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked in authorize_export().
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( Leads::POST_TYPE !== $post_type || empty( $_GET[ self::EXPORT_ACTION ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) ( $_GET['_gr_export_nonce'] ?? '' ) ) );
		$query = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed = self::authorize_export( $nonce );
		if ( is_wp_error( $allowed ) ) {
			wp_die( esc_html( $allowed->get_error_message() ), '', array( 'response' => 403 ) );
		}

		$rows = self::csv_rows( self::lead_ids( self::filters_from( $query ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="advertiser-leads-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Whether the current user may export.
	 *
	 * @param string $nonce Nonce from the list form.
	 * @return true|\WP_Error
	 */
	public static function authorize_export( string $nonce ) {
		if ( ! current_user_can( Leads::CAP ) ) {
			return new \WP_Error( 'gr_lead_forbidden', __( 'You are not allowed to export advertiser leads.', 'guam-restaurants-core' ) );
		}
		if ( ! wp_verify_nonce( $nonce, self::EXPORT_ACTION ) ) {
			return new \WP_Error( 'gr_lead_nonce', __( 'The export link has expired. Reload the list and try again.', 'guam-restaurants-core' ) );
		}
		return true;
	}

	/**
	 * Leads matching the filters, newest first.
	 *
	 * @param array{status: string, package: string, budget: string, assigned: string, search: string} $filters Filters.
	 * @return int[]
	 */
	public static function lead_ids( array $filters ): array {
		$args = array(
			'post_type'      => Leads::POST_TYPE,
			'post_status'    => array( 'private', 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => self::meta_query( $filters ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin export.
		);
		if ( '' !== $filters['search'] ) {
			$args['s'] = $filters['search'];
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * CSV rows, header first. Cells that a spreadsheet would run as a
	 * formula are prefixed with an apostrophe.
	 *
	 * @param int[] $lead_ids Leads.
	 * @return array<int, array<int, string>>
	 */
	public static function csv_rows( array $lead_ids ): array {
		$rows = array(
			array(
				__( 'Received', 'guam-restaurants-core' ),
				__( 'Status', 'guam-restaurants-core' ),
				__( 'Name', 'guam-restaurants-core' ),
				__( 'Business', 'guam-restaurants-core' ),
				__( 'Restaurant', 'guam-restaurants-core' ),
				__( 'Restaurant ID', 'guam-restaurants-core' ),
				__( 'Email', 'guam-restaurants-core' ),
				__( 'Phone', 'guam-restaurants-core' ),
				__( 'Package', 'guam-restaurants-core' ),
				__( 'Budget', 'guam-restaurants-core' ),
				__( 'Message', 'guam-restaurants-core' ),
				__( 'Assigned to', 'guam-restaurants-core' ),
				__( 'Notes', 'guam-restaurants-core' ),
			),
		);
		foreach ( $lead_ids as $lead_id ) {
			$lead = Leads::get( (int) $lead_id );
			if ( null === $lead ) {
				continue;
			}
			$user   = $lead['assigned_user'] ? get_userdata( $lead['assigned_user'] ) : false;
			$rows[] = array_map(
				array( self::class, 'csv_cell' ),
				array(
					$lead['received'],
					Leads::statuses()[ $lead['status'] ],
					$lead['name'],
					$lead['business'],
					$lead['restaurant_id'] > 0 ? html_entity_decode( get_the_title( $lead['restaurant_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
					$lead['restaurant_id'] > 0 ? (string) $lead['restaurant_id'] : '',
					$lead['email'],
					$lead['phone'],
					Leads::packages()[ $lead['package'] ] ?? $lead['package'],
					Leads::budgets()[ $lead['budget'] ] ?? $lead['budget'],
					$lead['message'],
					$user ? $user->display_name : '',
					$lead['notes'],
				)
			);
		}
		return $rows;
	}

	/**
	 * Neutralises spreadsheet formulas (CSV injection).
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * Leads have no public view and no quick edit.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ): array {
		if ( $post instanceof \WP_Post && Leads::POST_TYPE === $post->post_type ) {
			unset( $actions['view'], $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	/**
	 * Bulk edit would change post statuses, not lead statuses; only trash stays.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ): array {
		unset( $actions['edit'] );
		return $actions;
	}

	/**
	 * Adds the details and pipeline boxes.
	 *
	 * @return void
	 */
	public static function add_meta_boxes(): void {
		add_meta_box( 'gr-lead-details', __( 'Inquiry', 'guam-restaurants-core' ), array( self::class, 'details_box' ), Leads::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'gr-lead-pipeline', __( 'Follow-up', 'guam-restaurants-core' ), array( self::class, 'pipeline_box' ), Leads::POST_TYPE, 'side', 'high' );
	}

	/**
	 * Contact details, editable so leads from a phone call can be added by hand.
	 *
	 * @param \WP_Post $post Lead.
	 * @return void
	 */
	public static function details_box( $post ): void {
		$lead = Leads::get( (int) $post->ID ) ?? array();
		wp_nonce_field( self::SAVE_NONCE, '_gr_lead_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( array(
			'name'     => array( __( 'Name', 'guam-restaurants-core' ), 'text', Leads::MAX_NAME ),
			'business' => array( __( 'Business', 'guam-restaurants-core' ), 'text', Leads::MAX_BUSINESS ),
			'email'    => array( __( 'Email', 'guam-restaurants-core' ), 'email', 254 ),
			'phone'    => array( __( 'Phone', 'guam-restaurants-core' ), 'tel', Leads::MAX_PHONE ),
		) as $field => $def ) {
			printf(
				'<tr><th scope="row"><label for="gr-lead-%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="gr-lead-%1$s" name="gr_lead[%1$s]" value="%4$s" maxlength="%5$d"></td></tr>',
				esc_attr( $field ),
				esc_html( $def[0] ),
				esc_attr( $def[1] ),
				esc_attr( (string) ( $lead[ $field ] ?? '' ) ),
				(int) $def[2]
			);
		}

		$restaurant = (int) ( $lead['restaurant_id'] ?? 0 );
		printf(
			'<tr><th scope="row"><label for="gr-lead-restaurant">%1$s</label></th><td><input type="number" min="0" class="small-text" id="gr-lead-restaurant" name="gr_lead[restaurant_id]" value="%2$s"> %3$s<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Restaurant ID', 'guam-restaurants-core' ),
			$restaurant > 0 ? esc_attr( (string) $restaurant ) : '',
			$restaurant > 0 ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $restaurant ) ), esc_html( get_the_title( $restaurant ) ) ) : '',
			esc_html__( 'Leave empty when the lead is not about a listed restaurant.', 'guam-restaurants-core' )
		);

		foreach ( array(
			'package' => array( __( 'Package', 'guam-restaurants-core' ), Leads::packages() ),
			'budget'  => array( __( 'Budget', 'guam-restaurants-core' ), Leads::budgets() ),
		) as $field => $def ) {
			printf( '<tr><th scope="row"><label for="gr-lead-%1$s">%2$s</label></th><td><select id="gr-lead-%1$s" name="gr_lead[%1$s]">', esc_attr( $field ), esc_html( $def[0] ) );
			foreach ( $def[1] as $value => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $lead[ $field ] ?? 'not_sure', $value, false ), esc_html( $label ) );
			}
			echo '</select></td></tr>';
		}

		printf(
			'<tr><th scope="row"><label for="gr-lead-message">%1$s</label></th><td><textarea class="large-text" rows="6" id="gr-lead-message" name="gr_lead[message]" maxlength="%2$d">%3$s</textarea></td></tr>',
			esc_html__( 'Message', 'guam-restaurants-core' ),
			(int) Leads::MAX_MESSAGE,
			esc_textarea( (string) ( $lead['message'] ?? '' ) )
		);
		echo '</tbody></table>';
	}

	/**
	 * Status, assigned user and notes.
	 *
	 * @param \WP_Post $post Lead.
	 * @return void
	 */
	public static function pipeline_box( $post ): void {
		$lead = Leads::get( (int) $post->ID );

		printf( '<p><label for="gr-lead-status"><strong>%s</strong></label><br><select id="gr-lead-status" name="gr_lead[status]" class="widefat">', esc_html__( 'Status', 'guam-restaurants-core' ) );
		foreach ( Leads::statuses() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $lead['status'] ?? 'new', $value, false ), esc_html( $label ) );
		}
		echo '</select></p>';

		printf( '<p><label for="gr-lead-assigned"><strong>%1$s</strong></label><br><select id="gr-lead-assigned" name="gr_lead[assigned_user]" class="widefat"><option value="0">%2$s</option>', esc_html__( 'Assigned to', 'guam-restaurants-core' ), esc_html__( 'Nobody', 'guam-restaurants-core' ) );
		foreach ( Leads::assignable_users() as $user ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $user->ID, selected( (int) ( $lead['assigned_user'] ?? 0 ), (int) $user->ID, false ), esc_html( $user->display_name ) );
		}
		echo '</select></p>';

		printf(
			'<p><label for="gr-lead-notes"><strong>%1$s</strong></label><br><textarea id="gr-lead-notes" name="gr_lead[notes]" class="widefat" rows="8">%2$s</textarea></p><p class="description">%3$s</p>',
			esc_html__( 'Notes', 'guam-restaurants-core' ),
			esc_textarea( (string) ( $lead['notes'] ?? '' ) ),
			esc_html__( 'Private. Only administrators see leads.', 'guam-restaurants-core' )
		);
	}

	/**
	 * Saves both boxes. Invalid details are not saved and an error notice
	 * says why; the status, assignee and notes are saved on their own.
	 *
	 * @param int      $lead_id Lead.
	 * @param \WP_Post $post    Lead.
	 * @return void
	 */
	public static function save( $lead_id, $post ): void {
		unset( $post );
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $lead_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_gr_lead_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gr_lead_nonce'] ) ), self::SAVE_NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $lead_id ) ) {
			return;
		}
		$input = isset( $_POST['gr_lead'] ) && is_array( $_POST['gr_lead'] ) ? wp_unslash( $_POST['gr_lead'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in Leads.

		$messages = array();
		$result   = self::save_lead( (int) $lead_id, $input );
		if ( is_wp_error( $result ) ) {
			$messages = $result->get_error_messages();
		}
		if ( $messages ) {
			set_transient( self::NOTICE . get_current_user_id(), $messages, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Validates and saves an edit. Used by save() and the tests.
	 *
	 * @param int                  $lead_id Lead.
	 * @param array<string, mixed> $input   Fields from the edit screen.
	 * @return true|\WP_Error
	 */
	public static function save_lead( int $lead_id, array $input ) {
		if ( ! current_user_can( 'edit_post', $lead_id ) ) {
			return new \WP_Error( 'gr_lead_forbidden', __( 'You are not allowed to edit this lead.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}

		$errors   = new \WP_Error();
		$pipeline = Leads::update_pipeline( $lead_id, array_intersect_key( $input, array_flip( array( 'status', 'assigned_user', 'notes' ) ) ) );
		if ( is_wp_error( $pipeline ) ) {
			$errors->merge_from( $pipeline );
		}

		$fields = Leads::validate( $input );
		if ( is_wp_error( $fields ) ) {
			$errors->merge_from( $fields );
		} else {
			Leads::save_fields( $lead_id, $fields );
			// Unhook while updating the title so save() does not run again.
			remove_action( 'save_post_' . Leads::POST_TYPE, array( self::class, 'save' ), 10 );
			wp_update_post(
				array(
					'ID'         => $lead_id,
					'post_title' => Leads::title( $fields ),
				)
			);
			add_action( 'save_post_' . Leads::POST_TYPE, array( self::class, 'save' ), 10, 2 );
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Shows errors from the last save.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$key      = self::NOTICE . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) || ! $messages ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-error"><p>%1$s</p><ul><li>%2$s</li></ul></div>',
			esc_html__( 'Some lead details were not saved:', 'guam-restaurants-core' ),
			implode( '</li><li>', array_map( 'esc_html', $messages ) )
		);
	}

	/**
	 * Prints a filter dropdown.
	 *
	 * @param string                $name    Query arg.
	 * @param string                $all     Label for "no filter".
	 * @param array<string, string> $options Value => label.
	 * @param string                $current Selected value.
	 * @return void
	 */
	private static function dropdown( string $name, string $all, array $options, string $current ): void {
		printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%1$s" id="%1$s"><option value="">%2$s</option>', esc_attr( $name ), esc_html( $all ) );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}
