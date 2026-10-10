<?php
/**
 * Mall verification: unverified malls are hidden from the public.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps unverified malls out of public term lists, archives and the REST term
 * list, and adds a "Verified" checkbox to the mall edit screen that records
 * who verified it and when.
 */
final class Mall_Verification {

	private const NONCE_ACTION = 'guam_restaurants_verify_mall';
	private const NONCE_FIELD  = 'guam_restaurants_mall_nonce';

	/**
	 * Argument for get_terms() that skips the filter, for code that must see every mall.
	 */
	public const INCLUDE_UNVERIFIED_ARG = 'guam_restaurants_include_unverified';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'get_terms_args', array( self::class, 'hide_unverified' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'block_unverified_archive' ) );
		add_action( Content_Types::TAX_MALL . '_edit_form_fields', array( self::class, 'render_field' ) );
		add_action( 'edited_' . Content_Types::TAX_MALL, array( self::class, 'save_field' ) );
	}

	/**
	 * Whether the current request should see unverified malls: admin
	 * screens, WP-CLI, cron, and users who can edit posts.
	 *
	 * @return bool
	 */
	public static function can_see_unverified(): bool {
		return is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || current_user_can( 'edit_posts' );
	}

	/**
	 * Runs a callback with unverified malls visible to every term query,
	 * including WordPress's own lookups (term_exists() inside
	 * wp_set_object_terms() would otherwise not find an unverified mall).
	 * For back-end jobs such as imports.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	public static function with_unverified( callable $callback ) {
		$include = static function ( array $args ): array {
			$args[ self::INCLUDE_UNVERIFIED_ARG ] = true;
			return $args;
		};
		add_filter( 'get_terms_args', $include, 5 );
		try {
			return $callback();
		} finally {
			remove_filter( 'get_terms_args', $include, 5 );
		}
	}

	/**
	 * Whether a mall is verified.
	 *
	 * @param int $term_id Mall term ID.
	 * @return bool
	 */
	public static function is_verified( int $term_id ): bool {
		return (bool) get_term_meta( $term_id, Term_Meta::VERIFIED, true );
	}

	/**
	 * Restricts mall-only term queries to verified malls for the public.
	 *
	 * @param array<string, mixed> $args       get_terms() arguments.
	 * @param string[]             $taxonomies Taxonomies queried.
	 * @return array<string, mixed>
	 */
	public static function hide_unverified( array $args, array $taxonomies ): array {
		if ( array( Content_Types::TAX_MALL ) !== array_values( $taxonomies ) || ! empty( $args[ self::INCLUDE_UNVERIFIED_ARG ] ) || self::can_see_unverified() ) {
			return $args;
		}

		$clause = array(
			'key'   => Term_Meta::VERIFIED,
			'value' => '1',
		);

		$args['meta_query'] = empty( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			? array( $clause )
			: array(
				'relation' => 'AND',
				$args['meta_query'],
				$clause,
			);

		return $args;
	}

	/**
	 * Returns a 404 for an unverified mall's archive.
	 *
	 * @return void
	 */
	public static function block_unverified_archive(): void {
		if ( ! is_tax( Content_Types::TAX_MALL ) || self::can_see_unverified() ) {
			return;
		}

		$term = get_queried_object();
		if ( $term instanceof \WP_Term && ! self::is_verified( $term->term_id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Renders the Verified checkbox on the mall edit screen.
	 *
	 * @param \WP_Term $term Mall being edited.
	 * @return void
	 */
	public static function render_field( \WP_Term $term ): void {
		$verified = self::is_verified( $term->term_id );
		$by       = (int) get_term_meta( $term->term_id, Term_Meta::VERIFIED_BY, true );
		$on       = (string) get_term_meta( $term->term_id, Term_Meta::VERIFIED_ON, true );
		$user     = $by ? get_userdata( $by ) : false;
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Verified', 'guam-restaurants-core' ); ?></th>
			<td>
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<label>
					<input type="checkbox" name="guam_restaurants_mall_verified" value="1" <?php checked( $verified ); ?> />
					<?php esc_html_e( 'This mall is confirmed and may be shown to visitors.', 'guam-restaurants-core' ); ?>
				</label>
				<?php if ( $verified && $user && $on ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: user display name, 2: date. */
							esc_html__( 'Verified by %1$s on %2$s.', 'guam-restaurants-core' ),
							esc_html( $user->display_name ),
							esc_html( $on )
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the Verified checkbox.
	 *
	 * @param int $term_id Mall term ID.
	 * @return void
	 */
	public static function save_field( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		self::set_verified( $term_id, ! empty( $_POST['guam_restaurants_mall_verified'] ), get_current_user_id() );
	}

	/**
	 * Marks a mall verified or unverified, recording who and when.
	 *
	 * @param int  $term_id  Mall term ID.
	 * @param bool $verified New state.
	 * @param int  $user_id  User making the change.
	 * @return void
	 */
	public static function set_verified( int $term_id, bool $verified, int $user_id ): void {
		if ( self::is_verified( $term_id ) === $verified ) {
			return;
		}

		update_term_meta( $term_id, Term_Meta::VERIFIED, $verified );
		update_term_meta( $term_id, Term_Meta::VERIFIED_BY, $verified ? $user_id : 0 );
		update_term_meta( $term_id, Term_Meta::VERIFIED_ON, $verified ? current_time( 'Y-m-d' ) : '' );
	}
}
