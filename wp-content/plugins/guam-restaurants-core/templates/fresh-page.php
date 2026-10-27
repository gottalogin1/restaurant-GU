<?php
/**
 * Page template for New on Guam and This week on Guam.
 *
 * Uses the active block theme's header and footer template parts; the
 * content comes from Site\Fresh_Pages.
 *
 * @package GuamRestaurants\Core
 */

defined( 'ABSPATH' ) || exit;

// Render the parts and the body before wp_head(), so the styles their blocks
// enqueue are printed in <head> rather than the footer (no layout shift).
$guam_parts = array();
foreach ( array( 'header', 'footer' ) as $guam_part ) {
	ob_start();
	if ( function_exists( 'block_template_part' ) ) {
		block_template_part( $guam_part );
	}
	$guam_parts[ $guam_part ] = (string) ob_get_clean();
}
$guam_body = \GuamRestaurants\Core\Site\Fresh_Pages::render_current();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'gr-fresh-page-body' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<header class="wp-block-template-part"><?php echo $guam_parts['header']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup. ?></header>
	<main class="wp-block-group has-global-padding is-layout-constrained gr-dish-main" id="wp--skip-link--target">
		<?php echo $guam_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built. ?>
	</main>
	<footer class="wp-block-template-part"><?php echo $guam_parts['footer']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block markup. ?></footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
