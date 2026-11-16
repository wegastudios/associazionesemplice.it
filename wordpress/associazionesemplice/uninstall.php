<?php
/**
 * Eliminazione del plugin da WordPress.
 *
 * Di default i dati restano (soci, prima nota, allegati, impostazioni). Si cancellano solo se l'amministratore ha acceso
 * l'opzione «Cancella tutti i dati se il plugin viene eliminato» nelle impostazioni.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Cancella i dati del plugin del sito corrente. */
function asem_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'asem_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	$private_site = (string) get_option( 'asem_private_site', '' ); // identificativo di questo sito nel segnaposto della cartella indicata in wp-config.php (le opzioni si cancellano più sotto)

	foreach ( array( 'asem_send_reminders', 'asem_release_holds', 'asem_event_requests', 'asem_broadcast_batch', 'asem_check_pending_payments', 'asem_purge_backups', 'asem_private_folder_check', 'asem_file_crypt_migrate', 'asem_file_crypt_migrate_certs', 'asem_file_crypt_certs_check', 'asem_receipt_pdfs_batch', 'asem_purge_audit', 'asem_purge_member_sessions' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// Tabelle del plugin.
	$like = $wpdb->esc_like( $wpdb->prefix . 'asem_' ) . '%';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabelle del plugin, nessuna API di WordPress equivalente
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabelle del plugin, nessuna API di WordPress equivalente
	}

	// Opzioni e dati temporanei.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'asem_' ) . '%', $wpdb->esc_like( '_transient_asem_' ) . '%', $wpdb->esc_like( '_transient_timeout_asem_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabelle del plugin, nessuna API di WordPress equivalente
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'asem_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- tabelle del plugin, nessuna API di WordPress equivalente

	// Ruoli del plugin: gli utenti che li avevano restano su WordPress.
	remove_role( 'asem_member' );
	remove_role( 'asem_secretary' );
	remove_role( 'asem_employee' );
	foreach ( array( 'administrator' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
				if ( 0 === strpos( $cap, 'asem_' ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	// Cartella privata (allegati e copie di sicurezza).
	$upload = wp_upload_dir( null, false );
	$dir    = rtrim( (string) $upload['basedir'], '/\\' ) . '/associazionesemplice-private';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		if ( is_dir( $dir ) ) {
			$wp_filesystem->delete( $dir, true );
		}
		// Cartella indicata in wp-config.php (ASEM_PRIVATE_DIR): si tolgono solo le cose del plugin, mai l'intera cartella, che potrebbe servire ad altro.
		if ( defined( 'ASEM_PRIVATE_DIR' ) && is_string( ASEM_PRIVATE_DIR ) && '' !== trim( ASEM_PRIVATE_DIR ) ) {
			$custom = rtrim( trim( ASEM_PRIVATE_DIR ), '/\\' );
			if ( is_multisite() ) {
				$custom .= '/sito-' . get_current_blog_id();
			}
			// Solo se la cartella porta il segnaposto di questo sito: una cartella senza segnaposto, o di un altro sito, non si tocca.
			$placeholder = $custom . '/.asem-segnaposto';
			$mine        = '' !== $private_site && is_file( $placeholder ) && 1 === preg_match( '/^asem-sito:' . preg_quote( $private_site, '/' ) . '\s*$/m', (string) file_get_contents( $placeholder ) );
			if ( $mine && is_dir( $custom ) && '' !== $custom ) {
				foreach ( (array) glob( $custom . '/*' ) as $item ) {
					$name = basename( $item );
					if ( is_dir( $item ) && in_array( $name, array( 'backups', 'receipts', 'controllo' ), true ) ) {
						$wp_filesystem->delete( $item, true );
					} elseif ( is_file( $item ) && ( 'index.php' === $name || preg_match( '/^[0-9a-f]{32}(\.part-[0-9a-f]+)?$/', $name ) ) ) {
						wp_delete_file( $item );
					}
				}
				wp_delete_file( $custom . '/.htaccess' );
				wp_delete_file( $placeholder );
				if ( is_multisite() && ! glob( $custom . '/*' ) ) { // la sottocartella del sito, se è rimasta vuota
					$wp_filesystem->rmdir( $custom );
				}
			}
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $asem_blog_id ) {
		switch_to_blog( $asem_blog_id );
		asem_uninstall_site();
		restore_current_blog();
	}
} else {
	asem_uninstall_site();
}
