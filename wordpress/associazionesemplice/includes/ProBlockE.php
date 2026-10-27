<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * SMS ai soci, newsletter e nuovi metodi di pagamento (Satispay, Klarna) nei punti di estensione di Associazione Semplice: impostazioni, privacy,
 * area soci, promemoria e lavori periodici. Si avvia da {@see ProBoot::init()}.
 */
final class ProBlockE {

	const SMS_JOB = Sms::HOURLY; // lavoro orario; i gruppi successivi usano un evento singolo, Sms::HOOK

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
		add_filter( 'asem_direct_gateways', array( __CLASS__, 'direct_gateways' ) );
		add_filter( 'asem_payment_default_labels', array( __CLASS__, 'default_labels' ) );
		add_filter( 'asem_payment_validate', array( __CLASS__, 'validate_gateway' ), 10, 3 );
		add_filter( 'asem_payment_note', array( __CLASS__, 'payment_note' ), 10, 2 );
		add_filter( 'asem_fee_gateways', array( __CLASS__, 'fee_gateways' ) );
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
		return $m + array( SatispayRules::ID => 'Satispay' );
	}

	public static function account_names( array $n ): array {
		return $n + array( SatispayRules::ID => 'Satispay' );
	}

	public static function online_methods( array $m ): array {
		return array_merge( $m, array( SatispayRules::ID ) );
	}

	public static function direct_gateways( array $extra ): array {
		return $extra + array( SatispayRules::ID => 'Satispay' );
	}

	public static function default_labels( array $labels ): array {
		return $labels + array( SatispayRules::ID => 'Paga con Satispay' );
	}

	public static function fee_gateways( array $list ): array {
		return array_merge( $list, array( SatispayRules::ID ) );
	}

	public static function validate_gateway( $result, $config, $gateway ) {
		if ( SatispayRules::ID !== $gateway ) {
			return $result;
		}
		$r = SatispayRules::validate( (array) $config );
		return array( 'errors' => array_merge( (array) $result['errors'], $r['errors'] ), 'warnings' => array_merge( (array) $result['warnings'], $r['warnings'] ) );
	}

	public static function payment_note( $note, $gateway ) {
		return SatispayRules::ID === $gateway ? 'Confermi il pagamento nell\'app Satispay: i dati del tuo conto non passano da questo sito.' : $note;
	}
}
