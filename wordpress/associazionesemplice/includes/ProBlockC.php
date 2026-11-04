<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Certificati medici e affiliazioni agli enti (funzioni di Associazione Semplice Pro) nei punti di estensione di Associazione Semplice:
 * impostazioni, privacy, lavori periodici, scheda della persona, modulo delle attività, Bacheca e area soci. Si avvia da {@see ProBoot::init()}.
 */
final class ProBlockC {

	public static function register(): void {
		add_filter( 'asem_settings_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'asem_settings_clean', array( __CLASS__, 'clean' ) );
		add_filter( 'asem_privacy_export', array( __CLASS__, 'privacy_export' ), 20, 2 );
		add_action( 'asem_person_anonymized', array( __CLASS__, 'anonymize' ), 20, 3 );
		add_filter( 'asem_scheduled_jobs', array( __CLASS__, 'jobs' ) );
		add_filter( 'asem_admin_post_classes', array( __CLASS__, 'admin_post_classes' ) );
		add_action( 'asem_activity_deleted', array( __CLASS__, 'activity_deleted' ) );
		add_filter( 'asem_activity_saved', array( __CLASS__, 'activity_saved' ), 20, 3 );
		add_filter( 'asem_activity_form_rows', array( __CLASS__, 'activity_form_rows' ), 20, 2 );
		add_action( 'asem_person_receipts', array( __CLASS__, 'person_panels' ) );
		add_action( 'asem_dashboard_alerts', array( __CLASS__, 'alerts' ) );
		add_filter( 'asem_my_sections', array( __CLASS__, 'my_sections' ), 20 );
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 20, 3 );
	}

	// ---------- Impostazioni ----------

	public static function defaults( array $d ): array {
		return $d + array(
			'cert_reminders'   => 0,  // promemoria dei certificati medici al socio e alla segreteria: spenti di default
			'cert_warn_days'   => 30, // giorni di preavviso del certificato medico
			'cert_show_member' => 0,  // il socio vede la scadenza del proprio certificato nell'area soci
			'aff_reminders'    => 0,  // promemoria di scadenza di affiliazioni e tessere degli enti
			'aff_warn_days'    => 30, // giorni di preavviso di affiliazioni e tessere
		);
	}

	public static function clean( array $clean ): array {
		foreach ( array( 'cert_reminders', 'cert_show_member', 'aff_reminders' ) as $k ) {
			$clean[ $k ] = empty( $clean[ $k ] ) ? 0 : 1;
		}
		foreach ( array( 'cert_warn_days', 'aff_warn_days' ) as $k ) {
			$clean[ $k ] = ExpiryRules::warn_days( $clean[ $k ] ?? ExpiryRules::DEFAULT_WARN );
		}
		return $clean;
	}

	// ---------- Privacy ----------

	public static function privacy_export( array $data, int $person_id ): array {
		if ( Edition::installed( 'medical' ) ) {
			$data = Certificates::privacy_export( $data, $person_id );
		}
		if ( Edition::installed( 'affiliations' ) ) {
			$data = Affiliations::privacy_export( $data, $person_id );
		}
		return $data;
	}

	public static function anonymize( int $person_id ): void {
		if ( Edition::installed( 'medical' ) ) {
			Certificates::anonymize( $person_id );
		}
		if ( Edition::installed( 'affiliations' ) ) {
			Affiliations::anonymize( $person_id );
		}
	}

	// ---------- Lavori e azioni ----------

	/** Promemoria di scadenza, una volta al giorno. */
	public static function jobs( array $jobs ): array {
		return $jobs + array(
			'asem_cert_reminders' => array( 'daily', 900, 'Certificates', 'cron', 'medical' ),
			'asem_aff_reminders'  => array( 'daily', 1200, 'Affiliations', 'cron', 'affiliations' ),
		);
	}

	/** Download di admin-post gestiti da classi caricate al bisogno. */
	public static function admin_post_classes( array $map ): array {
		return $map + array(
			'asem_cert_file' => 'Certificates',
			'asem_cert_csv'  => 'Admin\\BlockCExports',
			'asem_aff_csv'   => 'Admin\\BlockCExports',
		);
	}

	// ---------- Attività ----------

	/** Eliminata un'attività: non richiede più il certificato. */
	public static function activity_deleted( int $activity_id ): void {
		if ( Edition::installed( 'medical' ) ) {
			Certificates::set_required( $activity_id, false );
		}
	}

	/** Salvata un'attività: la casella «richiede il certificato medico». */
	public static function activity_saved( array $notes, int $activity_id, array $p ): array {
		if ( Edition::has( 'medical' ) && ! empty( $p['cert_required_present'] ) ) {
			Certificates::set_required( $activity_id, ! empty( $p['cert_required'] ) );
		}
		return $notes;
	}

	public static function activity_form_rows( array $rows, ?int $activity_id ): array {
		if ( Edition::has( 'medical' ) ) {
			$rows[] = Admin\CertificatesPage::activity_row( $activity_id );
		}
		return $rows;
	}

	// ---------- Schede e avvisi ----------

	public static function person_panels( array $p ): void {
		Admin\CertificatesPage::person_panel( $p );
		Admin\AffiliationsPage::person_panel( $p );
	}

	public static function alerts(): void {
		Admin\CertificatesPage::alert();
		Admin\AffiliationsPage::alert();
	}

	// ---------- Area soci ----------

	/** Il certificato medico compare dopo le attività del socio. */
	public static function my_sections( array $sections ): array {
		if ( ! Edition::has( 'medical' ) ) {
			return $sections;
		}
		$out = array();
		foreach ( $sections as $s ) {
			$out[] = $s;
			if ( 'attivita' === $s ) {
				$out[] = 'certificato';
			}
		}
		return in_array( 'certificato', $out, true ) ? $out : array_merge( $out, array( 'certificato' ) );
	}

	public static function area_section( $html, string $key, array $p ) {
		return 'certificato' === $key && Edition::has( 'medical' ) ? Frontend\CertificatesFront::section( $p ) : $html;
	}
}
