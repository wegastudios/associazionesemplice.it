<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Configurazione guidata: in un'unica pagina raccoglie le impostazioni principali (ente, quote, funzioni, pagamenti, pagine del sito)
 * e le applica insieme. Si propone al primo avvio e si riapre quando serve da Impostazioni.
 */
final class Wizard {

	const OPTION    = 'asem_wizard_status';
	const TRANSIENT = 'asem_wizard_redirect';

	/** Funzioni che hanno una loro scheda e non stanno nell'elenco delle funzioni facoltative (le indica un'estensione con il filtro `asem_wizard_own_step`). */
	public static function own_step(): array {
		return array_values( (array) apply_filters( 'asem_wizard_own_step', array() ) );
	}

	const DONE    = 'done';
	const SKIPPED = 'skipped';


	public static function status(): string {
		$s = (string) get_option( self::OPTION, '' );
		return in_array( $s, array( self::DONE, self::SKIPPED ), true ) ? $s : '';
	}

	/** Da proporre in Bacheca finché non è stata completata né rimandata. */
	public static function pending(): bool {
		return '' === self::status();
	}

	public static function mark( string $status ): void {
		update_option( self::OPTION, in_array( $status, array( self::DONE, self::SKIPPED ), true ) ? $status : self::DONE, false );
		delete_transient( self::TRANSIENT );
	}

	/** Alla prima attivazione la configurazione guidata si apre da sola, una volta sola. */
	public static function schedule_first_run(): void {
		if ( self::pending() && false === get_transient( self::TRANSIENT ) ) {
			set_transient( self::TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	public static function maybe_redirect(): void {
		if ( ! get_transient( self::TRANSIENT ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || isset( $_GET['activate-multi'] ) || is_network_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		delete_transient( self::TRANSIENT );
		if ( self::pending() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=asem-wizard' ) );
			exit;
		}
	}

	/**
	 * Applica le scelte del modulo. Ogni parte è facoltativa: salva solo ciò che è presente.
	 *
	 * @param array $p dati inviati dal modulo
	 * @return string[] messaggi su ciò che è stato fatto
	 * @throws \InvalidArgumentException
	 */
	public static function apply( array $p ): array {
		$done = array();
		$vals = array();

		// 1. Ente
		$vals = EntityData::values( $p, false ); // nome, tipo, codice fiscale, sede, partita IVA: stesse regole della scheda «Dati e fiscalità»

		if ( array_key_exists( 'guests_enabled', $p ) ) {
			$vals['guests_enabled'] = ! empty( $p['guests_enabled'] ) ? 1 : 0;
		}
		if ( isset( $p['join_mode'] ) ) {
			$vals['join_mode']     = 'invite' === (string) $p['join_mode'] ? 'invite' : 'request';
			$vals['apply_enabled'] = 'request' === $vals['join_mode'] ? 1 : 0; // «chiunque può chiedere di iscriversi» apre anche la domanda online (pagina «Diventa socio»)
		}

		// Parti del gestionale usate
		if ( ! empty( $p['mod_present'] ) ) {
			$mods = array();
			foreach ( array_keys( Modules::defs() ) as $k ) {
				$mods[ $k ] = ! empty( $p['mod'][ $k ] ) ? 1 : 0;
			}
			$mods['import'] = 1; // le importazioni sono sempre disponibili (dagli Strumenti): non si chiedono
			$vals['modules']         = $mods;
			$vals['reports_enabled'] = ! empty( $mods['reports'] ) ? 1 : 0;
		}

		// Informativa privacy: si sceglie tra le pagine che esistono già su WordPress
		if ( array_key_exists( 'privacy_page_id', $p ) ) {
			$pid = (int) $p['privacy_page_id'];
			if ( $pid > 0 ) {
				if ( 'page' !== get_post_type( $pid ) || 'publish' !== get_post_status( $pid ) ) {
					throw new \InvalidArgumentException( 'La pagina scelta per l\'informativa privacy non esiste o non è pubblicata.' );
				}
				$vals['privacy_url'] = (string) get_permalink( $pid );
			} elseif ( '' !== (string) Settings::get( 'privacy_url' ) && url_to_postid( (string) Settings::get( 'privacy_url' ) ) > 0 ) {
				$vals['privacy_url'] = ''; // era una pagina del sito e ora non se ne vuole nessuna (un indirizzo esterno non si tocca)
			}
		}

		// Privacy e ricevute
		if ( isset( $p['privacy_url'] ) ) {
			$url = trim( (string) $p['privacy_url'] );
			if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				throw new \InvalidArgumentException( 'L\'indirizzo dell\'informativa privacy deve iniziare con http:// o https://.' );
			}
			$vals['privacy_url'] = $url;
		}
		if ( isset( $p['privacy_retention_years'] ) && '' !== trim( (string) $p['privacy_retention_years'] ) ) {
			$vals['privacy_retention_years'] = max( 1, min( 30, (int) $p['privacy_retention_years'] ) );
		}

		// 2. Anno sociale e quote
		if ( isset( $p['social_year_start_month'] ) ) {
			$vals['social_year_start_month'] = max( 1, min( 12, (int) $p['social_year_start_month'] ) );
		}
		if ( isset( $p['membership_fee'] ) && '' !== trim( (string) $p['membership_fee'] ) ) {
			$fee = Money::parse( (string) $p['membership_fee'] );
			if ( null === $fee || $fee < 0 ) {
				throw new \InvalidArgumentException( 'La quota associativa non è un importo valido.' );
			}
			$vals['membership_fee_cents'] = $fee;
		}
		if ( isset( $p['family_discount_pct'] ) && '' !== trim( (string) $p['family_discount_pct'] ) ) {
			$vals['family_discount_pct'] = max( 0, min( 100, (int) $p['family_discount_pct'] ) );
		}

		// 3. Funzioni facoltative (le app e le notifiche hanno la loro scheda)
		if ( ! empty( $p['features_present'] ) ) {
			foreach ( array_keys( \AssociazioneSemplice\Admin\SettingsPage::all_features() ) as $k ) {
				if ( in_array( $k, self::own_step(), true ) ) {
					continue;
				}
				$vals[ $k ] = ! empty( $p[ $k ] ) ? 1 : 0;
			}
		}

		// 4. Pagamenti: il bonifico (le estensioni aggiungono le loro scelte con il filtro `asem_wizard_values`)
		if ( isset( $p['payment_choice'] ) ) {
			$vals['bank_enabled'] = ! empty( $p['bank_enabled'] ) ? 1 : 0;
		}

		$vals = (array) apply_filters( 'asem_wizard_values', $vals, $p ); // un'estensione aggiunge e controlla le sue scelte

		if ( $vals ) {
			Settings::update( $vals );
			$done[] = 'Impostazioni salvate.';
		}

		$done = (array) apply_filters( 'asem_wizard_done', $done, $p ); // ... e riferisce cosa ha fatto

		// 5. Pagine del sito
		if ( ! empty( $p['pages_present'] ) ) {
			$keys = array_values( array_intersect( array_keys( Pages::defs() ), array_map( 'strval', (array) ( $p['pages'] ?? array() ) ) ) );
			$made = Pages::create( $keys );
			$done[] = $made ? 'Pagine create: ' . implode( ', ', $made ) . '.' : 'Le pagine scelte esistevano già.';
		}

		self::mark( self::DONE );
		Audit::log( 'wizard.completed', 'settings', 0, array( 'steps' => count( $done ) ) );
		return $done;
	}

}
