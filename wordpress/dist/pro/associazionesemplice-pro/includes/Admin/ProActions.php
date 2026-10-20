<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\AssocPolicies;
use AssociazioneSemplice\Attachments;
use AssociazioneSemplice\Attendance;
use AssociazioneSemplice\Audit;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\FivePerMille;
use AssociazioneSemplice\Fiscal;
use AssociazioneSemplice\Gateways;
use AssociazioneSemplice\Insurance;
use AssociazioneSemplice\Statement;
use AssociazioneSemplice\WooBridge;
use AssociazioneSemplice\WooLinks;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\PeopleCsv;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gestori dei moduli delle funzioni aggiuntive (pagamenti online, conti e fondi, anni solari, ricevute, comunicazioni, Wallet, testi, livelli).
 * Si registrano su Actions::add_handler(); senza la funzione l'azione si rifiuta con il messaggio standard.
 */
final class ProActions {

	/** Azione => [metodo, funzione, riservata all'amministratore]. */
	const MAP = array(
		'asem_save_payment_settings' => array( 'save_payment_settings', 'payments', true ),
		'asem_test_gateway' => array( 'test_gateway', 'payments', true ),
		'asem_check_payments' => array( 'check_payments', 'payments', false ),
		'asem_payment_reviewed' => array( 'payment_reviewed', 'payments', false ),
		'asem_save_group_cash' => array( 'save_group_cash', 'funds', false ),
		'asem_save_transfer' => array( 'save_transfer', 'funds', false ),
		'asem_add_account' => array( 'add_account', 'funds', false ),
		'asem_update_account' => array( 'update_account', 'funds', false ),
		'asem_close_account' => array( 'close_account', 'funds', false ),
		'asem_reopen_account' => array( 'reopen_account', 'funds', false ),
		'asem_fund_create' => array( 'fund_create', 'funds', false ),
		'asem_fund_deposit' => array( 'fund_deposit', 'funds', false ),
		'asem_fund_release' => array( 'fund_release', 'funds', false ),
		'asem_fund_settle' => array( 'fund_settle', 'funds', false ),
		'asem_create_year' => array( 'create_year', 'fiscal', true ),
		'asem_close_year' => array( 'close_year', 'fiscal', true ),
		'asem_reopen_year' => array( 'reopen_year', 'fiscal', true ),
		'asem_receipt_email' => array( 'receipt_email', 'receipts', false ),
		'asem_walk_in' => array( 'walk_in', 'door_sales', false ),
		'asem_broadcast_send' => array( 'broadcast_send', 'broadcasts', false ),
		'asem_broadcast_test' => array( 'broadcast_test', 'broadcasts', false ),
		'asem_broadcast_retry' => array( 'broadcast_retry', 'broadcasts', false ),
		'asem_save_wallet_apple' => array( 'save_wallet_apple', 'wallet', true ),
		'asem_save_wallet_google' => array( 'save_wallet_google', 'wallet', true ),
		'asem_wallet_clear' => array( 'wallet_clear', 'wallet', true ),
		'asem_save_terms' => array( 'save_terms', 'texts', true ),
		'asem_save_texts' => array( 'save_texts', 'texts', true ),
		'asem_import_texts' => array( 'import_texts', 'texts', true ),
		'asem_reset_texts' => array( 'reset_texts', 'texts', true ),
		'asem_add_text' => array( 'add_text', 'texts', true ),
		'asem_save_language' => array( 'save_language', 'texts', true ),
		'asem_import_language' => array( 'import_language', 'texts', true ),
		'asem_delete_language' => array( 'delete_language', 'texts', true ),
		'asem_save_levels' => array( 'save_levels', 'levels', true ),
		'asem_insurance_add' => array( 'insurance_add', 'insurance', false ),
		'asem_insurance_delete' => array( 'insurance_delete', 'insurance', false ),
		'asem_policy_add' => array( 'policy_add', 'insurance', false ),
		'asem_policy_delete' => array( 'policy_delete', 'insurance', false ),
		'asem_attendance_save' => array( 'attendance_save', 'insurance', false ),
		'asem_fivepm_settings' => array( 'fivepm_settings', 'fivepm', false ),
		'asem_fivepm_add' => array( 'fivepm_add', 'fivepm', false ),
		'asem_fivepm_report' => array( 'fivepm_report', 'fivepm', false ),
		'asem_fivepm_delete' => array( 'fivepm_delete', 'fivepm', false ),
		'asem_statement_notes' => array( 'statement_notes', 'fiscal', false ),
		'asem_save_woo' => array( 'save_woo', 'payments', true ),
		'asem_save_app' => array( 'save_app', 'pwa', true ),
		'asem_push_test' => array( 'push_test', 'pwa', true ),
		'asem_push_reset' => array( 'push_reset', 'pwa', true ),
	);

	public static function register(): void {
		foreach ( self::MAP as $action => $row ) {
			Actions::add_handler( $action, self::guarded( $row[0], $row[1] ), $row[2] );
		}
	}

	private static function guarded( string $method, string $feature ): callable {
		return function ( array $p ) use ( $method, $feature ) {
			if ( ! Edition::has( $feature ) ) {
				throw new \InvalidArgumentException( Edition::missing_message( $feature ) );
			}
			return self::$method( $p );
		};
	}

	public static function save_language( array $p ): array {
		$code = (string) ( $p['language'] ?? '' );
		if ( ! isset( \AssociazioneSemplice\Languages::available()[ $code ] ) ) {
			throw new \InvalidArgumentException( 'Lingua non disponibile.' );
		}
		Settings::update( array( 'language' => $code ) );
		\AssociazioneSemplice\Texts::flush();
		return array( Ui::url( 'asem-texts' ), 'Lingua impostata: ' . \AssociazioneSemplice\Languages::available()[ $code ] . '.' );
	}

	public static function import_language( array $p ): array {
		if ( empty( $_FILES['lang_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['lang_file']['error'] || ! is_uploaded_file( $_FILES['lang_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli il file della traduzione.' );
		}
		if ( (int) $_FILES['lang_file']['size'] > 5 * 1048576 ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Il file è troppo grande (massimo 5 MB).' );
		}
		$name   = (string) $_FILES['lang_file']['name']; // phpcs:ignore WordPress.Security
		$pairs  = array();
		if ( 'json' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$j = json_decode( (string) file_get_contents( (string) $_FILES['lang_file']['tmp_name'] ), true ); // phpcs:ignore WordPress.Security
			$pairs = is_array( $j ) && isset( $j['strings'] ) && is_array( $j['strings'] ) ? $j['strings'] : ( is_array( $j ) ? $j : array() );
		} else {
			$err = null;
			foreach ( \AssociazioneSemplice\SheetReader::read( (string) $_FILES['lang_file']['tmp_name'], $name ) as $s ) { // phpcs:ignore WordPress.Security
				try {
					$pairs = \AssociazioneSemplice\Languages::pairs_from_rows( $s['rows'] );
					break;
				} catch ( \InvalidArgumentException $e ) {
					$err = $e;
				}
			}
			if ( ! $pairs && $err ) {
				throw $err;
			}
		}
		$code = trim( (string) ( $p['code'] ?? '' ) );
		$n    = \AssociazioneSemplice\Languages::save_pack( $code, (string) ( $p['name'] ?? '' ), $pairs );
		return array( Ui::url( 'asem-texts' ), 'Traduzione caricata: ' . $n . ' frasi. Ora puoi sceglierla tra le lingue.' );
	}

	public static function delete_language( array $p ): array {
		$code = (string) ( $p['code'] ?? '' );
		\AssociazioneSemplice\Languages::delete_pack( $code );
		if ( Settings::get( 'language' ) === $code ) {
			Settings::update( array( 'language' => 'it' ) );
		}
		return array( Ui::url( 'asem-texts' ), 'Traduzione eliminata.' );
	}

	public static function save_levels( array $p ): array {
		if ( ! \AssociazioneSemplice\Edition::has( 'levels' ) ) {
			throw new \InvalidArgumentException( 'Questa edizione ha un\'unica quota associativa.' );
		}
		$rows = array();
		foreach ( (array) ( $p['level'] ?? array() ) as $r ) {
			if ( is_array( $r ) ) {
				$rows[] = $r;
			}
		}
		\AssociazioneSemplice\Levels::save( $rows );
		return array( Ui::url( 'asem-settings' ), 'Livelli di socio salvati.' );
	}

	public static function receipt_email( array $p ): array {
		\AssociazioneSemplice\Receipts::email( (string) ( $p['key'] ?? '' ) );
		return array( $p['_back'] ?? Ui::url( 'asem-ledger' ), 'Ricevuta inviata per email.' );
	}

	public static function save_terms( array $p ): array {
		if ( ! empty( $p['preset'] ) ) { // versione base: femminile (associazione, socie) o maschile (comitato, soci)
			$pre = \AssociazioneSemplice\Terms::PRESETS[ (string) $p['preset'] ] ?? null;
			if ( ! $pre ) {
				throw new \InvalidArgumentException( 'Versione non valida.' );
			}
			Settings::update( array( 'entity_type' => $pre[0], 'member_term' => $pre[1] ) );
			return array( Ui::url( 'asem-texts' ), 'Versione ' . $p['preset'] . ' impostata: tutti i testi sono adattati.' );
		}
		$ec = (string) ( $p['entity_types_custom'] ?? '' );
		$mc = (string) ( $p['member_terms_custom'] ?? '' );
		$ents = \AssociazioneSemplice\Terms::entity_types( $ec );
		$mems = \AssociazioneSemplice\Terms::member_terms( $mc );
		$ent  = mb_strtolower( trim( (string) ( $p['entity_type'] ?? '' ) ), 'UTF-8' );
		$mem  = mb_strtolower( trim( (string) ( $p['member_term'] ?? '' ) ), 'UTF-8' );
		if ( ! isset( $ents[ $ent ] ) ) {
			throw new \InvalidArgumentException( 'Scegli un tipo di ente dall\'elenco (o aggiungilo a mano: una riga «nome;m» oppure «nome;f»).' );
		}
		if ( ! isset( $mems[ $mem ] ) ) {
			throw new \InvalidArgumentException( 'Scegli un termine dall\'elenco (o aggiungilo a mano: una riga «singolare;plurale;m» oppure «…;f»).' );
		}
		Settings::update( array( 'entity_type' => $ent, 'entity_types_custom' => $ec, 'member_term' => $mem, 'member_terms_custom' => $mc ) );
		return array( Ui::url( 'asem-texts' ), 'Tipo di ente e termini salvati: i testi sono adattati.' );
	}

	public static function save_texts( array $p ): array {
		$ov = \AssociazioneSemplice\TextsEngine::overrides();
		$by = array();
		foreach ( \AssociazioneSemplice\TextsEngine::rows() as $r ) {
			$by[ md5( $r['text'] ) ] = $r['text'];
		}
		foreach ( (array) ( $p['t'] ?? array() ) as $hash => $custom ) {
			if ( ! isset( $by[ (string) $hash ] ) ) {
				continue; // solo testi che esistono davvero
			}
			$custom = trim( str_replace( "\r\n", "\n", (string) $custom ) );
			if ( '' === $custom ) {
				unset( $ov[ $by[ $hash ] ] );
			} else {
				$ov[ $by[ $hash ] ] = $custom;
			}
		}
		\AssociazioneSemplice\TextsEngine::save_overrides( $ov );
		return array( $p['_back'] ?? Ui::url( 'asem-texts' ), 'Testi salvati.' );
	}

	public static function import_texts( array $p ): array {
		if ( empty( $_FILES['texts_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['texts_file']['error'] || ! is_uploaded_file( $_FILES['texts_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Scegli il file da importare.' );
		}
		if ( (int) $_FILES['texts_file']['size'] > 5 * 1048576 ) { // phpcs:ignore WordPress.Security
			throw new \InvalidArgumentException( 'Il file è troppo grande (massimo 5 MB).' );
		}
		$sheets = \AssociazioneSemplice\SheetReader::read( (string) $_FILES['texts_file']['tmp_name'], (string) $_FILES['texts_file']['name'] ); // phpcs:ignore WordPress.Security
		$res    = null;
		$err    = null;
		foreach ( $sheets as $s ) {
			try {
				$res = \AssociazioneSemplice\TextsEngine::import_rows( $s['rows'] );
				break;
			} catch ( \InvalidArgumentException $e ) {
				$err = $e;
			}
		}
		if ( ! $res ) {
			throw $err ?: new \InvalidArgumentException( 'File non valido.' );
		}
		return array( Ui::url( 'asem-texts' ), 'Importazione completata: ' . $res['set'] . ' testi impostati, ' . $res['removed'] . ' ripristinati, ' . $res['manual'] . ' aggiunte a mano' . ( $res['ignored'] ? ', ' . $res['ignored'] . ' righe ignorate' : '' ) . '.' );
	}

	public static function reset_texts( array $p ): array {
		\AssociazioneSemplice\TextsEngine::save_overrides( array() );
		return array( Ui::url( 'asem-texts' ), 'Testi originali ripristinati.' );
	}

	public static function add_text( array $p ): array {
		$o = trim( (string) ( $p['original'] ?? '' ) );
		$c = trim( (string) ( $p['custom'] ?? '' ) );
		if ( strlen( $o ) < \AssociazioneSemplice\TextsEngine::MIN_LEN || '' === $c ) {
			throw new \InvalidArgumentException( 'Scrivi il testo di oggi (almeno 3 caratteri) e quello nuovo.' );
		}
		$ov       = \AssociazioneSemplice\TextsEngine::overrides();
		$ov[ $o ] = $c;
		\AssociazioneSemplice\TextsEngine::save_overrides( $ov );
		return array( $p['_back'] ?? Ui::url( 'asem-texts' ), 'Sostituzione aggiunta.' );
	}

	/**
	 * Chi si presenta senza aver prenotato: prenota (creando l'ospite se serve), incassa il contributo e registra l'ingresso.
	 * Tutto insieme: se qualcosa non va (posti esauriti, conto non valido...) non resta scritto nulla.
	 */
	public static function walk_in( array $p ): array {
		$msg = \AssociazioneSemplice\DoorSales::sell( $p );
		return array( Ui::url( 'asem-activity', array( 'id' => (int) ( $p['activity_id'] ?? 0 ) ) ), $msg );
	}

	public static function broadcast_send( array $p ): array {
		list( $g, $ref ) = MessagesPage::parse_audience( (string) ( $p['audience'] ?? '' ) );
		$id = \AssociazioneSemplice\Broadcasts::create( (string) ( $p['subject'] ?? '' ), (string) ( $p['body'] ?? '' ), $g, $ref );
		$b  = \AssociazioneSemplice\Broadcasts::get( $id );
		return array( Ui::url( 'asem-messages', array( 'view' => $id ) ), 'Comunicazione avviata: ' . (int) $b['sent'] . ' email inviate su ' . (int) $b['total'] . ( (int) $b['total'] > (int) $b['sent'] ? ', le altre continuano da sole in background.' : '.' ) );
	}

	public static function broadcast_test( array $p ): array {
		if ( ! \AssociazioneSemplice\Broadcasts::send_test( (string) ( $p['subject'] ?? '' ), (string) ( $p['body'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( 'Invio della prova non riuscito: controlla la posta in uscita del sito.' );
		}
		return array( $p['_back'] ?? Ui::url( 'asem-messages' ), 'Prova inviata al tuo indirizzo email.' );
	}

	public static function broadcast_retry( array $p ): array {
		$id = (int) ( $p['id'] ?? 0 );
		$n  = \AssociazioneSemplice\Broadcasts::retry_failed( $id );
		return array( Ui::url( 'asem-messages', array( 'view' => $id ) ), $n . ( 1 === $n ? ' email rimessa in coda.' : ' email rimesse in coda.' ) );
	}

	/**
	 * Cassa per più persone: chi paga salda eventi, corsi e quote per sé e per altri. Tutto in un'unica operazione (se qualcosa non va non resta scritto nulla):
	 * crea i nuovi ospiti, prenota o iscrive chi non lo è ancora e registra un solo incasso con le voci intestate ai beneficiari.
	 * Eccezione voluta: un evento si può pagare anche per un socio sospeso o con la tessera non in regola; per i corsi la tessera deve essere in regola.
	 */
	public static function save_group_cash( array $p ): array {
		$summary = \AssociazioneSemplice\GroupCash::record( $p );
		return array( Ui::url( 'asem-ledger' ), 'Incasso registrato: ' . $summary['lines'] . ( 1 === $summary['lines'] ? ' voce' : ' voci' ) . ' per ' . $summary['people'] . ( 1 === $summary['people'] ? ' persona' : ' persone' ) . ', totale ' . Money::format( $summary['cents'] ) . '.' );
	}

	public static function save_transfer( array $p ): array {
		Plugin::ledger()->record_transfer(
			(string) ( $p['date'] ?? '' ), (int) ( $p['from_id'] ?? 0 ), (int) ( $p['to_id'] ?? 0 ),
			Money::parse( $p['amount'] ?? '' ) ?? 0, (string) ( $p['method'] ?? '' ), trim( (string) ( $p['description'] ?? '' ) )
		);
		return array( Ui::url( 'asem-ledger' ), 'Giroconto registrato.' );
	}

	public static function create_year( array $p ): array {
		\AssociazioneSemplice\FiscalYears::create( (int) ( $p['year'] ?? 0 ) );
		return array( Ui::url( 'asem-years' ), 'Anno solare creato.' );
	}

	public static function close_year( array $p ): array {
		\AssociazioneSemplice\FiscalYears::close( (int) ( $p['year'] ?? 0 ) );
		return array( Ui::url( 'asem-years' ), 'Anno solare chiuso: non accetta più incassi né spese.' );
	}

	public static function reopen_year( array $p ): array {
		\AssociazioneSemplice\FiscalYears::reopen( (int) ( $p['year'] ?? 0 ) );
		return array( Ui::url( 'asem-years' ), 'Anno solare riaperto.' );
	}

	public static function add_account( array $p ): array {
		Plugin::ledger()->add_account( (string) ( $p['name'] ?? '' ), (string) ( $p['type'] ?? '' ), Money::parse( $p['opening'] ?? '' ) ?? 0 );
		return array( Ui::url( 'asem-accounts' ), 'Conto aggiunto.' );
	}

	public static function update_account( array $p ): array {
		Plugin::ledger()->update_account( (int) ( $p['id'] ?? 0 ), (string) ( $p['name'] ?? '' ), (string) ( $p['type'] ?? '' ), Money::parse( $p['opening'] ?? '' ) ?? 0 );
		return array( Ui::url( 'asem-accounts' ), 'Conto aggiornato: i saldi sono stati ricalcolati.' );
	}

	public static function fund_create( array $p ): array {
		Plugin::funds()->create( (string) ( $p['name'] ?? '' ), Money::parse( $p['amount'] ?? '' ) ?? 0, ! empty( $p['person_id'] ) ? (int) $p['person_id'] : null );
		return array( Ui::url( 'asem-accounts' ), 'Fondo creato.' );
	}

	public static function fund_deposit( array $p ): array {
		Plugin::funds()->deposit( (int) ( $p['id'] ?? 0 ), Money::parse( $p['amount'] ?? '' ) ?? 0, (string) ( $p['date'] ?? current_time( 'Y-m-d' ) ) );
		return array( Ui::url( 'asem-accounts' ), 'Somma accantonata nel fondo.' );
	}

	public static function fund_release( array $p ): array {
		$cents = Money::parse( $p['amount'] ?? '' );
		if ( null === $cents ) {
			throw new \InvalidArgumentException( 'Indica l\'importo da liberare.' );
		}
		Plugin::funds()->release( (int) ( $p['id'] ?? 0 ), $cents, (string) ( $p['date'] ?? current_time( 'Y-m-d' ) ) );
		return array( Ui::url( 'asem-accounts' ), 'Quota liberata: è tornata nella disponibilità reale.' );
	}

	public static function fund_settle( array $p ): array {
		$tx = Plugin::funds()->settle( (int) ( $p['id'] ?? 0 ), (int) ( $p['account_id'] ?? 0 ), (string) ( $p['method'] ?? '' ), (string) ( $p['date'] ?? current_time( 'Y-m-d' ) ) );
		return array( Ui::url( 'asem-accounts' ), $tx ? 'Rimborso registrato in prima nota e fondo estinto.' : 'Fondo a zero: estinto.' );
	}

	public static function close_account( array $p ): array {
		Plugin::ledger()->close_account( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-accounts' ), 'Conto chiuso.' );
	}

	public static function reopen_account( array $p ): array {
		Plugin::ledger()->reopen_account( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-accounts' ), 'Conto riaperto.' );
	}

	public static function save_payment_settings( array $p ): array {
		$txt = function ( string $k ) use ( $p ) {
			return sanitize_text_field( $p[ $k ] ?? '' );
		};
		Settings::update(
			array(
				'payment_provider'        => $txt( 'payment_provider' ),
				'stripe_mode'             => $txt( 'stripe_mode' ),
				'stripe_publishable_key'  => $txt( 'stripe_publishable_key' ),
				'stripe_secret_key'       => $txt( 'stripe_secret_key' ),     // vuoto = lascia quella salvata
				'stripe_webhook_secret'   => $txt( 'stripe_webhook_secret' ),
				'paypal_mode'             => $txt( 'paypal_mode' ),
				'paypal_client_id'        => $txt( 'paypal_client_id' ),
				'paypal_client_secret'    => $txt( 'paypal_client_secret' ),
				'pay_label_stripe'        => $txt( 'pay_label_stripe' ),
				'pay_note_stripe'         => $txt( 'pay_note_stripe' ),
				'pay_label_paypal'        => $txt( 'pay_label_paypal' ),
				'pay_note_paypal'         => $txt( 'pay_note_paypal' ),
				'pay_label_woocommerce'   => $txt( 'pay_label_woocommerce' ),
				'pay_note_woocommerce'    => $txt( 'pay_note_woocommerce' ),
			)
		);
		foreach ( \AssociazioneSemplice\Settings::secret_keys() as $k ) {
			if ( ! empty( $p[ 'clear_' . $k ] ) ) {
				Settings::clear_secret( $k );
			}
		}
		$check = PaymentConfig::validate( \AssociazioneSemplice\ProSettings::payment_config() );
		$msg   = 'Impostazioni dei pagamenti salvate.';
		if ( $check['errors'] ) {
			$msg .= ' Attenzione: ' . implode( ' ', $check['errors'] );
		}
		return array( Ui::url( 'asem-payments' ), $msg );
	}

	/** Prova la connessione a Stripe o PayPal con le chiavi salvate (solo quando l'amministratore preme il pulsante). */
	public static function test_gateway( array $p ): array {
		$provider = in_array( $p['provider'] ?? '', array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL ), true ) ? $p['provider'] : '';
		$res      = Gateways::test( $provider, \AssociazioneSemplice\ProSettings::payment_config(), array( Gateways::class, 'wp_http' ) );
		Audit::log( 'gateway.tested', 'settings', null, array( 'provider' => $provider, 'ok' => $res['ok'] ) );
		if ( ! $res['ok'] ) {
			throw new \InvalidArgumentException( $res['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( Ui::url( 'asem-payments' ), $res['message'] );
	}

	/** Salva la chiave di licenza e la verifica subito con il server. */
	public static function check_payments( array $p ): array {
		$r = Plugin::payments()->check_pending( true );
		return array( Ui::url( 'asem-payments' ), 'Controllati ' . $r['checked'] . ', registrati ' . $r['registered'] . ', scaduti ' . $r['expired'] . '.' );
	}

	public static function payment_reviewed( array $p ): array {
		Plugin::payments()->mark_reviewed( (int) $p['id'] );
		return array( Ui::url( 'asem-payments' ), 'Pagamento segnato come controllato.' );
	}

	public static function save_wallet_apple( array $p ): array {
		$msg = \AssociazioneSemplice\Wallet::save_apple( array( 'password' => $p['apple_password'] ?? '', 'pass_type' => $p['pass_type'] ?? '', 'team' => $p['team'] ?? '' ), Actions::uploaded_bytes( 'apple_p12' ), Actions::uploaded_bytes( 'apple_wwdr' ) );
		return array( Ui::url( 'asem-card' ), $msg );
	}

	public static function save_wallet_google( array $p ): array {
		return array( Ui::url( 'asem-card' ), \AssociazioneSemplice\Wallet::save_google( array( 'issuer' => $p['issuer'] ?? '' ), Actions::uploaded_bytes( 'google_json' ) ) );
	}

	public static function wallet_clear( array $p ): array {
		\AssociazioneSemplice\Wallet::clear( (string) ( $p['which'] ?? '' ) );
		return array( Ui::url( 'asem-card' ), 'Credenziali rimosse.' );
	}


	public static function insurance_add( array $p ): array {
		Insurance::add( (int) ( $p['person_id'] ?? 0 ), (string) ( $p['company'] ?? '' ), (string) ( $p['policy_no'] ?? '' ), (string) ( $p['valid_from'] ?? '' ), (string) ( $p['valid_to'] ?? '' ), (string) ( $p['notes'] ?? '' ) );
		return array( Ui::url( 'asem-volunteers' ), 'Polizza registrata.' );
	}

	public static function insurance_delete( array $p ): array {
		Insurance::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-volunteers' ), 'Polizza eliminata.' );
	}

	public static function policy_add( array $p ): array {
		AssocPolicies::add( (string) ( $p['kind'] ?? '' ), (string) ( $p['company'] ?? '' ), (string) ( $p['policy_no'] ?? '' ), (string) ( $p['valid_from'] ?? '' ), (string) ( $p['valid_to'] ?? '' ), (string) ( $p['premium'] ?? '' ), (string) ( $p['coverage'] ?? '' ) );
		return array( Ui::url( 'asem-volunteers' ), 'Polizza registrata.' );
	}

	public static function policy_delete( array $p ): array {
		AssocPolicies::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-volunteers' ), 'Polizza eliminata.' );
	}

	public static function attendance_save( array $p ): array {
		$aid  = (int) ( $p['activity_id'] ?? 0 );
		$date = (string) ( $p['date'] ?? '' );
		$n    = Attendance::save( $aid, $date, array_map( 'intval', (array) ( $p['present'] ?? array() ) ), get_current_user_id() );
		return array( Ui::url( 'asem-attendance', array( 'activity' => $aid, 'ym' => substr( $date, 0, 7 ), 'date' => $date ) ), 'Presenze registrate (' . $n . ' persone).' );
	}

	public static function fivepm_settings( array $p ): array {
		if ( ! current_user_can( Plugin::CAP ) ) {
			throw new \InvalidArgumentException( 'Solo un amministratore può attivare il 5x1000.' );
		}
		Settings::update( array( 'fivepm_enabled' => ! empty( $p['fivepm_enabled'] ) ? 1 : 0, 'fivepm_text' => (string) ( $p['fivepm_text'] ?? '' ) ) );
		return array( Ui::url( 'asem-fivepm' ), 'Impostazioni del 5x1000 salvate.' );
	}

	public static function fivepm_add( array $p ): array {
		FivePerMille::add( (int) ( $p['year'] ?? 0 ), (string) ( $p['amount'] ?? '' ), (int) ( $p['choices'] ?? 0 ), (string) ( $p['received_on'] ?? '' ) );
		return array( Ui::url( 'asem-fivepm' ), 'Contributo registrato.' );
	}

	public static function fivepm_report( array $p ): array {
		FivePerMille::report( (int) ( $p['id'] ?? 0 ), (string) ( $p['reported_on'] ?? '' ), (string) ( $p['report_notes'] ?? '' ) );
		return array( Ui::url( 'asem-fivepm' ), 'Rendiconto registrato.' );
	}

	public static function fivepm_delete( array $p ): array {
		FivePerMille::delete( (int) ( $p['id'] ?? 0 ) );
		return array( Ui::url( 'asem-fivepm' ), 'Contributo eliminato.' );
	}

	public static function statement_notes( array $p ): array {
		$year = (int) ( $p['year'] ?? 0 );
		Statement::save_notes( $year, (string) ( $p['notes'] ?? '' ) );
		return array( Ui::url( 'asem-statement', array( 'year' => $year ) ), 'Relazione salvata.' );
	}


	public static function save_woo( array $p ): array {
		$on = ! empty( $p['woo_enabled'] );
		if ( $on && ! WooBridge::active() ) {
			throw new \InvalidArgumentException( 'WooCommerce non è attivo su questo sito: installalo e attivalo prima.' );
		}
		$default = (int) ( $p['woo_default_product'] ?? 0 );
		if ( $default > 0 ) {
			WooBridge::assert_product( $default );
		}
		foreach ( (array) ( $p['link_level'] ?? array() ) as $id => $product ) {
			WooLinks::set( WooLinks::LEVEL, (int) $id, (int) $product );
		}
		foreach ( (array) ( $p['link_activity'] ?? array() ) as $id => $product ) {
			WooLinks::set( WooLinks::ACTIVITY, (int) $id, (int) $product );
		}
		$provider = (string) Settings::get( 'payment_provider' );
		if ( $on ) {
			$provider = PaymentConfig::WOOCOMMERCE;
		} elseif ( PaymentConfig::WOOCOMMERCE === $provider ) {
			$provider = PaymentConfig::NONE;
		}
		Settings::update( array( 'payment_provider' => $provider, 'woo_default_product' => $default ) );
		return array( Ui::url( 'asem-tech' ), $on ? 'WooCommerce attivo: i pagamenti passano dal negozio.' : 'Impostazioni di WooCommerce salvate.' );
	}

	public static function save_app( array $p ): array {
		$push = ! empty( $p['push_enabled'] );
		$pwa  = ! empty( $p['pwa_enabled'] );
		if ( $push && ! \AssociazioneSemplice\WebPush::supported() ) {
			throw new \InvalidArgumentException( 'Questo server non ha le funzioni di cifratura necessarie per le notifiche (estensione openssl di PHP).' );
		}
		$vals = array( 'pwa_enabled' => $pwa ? 1 : 0, 'push_enabled' => ( $push && $pwa ) ? 1 : 0, 'pwa_name' => (string) ( $p['pwa_name'] ?? '' ), 'pwa_short_name' => (string) ( $p['pwa_short_name'] ?? '' ) );
		if ( ! empty( $p['pwa_icon_remove'] ) ) {
			$vals['pwa_icon_id'] = 0;
		}
		if ( ! empty( $_FILES['pwa_icon']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['pwa_icon']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- valore verificato e ripulito da chi lo usa
			$vals['pwa_icon_id'] = self::upload_icon( $_FILES['pwa_icon'] ); // phpcs:ignore WordPress.Security
		}
		Settings::update( $vals );
		if ( $pwa ) {
			\AssociazioneSemplice\Push::vapid(); // crea le chiavi del sito se non ci sono
		}
		return array( Ui::url( 'asem-app' ), $pwa ? 'App e notifiche salvate.' : 'App spenta.' );
	}

	/** Icona dell'app: PNG quadrato di almeno 192 pixel (meglio 512). @return int id dell'immagine nella libreria media */
	public static function upload_icon( array $file ): int {
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) || (int) $file['size'] > 2 * 1048576 ) {
			throw new \InvalidArgumentException( 'Caricamento dell\'icona non riuscito (PNG, massimo 2 MB).' );
		}
		$info = @getimagesize( (string) $file['tmp_name'] );
		if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] !== $info[1] || $info[0] < 192 ) {
			throw new \InvalidArgumentException( 'L\'icona deve essere un\'immagine PNG quadrata di almeno 192 pixel (meglio 512×512).' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$up = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'png' => 'image/png' ) ) );
		if ( ! empty( $up['error'] ) ) {
			throw new \InvalidArgumentException( 'Caricamento dell\'icona non riuscito: ' . $up['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Icona dell\'app', 'post_status' => 'inherit' ), $up['file'] );
		if ( ! $id || is_wp_error( $id ) ) {
			throw new \InvalidArgumentException( 'Impossibile salvare l\'icona.' );
		}
		wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $up['file'] ) );
		return (int) $id;
	}

	public static function push_test( array $p ): array {
		if ( ! \AssociazioneSemplice\Push::enabled() ) {
			throw new \InvalidArgumentException( 'Le notifiche non sono attive: accendile e salva.' );
		}
		$n = \AssociazioneSemplice\Push::notify_users( array( get_current_user_id() ), 'Prova delle notifiche', 'Se leggi questo messaggio le notifiche funzionano.', \AssociazioneSemplice\Gatekeeper::area_url() );
		return array( Ui::url( 'asem-app' ), $n > 0 ? 'Notifica di prova inviata a ' . $n . ( 1 === $n ? ' dispositivo.' : ' dispositivi.' ) : 'Nessun dispositivo collegato al tuo utente: apri l\'area soci dal telefono e attiva le notifiche.' );
	}

	public static function push_reset( array $p ): array {
		\AssociazioneSemplice\Push::reset_keys();
		return array( Ui::url( 'asem-app' ), 'Chiavi rigenerate: i dispositivi devono riattivare le notifiche.' );
	}

}
