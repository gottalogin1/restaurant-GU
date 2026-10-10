<?php
/**
 * Plugin Name:       Guam Restaurants Core
 * Description:       Business logic for the Guam restaurant directory: restaurant listings, village, mall and cuisine tagging, imports and ad serving.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Guam Restaurants
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guam-restaurants-core
 * Domain Path:       /languages
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

define( 'GUAM_RESTAURANTS_CORE_VERSION', '0.1.0' );
define( 'GUAM_RESTAURANTS_CORE_FILE', __FILE__ );
define( 'GUAM_RESTAURANTS_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once GUAM_RESTAURANTS_CORE_DIR . 'includes/class-autoloader.php';
Autoloader::register( __NAMESPACE__, GUAM_RESTAURANTS_CORE_DIR . 'includes/' );
require_once GUAM_RESTAURANTS_CORE_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'instance' ) );
