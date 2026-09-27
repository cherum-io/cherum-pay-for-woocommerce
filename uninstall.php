<?php
/**
 * Clean removal. Settings and replay-guard transients do not outlive the
 * plugin: leftovers in wp_options are how the next audit finds a key the
 * owner believed deleted.
 *
 * @package Cherum_Pay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_cherum_pay_settings' );
wp_clear_scheduled_hook( 'cherum_pay_poll_pending' );

global $wpdb;
// Replay-guard transients (cherum_pay_seen_*) and their timeouts.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	  WHERE option_name LIKE '_transient_cherum_pay_seen_%'
	     OR option_name LIKE '_transient_timeout_cherum_pay_seen_%'"
);
