<?php
/**
 * Plugin Name: 360 Platform Core
 * Plugin URI: https://github.com/KazimirAlvis/360-platform-core
 * Description: Stable content, data, geography, relationship, and site-context services for the Global 360 platform.
 * Version: 1.1.1
 * Author: PR360
 * Update URI: https://github.com/KazimirAlvis/360-platform-core
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: 360-platform-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GLOBAL360_PLATFORM_VERSION', '1.1.1' );
define( 'GLOBAL360_PLATFORM_FILE', __FILE__ );
define( 'GLOBAL360_PLATFORM_PATH', plugin_dir_path( __FILE__ ) );
define( 'GLOBAL360_PLATFORM_CORE_OWNS_CONTENT_TYPES', true );

$global360_platform_files = array(
	'src/Geography/StateRegistry.php',
	'src/Support/LegacyMetaAdapter.php',
	'src/Relationships/RelationshipService.php',
	'src/Data/LocationRepository.php',
	'src/Data/ClinicRepository.php',
	'src/Data/DoctorRepository.php',
	'src/Context/SiteContext.php',
	'src/Ownership/FieldOwnership.php',
	'src/Content/PostTypes.php',
	'src/Content/MetaRegistry.php',
	'src/Reviews/PatientReviews.php',
	'src/Reviews/PublicReviewRepository.php',
	'src/Reviews/ContactFormIntegration.php',
	'src/Reviews/ReviewForm.php',
	'src/Reviews/Admin.php',
	'src/Updater.php',
	'src/Plugin.php',
);

foreach ( $global360_platform_files as $global360_platform_file ) {
	require_once GLOBAL360_PLATFORM_PATH . $global360_platform_file;
}
unset( $global360_platform_file, $global360_platform_files );

if ( ! function_exists( 'global360_platform' ) ) {
	/** @return \Global360\Platform\Plugin */
	function global360_platform() {
		return \Global360\Platform\Plugin::instance();
	}
}

global360_platform()->boot();
\Global360\Platform\Updater::init();

register_activation_hook( __FILE__, array( '\Global360\Platform\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Global360\Platform\Plugin', 'deactivate' ) );
