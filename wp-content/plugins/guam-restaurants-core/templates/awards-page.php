<?php
/**
 * Page template for the awards archive, category and most-awarded pages.
 *
 * Uses the active block theme's header and footer template parts; the
 * content comes from Awards\Pages.
 *
 * @package GuamRestaurants\Core
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'gr-awards-page' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php
	if ( function_exists( 'block_template_part' ) ) {
		block_template_part( 'header' );
	}
	?>
	<main class="wp-block-group is-layout-constrained gr-awards-main">
		<?php echo \GuamRestaurants\Core\Awards\Pages::render_current(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built. ?>
	</main>
	<?php
	if ( function_exists( 'block_template_part' ) ) {
		block_template_part( 'footer' );
	}
	?>
</div>
<?php wp_footer(); ?>
</body>
</html>
