<?php
/*
Uninstall logic when WP-Mollom is deleted  through the admin panel(>= WP 2.7)
*/


// do not run unless within the WP plugin flow
if( (!defined("ABSPATH")) && (!defined("WP_UNINSTALL_PLUGIN")) ) {
	define( 'MOLLOM_I8N', 'wp-mollom' );
}

// define/init variables we'll need
global $wpdb, $wp_db_version;

// < WP 2.7 don't have their own uninstallation file
if ( 8645 > $wp_db_version ) {
	return;
}

define( 'MOLLOM_TABLE', 'mollom' );

// delete all mollom related options
delete_option('mollom_private_key');

$mollom_table = $wpdb->prefix . MOLLOM_TABLE;
// delete MOLLOM_TABLE

?>