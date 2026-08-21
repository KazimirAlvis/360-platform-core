<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'GLOBAL360_PLATFORM_VERSION', '1.0.0' );
define( 'GLOBAL360_PLATFORM_FILE', dirname( __DIR__ ) . '/360-platform-core.php' );

$manifest_version = '1.0.0';
function add_filter() {}
function plugin_basename( $file ) { return '360-platform-core/' . basename( $file ); }
function sanitize_text_field( $value ) { return (string) $value; }
function sanitize_key( $value ) { return (string) $value; }
function esc_url_raw( $value ) { return (string) $value; }
function wp_kses_post( $value ) { return (string) $value; }
function is_wp_error() { return false; }
function wp_remote_retrieve_response_code() { return 200; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_remote_get() {
	global $manifest_version;
	return array( 'body' => json_encode( array(
		'name' => '360 Platform Core', 'version' => $manifest_version,
		'download_url' => 'https://github.com/KazimirAlvis/360-platform-core/archive/refs/heads/main.zip',
		'homepage' => 'https://github.com/KazimirAlvis/360-platform-core',
	) ) );
}
function updater_expect( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } echo "PASS: $message\n"; }

require dirname( __DIR__ ) . '/src/Updater.php';

$same = (object) array( 'checked' => array( '360-platform-core/360-platform-core.php' => '1.0.0' ), 'response' => array() );
$same = \Global360\Platform\Updater::inject_update( $same );
updater_expect( empty( $same->response ), 'matching manifest version does not offer an update' );

$manifest_version = '1.0.1';
$newer = (object) array( 'checked' => array( '360-platform-core/360-platform-core.php' => '1.0.0' ), 'response' => array() );
$newer = \Global360\Platform\Updater::inject_update( $newer );
updater_expect( isset( $newer->response['360-platform-core/360-platform-core.php'] ), 'newer manifest version offers an update' );
updater_expect( '1.0.1' === $newer->response['360-platform-core/360-platform-core.php']->new_version, 'update response carries the newer version' );

$temp = sys_get_temp_dir() . '/platform-updater-' . uniqid();
mkdir( $temp );
$github_dir = $temp . '/360-platform-core-main';
mkdir( $github_dir );
$renamed = \Global360\Platform\Updater::rename_github_package( $github_dir, $temp, null, array( 'type'=>'plugin', 'plugin'=>'360-platform-core/360-platform-core.php' ) );
updater_expect( $temp . '/360-platform-core' === $renamed && is_dir( $renamed ), 'GitHub package directory normalizes to 360-platform-core' );
rmdir( $renamed );
rmdir( $temp );

echo "Platform Core updater tests passed.\n";
