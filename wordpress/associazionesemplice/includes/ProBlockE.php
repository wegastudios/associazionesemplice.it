<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * SMS ai soci, newsletter e nuovi metodi di pagamento (Satispay, Klarna) nei punti di estensione di Associazione Semplice: impostazioni, privacy,
 * area soci, promemoria e lavori periodici. Si avvia da {@see ProBoot::init()}.
 */
final class ProBlockE {

	const SMS_JOB = Sms::HOOK;

	public static function register(): void {
		add_filter( 'asem_settings_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'asem_settings_clean', array( __CLASS__, 'clean' ) );
		add_filter( 'asem_secret_keys', array( __CLASS__, 'secret_keys' ) );
		add_filter( 'asem_privacy_export', array( __CLASS__, 'privacy_export' ), 40, 2 );
		add_action( 'asem_person_anonymized', array( __CLASS__, 'anonymize' ), 40, 1 );
		add_action( 'asem_consent_changed', array( Newsletter::class, 'on_consent_changed' ), 10, 3 );
		add_action( 'asem_notify_email', array( Sms::class, 'on_reminder' ), 20, 3 );
		add_action( Sms::HOOK, array( Sms::class, 'job' ) );
		add_action( self::SMS_JOB, array( Sms::class, 'job' ) );
		add_action( Newsletter::HOOK, array( Newsletter::class, 'job' ) );
		add_filter( 'asem_my_sections', array( __CLASS__, 'my_sections' ), 40 );
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 40, 3 );
		add_filter( 'asem_payment_methods', array( __CLASS__, 'payment_methods' ) );
		add_filter( 'asem_online_account_names', array( __CLASS__, 'account_names' ) );
		add_filter( 'asem_online_methods', array( __CLASS__, 'online_methods' ) );
		Frontend\Actions::add_handler( 'asem_front_consent', array( Frontend\ConsentFront::class, 'do_save' ) );
		Admin\BlockEPanels::register();
	}

	/** Lavori periodici (si pianificano quando le funzioni ci sono). */
	public static function schedule(): void {
		if ( Edition::installed( 'sms' ) && ! wp_next_scheduled( self::SMS_JOB ) ) {
			wp_schedule_event( time() + 900, 'hourly', self::SMS_JOB );
		}
		if ( Edition::installed( 'newsletter' ) && ! wp_next_scheduled( Newsletter::HOOK ) ) {
			wp_schedule_event( time() + 3000, 'daily', Newsletter::HOOK );
		}
	}

	public static function unschedule(): void {
		foreach ( array( self::SMS_JOB, Newsletter::HOOK, Sms::HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// ---------- Impostazioni ----------

	public static function defaults( array $d ): array {
		$d += array(
			'sms_provider'        => '',
			'sms_sender'          => '',
			'sms_reminders'       => 0,    // SMS anche per i promemoria: spenti di default
			'newsletter_provider' => '',
			'newsletter_list'     => '',
			'newsletter_auto'     => 1,    // sincronizzazione giornaliera
			'newsletter_extra'    => 0,    // tipo di socio e stato della tessera: spento di default
		);
		foreach ( array_merge( Sms::setting_keys(), Newsletter::setting_keys() ) as $k => $secret ) {
			$d[ $k ] = $d[ $k ] ?? '';
		}
		return $d;
	}

	public static function clean( array $clean ): array {
		$clean['sms_provider']        = isset( Sms::providers()[ (string) ( $clean['sms_provider'] ?? '' ) ] ) ? (string) $clean['sms_provider'] : '';
		$clean['sms_sender']          = mb_substr( SmsRules::clean_sender( (string) ( $clean['sms_sender'] ?? '' ) ), 0, 15 );
		$clean['sms_reminders']       = empty( $clean['sms_reminders'] ) ? 0 : 1;
		$clean['newsletter_provider'] = isset( Newsletter::providers()[ (string) ( $clean['newsletter_provider'] ?? '' ) ] ) ? (string) $clean['newsletter_provider'] : '';
		$clean['newsletter_list']     = mb_substr( trim( (string) ( $clean['newsletter_list'] ?? '' ) ), 0, 60 );
		$clean['newsletter_auto']     = empty( $clean['newsletter_auto'] ) ? 0 : 1;
		$clean['newsletter_extra']    = empty( $clean['newsletter_extra'] ) ? 0 : 1;
		foreach ( array_merge( Sms::setting_keys(), Newsletter::setting_keys() ) as $k => $secret ) {
			if ( ! $secret ) {
				$clean[ $k ] = mb_substr( trim( (string) ( $clean[ $k ] ?? '' ) ), 0, 200 );
			}
		}
		return $clean;
	}

	/** Le chiavi dei fornitori di SMS e dei servizi di newsletter sono segreti: cifrate, mai mostrate. */
	public static function secret_keys( array $keys ): array {
		foreach ( array_merge( Sms::setting_keys(), Newsletter::setting_keys() ) as $k => $secret ) {
			if ( $secret ) {
				$keys[] = $k;
			}
		}
		return $keys;
	}

	// ---------- Privacy ----------

	public static function privacy_export( array $data, int $person_id ): array {
		return Consents::privacy_export( $data, $person_id );
	}

	public static function anonymize( int $person_id ): void {
		if ( Edition::installed( 'newsletter' ) ) {
			Newsletter::on_anonymized( $person_id ); // prima di tutto: serve l'indirizzo conservato per toglierla dalla lista
		}
		Sms::forget( $person_id );
		Consents::forget( $person_id );
	}

	// ---------- Area soci ----------

	/** «Le mie comunicazioni» compare dopo il profilo, se l'associazione usa SMS o newsletter. */
	public static function my_sections( array $sections ): array {
		if ( ( Edition::has( 'sms' ) || Edition::has( 'newsletter' ) ) && ! in_array( 'consensi', $sections, true ) ) {
			$sections[] = 'consensi';
		}
		return $sections;
	}

	public static function area_section( $html, string $key, array $p ) {
		return 'consensi' === $key ? (string) $html . Frontend\ConsentFront::section( $p ) : $html;
	}

	// ---------- Satispay ----------

	public static function payment_methods( array $m ): array {
		return $m + array( PaymentConfig::SATISPAY => 'Satispay' );
	}

	public static function account_names( array $n ): array {
		return $n + array( PaymentConfig::SATISPAY => 'Satispay' );
	}

	public static function online_methods( array $m ): array {
		return array_merge( $m, array( PaymentConfig::SATISPAY ) );
	}
}
