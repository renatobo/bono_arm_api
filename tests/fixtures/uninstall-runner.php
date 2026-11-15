<?php
$scenario = $argv[1];
$deleted = $removed = $sites = array();
define( 'WP_UNINSTALL_PLUGIN', 'bono-arm-api/bono-arm-api.php' );
if ( 'constant' === $scenario ) { define( 'BONO_ARM_API_VERSION', 'test' ); }
function get_option( $key, $default = false ) { global $scenario; return 'active_plugins' === $key && 'sibling' === $scenario ? array( 'other/bono-arm-api.php' ) : $default; }
function get_site_option( $key, $default = false ) { global $scenario; return 'network_sibling' === $scenario ? array( 'other/bono-arm-api.php' => 1 ) : $default; }
function is_multisite() { global $scenario; return in_array( $scenario, array( 'multisite', 'network_sibling' ), true ); }
function delete_option( $name ) { global $deleted; $deleted[] = $name; }
function delete_transient( $name ) {}
function get_current_blog_id() { return 1; }
function wp_roles() { return (object) array( 'role_objects' => array( new class {
	public function has_cap( $cap ) { return true; }
	public function remove_cap( $cap ) { global $removed; $removed[] = $cap; }
} ) ); }
function get_sites( $args ) { return 0 === $args['offset'] ? array( 1, 2 ) : array(); }
function switch_to_blog( $id ) { global $sites; $sites[] = $id; }
function restore_current_blog() {}
require dirname( __DIR__, 2 ) . '/uninstall.php';
echo json_encode( compact( 'deleted', 'removed', 'sites' ) );
