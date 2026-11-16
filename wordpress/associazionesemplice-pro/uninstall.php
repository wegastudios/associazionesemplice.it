<?php
/**
 * Disinstallazione di Associazione Semplice Pro: toglie i lavori periodici e i dati della licenza e degli aggiornamenti.
 * I dati dell'associazione (soci, prima nota…) appartengono ad Associazione Semplice e non si toccano qui.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'asem_license_check', 'asem_update_check', 'asem_license_resync', 'asem_watch_month_end', 'asem_sms_hourly', 'asem_sms_queue', 'asem_newsletter_sync' ) as $hook ) {
	wp_unschedule_hook( $hook );
}
foreach ( array( 'asem_license_state', 'asem_license_first_seen', 'asem_update_latest', 'asem_portal_updates' ) as $option ) {
	delete_option( $option );
}
