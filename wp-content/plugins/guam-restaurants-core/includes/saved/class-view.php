<?php
/**
 * Hearts, the Saved page and the sign-in prompts.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Saved;

use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Directory_View;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\Pages;
use GuamRestaurants\Core\Site\Restaurant_View;
use GuamRestaurants\Core\Site\Rest_Controller as Site_Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Renders everything visitors see of saved lists.
 *
 * - A heart on every restaurant card and a Save button on restaurant
 *   pages. They are hidden until assets/site/saved.js wires them up, so
 *   pages stay cacheable and the buttons never show without the script.
 * - The Saved page: the visitor's list as the directory's numbered list
 *   with the map beside it, a share link, and either the account panel
 *   (signed in) or the sign-in panel (not signed in). A shared link
 *   (`?list=`) shows those restaurants instead, with a button to save them.
 * - Sign-in prompts. Saving never needs an account, but every place that
 *   saves invites the visitor to sign in so the list follows them to other
 *   devices: a prompt after the first and fifth save (once each per
 *   browser), the panel on the Saved page, and Sign in in the header. Each
 *   offers Google and Facebook (LoginPress Pro's Social Login add-on, when
 *   it is set up), email and password sign-up, and sign-in.
 */
final class View {

	/**
	 * URL parameter of a shared list.
	 */
	public const LIST_PARAM = 'list';

	/**
	 * Result modes: the visitor's own list, or a list someone shared.
	 */
	public const MINE   = 'mine';
	public const SHARED = 'shared';

	/**
	 * Saves after which the prompt to sign in appears (once each).
	 */
	public const PROMPT_AT = array( 1, 5 );

	/**
	 * Element ID of the sign-in panel, for links to it.
	 */
	public const JOIN_ID = 'gr-join';

	/**
	 * Whether the prompt dialog is needed on this page.
	 *
	 * @var bool
	 */
	private static bool $prompt = false;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_script' ) );
		add_action( 'wp_footer', array( self::class, 'print_prompt' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/**
	 * Registers saved.js and its settings. The REST nonce is printed only
	 * for signed-in visitors, whose pages are not page-cached.
	 *
	 * @return void
	 */
	public static function register_script(): void {
		$handle = Assets::SCRIPTS['saved'];
		wp_register_script( $handle, plugins_url( 'assets/site/saved.js', GUAM_RESTAURANTS_CORE_FILE ), array( 'wp-i18n' ), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( $handle, 'guam-restaurants-core' );
	}

	/**
	 * Enqueues saved.js with its settings, once per page.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		Assets::enqueue( 'saved' );
		if ( wp_script_is( Assets::SCRIPTS['saved'], 'done' ) || did_action( 'guam_restaurants_saved_settings' ) ) {
			return;
		}
		do_action( 'guam_restaurants_saved_settings' );
		$signed_in = is_user_logged_in();
		wp_add_inline_script(
			Assets::SCRIPTS['saved'],
			'window.guamRestaurantsSaved = ' . wp_json_encode(
				array(
					'signedIn'  => $signed_in,
					'nonce'     => $signed_in ? wp_create_nonce( 'wp_rest' ) : '',
					'endpoint'  => rest_url( Site_Rest::NAMESPACE . '/saved' ),
					'cards'     => rest_url( Site_Rest::NAMESPACE . '/saved/cards' ),
					'page'      => Pages::url( 'saved' ),
					'listParam' => self::LIST_PARAM,
					'shareMax'  => Saved_List::SHARE_MAX,
					'max'       => Saved_List::MAX,
					'promptAt'  => self::PROMPT_AT,
				)
			) . ';',
			'before'
		);
		if ( ! $signed_in ) {
			self::$prompt = true;
		}
	}

	/**
	 * Heart icon, filled by CSS when pressed.
	 *
	 * @return string
	 */
	private static function icon(): string {
		return '<svg class="gr-save__icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M12 20.3l-1.2-1.1C6.1 15 3 12.2 3 8.8 3 6 5.2 3.8 8 3.8c1.6 0 3 .7 4 1.9 1-1.2 2.4-1.9 4-1.9 2.8 0 5 2.2 5 5 0 3.4-3.1 6.2-7.8 10.4L12 20.3z"/></svg>';
	}

	/**
	 * Heart button for a restaurant card.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function heart( int $restaurant_id ): string {
		self::enqueue();
		$name = wp_strip_all_tags( get_the_title( $restaurant_id ) );
		return sprintf(
			'<button type="button" class="gr-save" data-gr-save="%1$d" aria-pressed="false" data-gr-label-off="%2$s" data-gr-label-on="%3$s" aria-label="%2$s" hidden>%4$s</button>',
			$restaurant_id,
			/* translators: %s: restaurant name. */
			esc_attr( sprintf( __( 'Save %s', 'guam-restaurants-core' ), $name ) ),
			/* translators: %s: restaurant name. */
			esc_attr( sprintf( __( 'Saved: %s. Remove from your list', 'guam-restaurants-core' ), $name ) ),
			self::icon()
		);
	}

	/**
	 * Save button for a restaurant page's action bar.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function action_button( int $restaurant_id ): string {
		self::enqueue();
		return sprintf(
			'<button type="button" class="gr-action gr-save gr-save--action" data-gr-save="%1$d" aria-pressed="false" data-gr-text-off="%2$s" data-gr-text-on="%3$s" hidden>%4$s<span class="gr-save__text">%2$s</span></button>',
			$restaurant_id,
			esc_attr__( 'Save', 'guam-restaurants-core' ),
			esc_attr__( 'Saved', 'guam-restaurants-core' ),
			self::icon()
		);
	}

	/**
	 * The Saved page's numbered list (newest first), as on directory pages,
	 * so the map beside it shows matching numbered pins.
	 *
	 * @param int[]  $ids  Published restaurant IDs, oldest first.
	 * @param string $mode MINE or SHARED.
	 * @return string
	 */
	public static function results( array $ids, string $mode ): string {
		$ids = array_reverse( $ids );
		if ( ! $ids ) {
			if ( self::SHARED === $mode ) {
				return '<p class="gr-directory__summary" role="status">' . esc_html__( 'None of the restaurants in this link are listed any more.', 'guam-restaurants-core' ) . '</p>';
			}
			$browse = Directory_View::page_url( Filters::defaults(), array() );
			return '<p class="gr-directory__summary" role="status">' . esc_html__( 'Nothing saved yet.', 'guam-restaurants-core' ) . '</p>'
				. '<p class="gr-saved__empty">' . sprintf(
					/* translators: %s: "Browse restaurants" link. */
					esc_html__( 'Tap the heart on any restaurant to keep it here. %s', 'guam-restaurants-core' ),
					sprintf( '<a href="%1$s">%2$s</a>', esc_url( $browse ), esc_html__( 'Browse restaurants', 'guam-restaurants-core' ) )
				) . '</p>';
		}
		$items = '';
		$pin   = 0;
		Restaurant_View::prime( $ids );
		foreach ( $ids as $id ) {
			$point  = Restaurant_View::coordinates( $id );
			$items .= null === $point
				? '<li>' . Restaurant_View::card( $id, 2 ) . '</li>'
				: sprintf( '<li data-gr-pin="%1$d" data-gr-point="%2$s,%3$s">%4$s</li>', ++$pin, esc_attr( (string) $point['lng'] ), esc_attr( (string) $point['lat'] ), Restaurant_View::card( $id, 2 ) );
		}
		$count = count( $ids );
		return '<p class="gr-directory__summary" role="status">' . esc_html(
			self::SHARED === $mode
				/* translators: %s: number of restaurants. */
				? sprintf( _n( '%s restaurant in this list', '%s restaurants in this list', $count, 'guam-restaurants-core' ), number_format_i18n( $count ) )
				/* translators: %s: number of restaurants. */
				: sprintf( _n( '%s saved restaurant', '%s saved restaurants', $count, 'guam-restaurants-core' ), number_format_i18n( $count ) )
		) . '</p><ul class="gr-cards" role="list">' . $items . '</ul>';
	}

	/**
	 * The Saved page block.
	 *
	 * @return string
	 */
	public static function render_page(): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );
		self::enqueue();

		// Read-only: which shared list to show.
		$shared  = isset( $_GET[ self::LIST_PARAM ] ) ? Saved_List::parse_ids( sanitize_text_field( wp_unslash( $_GET[ self::LIST_PARAM ] ) ), Saved_List::SHARE_MAX ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = get_current_user_id();
		$here    = (string) get_permalink();

		if ( null !== $shared ) {
			$ids  = Saved_List::published( $shared );
			$head = '<div class="gr-saved__head"><p class="gr-saved__intro">' . esc_html__( 'Someone shared this list of restaurants with you. The link holds only the restaurants, nothing about the person who shared it.', 'guam-restaurants-core' ) . '</p>'
				. ( $ids ? sprintf(
					'<div class="gr-saved__tools"><button type="button" class="gr-button" data-gr-save-all="%1$s" data-gr-done="%2$s" hidden>%3$s</button><a class="gr-saved__mine" href="%4$s">%5$s</a></div>',
					esc_attr( implode( ',', $ids ) ),
					esc_attr__( 'Added to your list', 'guam-restaurants-core' ),
					esc_html__( 'Save all to my list', 'guam-restaurants-core' ),
					esc_url( $here ),
					esc_html__( 'Go to my list', 'guam-restaurants-core' )
				) : '' )
				. '</div>';
			$body = self::layout( self::results( $ids, self::SHARED ), '' );
			return self::wrap( $head . $body . ( $user_id ? '' : self::join_panel( $here, 'page' ) ) );
		}

		if ( $user_id ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page cache convention.
			}
			$ids = Saved_List::published( Saved_List::get( $user_id ) );
			return self::wrap( self::tools() . self::layout( self::results( $ids, self::MINE ), 'data-gr-saved-mine' ) . self::account_panel( $user_id ) );
		}

		// Not signed in: the list is in the browser, so saved.js fills it in.
		$loading = '<p class="gr-directory__summary" role="status">' . esc_html__( 'Loading your saved restaurants…', 'guam-restaurants-core' ) . '</p>'
			. '<noscript><p>' . esc_html__( 'Saved restaurants are kept in your browser, which needs JavaScript. Sign in to keep a list on your account.', 'guam-restaurants-core' ) . '</p></noscript>';
		return self::wrap( self::join_panel( $here, 'page' ) . self::tools() . self::layout( $loading, 'data-gr-saved-mine data-gr-saved-local' ) );
	}

	/**
	 * Wraps the Saved page in the block wrapper.
	 *
	 * @param string $inner Inner HTML.
	 * @return string
	 */
	private static function wrap( string $inner ): string {
		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes( array( 'class' => 'gr-saved' ) ), $inner );
	}

	/**
	 * Share button above the visitor's own list.
	 *
	 * @return string
	 */
	private static function tools(): string {
		return sprintf(
			'<div class="gr-saved__tools"><button type="button" class="gr-button gr-button--quiet" data-gr-saved-share data-gr-share-title="%1$s" data-gr-copied="%2$s" hidden>%3$s</button><p class="gr-saved__share-note" data-gr-saved-share-note hidden>%4$s</p></div>',
			esc_attr__( 'My saved restaurants', 'guam-restaurants-core' ),
			esc_attr__( 'Link copied', 'guam-restaurants-core' ),
			esc_html__( 'Share my list', 'guam-restaurants-core' ),
			esc_html(
				sprintf(
					/* translators: %s: most restaurants in a shared link. */
					__( 'The link shows your newest %s saved restaurants and nothing else about you.', 'guam-restaurants-core' ),
					number_format_i18n( Saved_List::SHARE_MAX )
				)
			)
		);
	}

	/**
	 * The directory's list-and-map layout around the results.
	 *
	 * @param string $results    Results HTML.
	 * @param string $attributes Extra attributes for the results element (trusted).
	 * @return string
	 */
	private static function layout( string $results, string $attributes ): string {
		return sprintf(
			'<div class="gr-directory gr-saved__list"><div class="gr-directory__views" role="group" aria-label="%1$s" hidden><button type="button" class="gr-directory__view" data-gr-view="list" aria-pressed="true">%2$s</button><button type="button" class="gr-directory__view" data-gr-view="map" aria-pressed="false">%3$s</button></div><div class="gr-directory__body"><div class="gr-directory__results" id="%4$s" tabindex="-1" %5$s>%6$s</div><div class="gr-directory__map" data-gr-results-map role="region" aria-label="%7$s"></div></div></div>',
			esc_attr__( 'Show the list as', 'guam-restaurants-core' ),
			esc_html__( 'List', 'guam-restaurants-core' ),
			esc_html__( 'Map', 'guam-restaurants-core' ),
			esc_attr( Directory_View::RESULTS_ID ),
			$attributes,
			$results,
			esc_attr__( 'Map of these restaurants', 'guam-restaurants-core' )
		);
	}

	/**
	 * Google, Facebook and other buttons from LoginPress Pro's Social Login
	 * add-on, or '' when it is not active or no provider is set up.
	 *
	 * @param string $redirect Where to return after signing in.
	 * @return string
	 */
	public static function social_buttons( string $redirect ): string {
		global $shortcode_tags;
		$html = '';
		if ( ! is_user_logged_in() && isset( $shortcode_tags['loginpress_social_login'] ) && is_callable( $shortcode_tags['loginpress_social_login'] ) ) {
			// Called directly, not through do_shortcode(), so the URL needs no shortcode escaping.
			$html = (string) call_user_func(
				$shortcode_tags['loginpress_social_login'],
				array(
					'display'     => 'column',
					'redirect_to' => $redirect,
				),
				'',
				'loginpress_social_login'
			);
			if ( false === strpos( $html, '<a ' ) ) {
				$html = '';
			}
		}
		/**
		 * Filters the social sign-in buttons on the Saved page and sign-in prompt.
		 *
		 * @param string $html     Buttons, or '' for none.
		 * @param string $redirect Where to return after signing in.
		 */
		return (string) apply_filters( 'guam_restaurants_social_login_html', $html, $redirect );
	}

	/**
	 * Why to sign in and every way to do it.
	 *
	 * @param string $redirect Where to return after signing in.
	 * @param string $context  'page' (Saved page) or 'dialog' (prompt).
	 * @return string
	 */
	public static function join_panel( string $redirect, string $context ): string {
		$dialog     = 'dialog' === $context;
		$heading    = $dialog ? __( 'Saved. Keep your list on every device', 'guam-restaurants-core' ) : __( 'Keep your list on every device', 'guam-restaurants-core' );
		$social     = self::social_buttons( $redirect );
		$register   = get_option( 'users_can_register' ) ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), wp_registration_url() ) : '';
		$options    = '' !== $register
			? sprintf( '<a class="gr-button" href="%1$s">%2$s</a>', esc_url( $register ), esc_html__( 'Sign up with email', 'guam-restaurants-core' ) )
			: '';
		$options   .= sprintf(
			'<p class="gr-join__login">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Already have an account?', 'guam-restaurants-core' ),
			esc_url( wp_login_url( $redirect ) ),
			esc_html__( 'Sign in', 'guam-restaurants-core' )
		);
		$heading_id = $dialog ? 'gr-join-dialog-title' : 'gr-join-title';
		return sprintf(
			'<section class="gr-join gr-join--%1$s" %2$s aria-labelledby="%3$s"><h2 class="gr-join__title" id="%3$s">%4$s</h2><p class="gr-join__why">%5$s</p><div class="gr-join__options">%6$s%7$s%8$s</div><p class="gr-join__fine">%9$s</p></section>',
			esc_attr( $context ),
			$dialog ? '' : 'id="' . esc_attr( self::JOIN_ID ) . '"',
			esc_attr( $heading_id ),
			esc_html( $heading ),
			esc_html__( 'Right now your list lives only in this browser. Sign in free to see it on your phone and computer, keep it if you clear your browser, and share it with friends.', 'guam-restaurants-core' ),
			'' !== $social ? '<div class="gr-join__social">' . $social . '</div>' : '',
			'' !== $social && '' !== $options ? '<p class="gr-join__or"><span>' . esc_html__( 'or', 'guam-restaurants-core' ) . '</span></p>' : '',
			$options,
			self::fine_print()
		);
	}

	/**
	 * What signing in shares, with the privacy policy link.
	 *
	 * @return string
	 */
	private static function fine_print(): string {
		$privacy = get_privacy_policy_url();
		$text    = esc_html__( 'We use your account only to keep your list. With Google or Facebook we receive just your name, email address and profile picture, and we never post anything for you.', 'guam-restaurants-core' );
		return '' !== $privacy ? $text . ' ' . sprintf( '<a href="%1$s">%2$s</a>', esc_url( $privacy ), esc_html__( 'Privacy policy', 'guam-restaurants-core' ) ) : $text;
	}

	/**
	 * The signed-in person's account: sign out, password, delete.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function account_panel( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}
		$home  = Members::home();
		$links = array(
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( wp_logout_url( $home ) ), esc_html__( 'Sign out', 'guam-restaurants-core' ) ),
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( wp_lostpassword_url( $home ) ), esc_html__( 'Set or change your password', 'guam-restaurants-core' ) ),
		);
		if ( user_can( $user_id, Roles::OWNER ) ) {
			$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=' . Dashboard::PAGE ) ), esc_html__( 'My restaurant', 'guam-restaurants-core' ) );
		}
		$delete = '';
		if ( Members::is_member( $user_id ) ) {
			// Read-only: whether to show the confirmation step.
			$confirm = isset( $_GET['account'] ) && 'confirm-delete' === $_GET['account']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$delete  = sprintf(
				'<form class="gr-account__delete" method="post" action="%1$s"><input type="hidden" name="action" value="%2$s">%3$s%4$s<button type="submit" class="gr-button gr-button--danger">%5$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( Members::DELETE_ACTION ),
				wp_nonce_field( Members::DELETE_ACTION, '_wpnonce', true, false ),
				$confirm
					? '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Yes, delete my account and my saved list for good.', 'guam-restaurants-core' ) . '</label></p>'
					: '',
				$confirm ? esc_html__( 'Delete my account now', 'guam-restaurants-core' ) : esc_html__( 'Delete my account', 'guam-restaurants-core' )
			);
		}
		return sprintf(
			'<section class="gr-account" id="gr-account" aria-labelledby="gr-account-title"><h2 id="gr-account-title">%1$s</h2><p>%2$s</p><ul class="gr-account__links"><li>%3$s</li></ul>%4$s</section>',
			esc_html__( 'Your account', 'guam-restaurants-core' ),
			esc_html(
				sprintf(
					/* translators: 1: display name, 2: email address. */
					__( 'Signed in as %1$s (%2$s). Your saved list is kept on this account.', 'guam-restaurants-core' ),
					$user->display_name,
					$user->user_email
				)
			),
			implode( '</li><li>', $links ),
			$delete
		);
	}

	/**
	 * Header links: Saved (with a count) and Sign in or Account.
	 *
	 * @return string
	 */
	public static function account_links(): string {
		$saved = Pages::url( 'saved' );
		if ( '' === $saved ) {
			return '';
		}
		self::enqueue();
		$signed_in = is_user_logged_in();
		$account   = $signed_in
			? sprintf( '<a class="gr-account-links__account" href="%1$s">%2$s</a>', esc_url( $saved . '#gr-account' ), esc_html__( 'Account', 'guam-restaurants-core' ) )
			: sprintf( '<a class="gr-account-links__signin" href="%1$s" data-gr-join-open>%2$s</a>', esc_url( $saved . '#' . self::JOIN_ID ), esc_html__( 'Sign in', 'guam-restaurants-core' ) );
		return sprintf(
			'<nav %1$s><a class="gr-account-links__saved" href="%2$s">%3$s<span>%4$s</span> <span class="gr-account-links__count" data-gr-saved-count hidden></span></a>%5$s</nav>',
			get_block_wrapper_attributes(
				array(
					'class'      => 'gr-account-links',
					'aria-label' => esc_attr__( 'Saved and account', 'guam-restaurants-core' ),
				)
			),
			esc_url( $saved ),
			self::icon(),
			esc_html__( 'Saved', 'guam-restaurants-core' ),
			$account
		);
	}

	/**
	 * Prints the sign-in prompt for visitors who are not signed in, on pages
	 * with something to save. saved.js opens it after the first and fifth
	 * save, and from the header's Sign in link.
	 *
	 * @return void
	 */
	public static function print_prompt(): void {
		if ( ! self::$prompt || is_user_logged_in() ) {
			return;
		}
		printf(
			'<dialog class="gr-join-dialog" data-gr-join-dialog aria-labelledby="gr-join-dialog-title"><button type="button" class="gr-join-dialog__close" data-gr-join-close aria-label="%1$s">&times;</button>%2$s<p class="gr-join-dialog__later"><button type="button" class="gr-button gr-button--quiet" data-gr-join-close>%3$s</button></p></dialog>',
			esc_attr__( 'Close', 'guam-restaurants-core' ),
			self::join_panel( self::current_url(), 'dialog' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			esc_html__( 'Not now', 'guam-restaurants-core' )
		);
	}

	/**
	 * This page's URL, to come back to after signing in.
	 *
	 * @return string
	 */
	public static function current_url(): string {
		global $wp;
		$path = $wp instanceof \WP ? (string) $wp->request : '';
		$url  = '' === $path ? home_url( '/' ) : home_url( user_trailingslashit( $path ) );
		// Read-only: the page's own filters, to come back to.
		$query = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $query['account'] );
		return $query ? add_query_arg( urlencode_deep( $query ), $url ) : $url;
	}

	/**
	 * Keeps the Saved page out of search results: it is personal or a
	 * copy of other pages.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		$saved  = Pages::url( 'saved' );
		if ( '' !== $saved && is_page() && (string) get_permalink() === $saved ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}
