<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Tetto orario unico delle email del plugin (Impostazioni → Limiti e soglie → «Email del gestionale in un'ora», predefinito 200).
 *
 * Perché: molti hosting condivisi permettono 100-300 email all'ora e oltre bloccano la posta del sito, anche quella delle conferme. Senza un tetto, una
 * comunicazione a molti soci (che da sola parte a 1.500 all'ora) lascia senza posta tutto il resto.
 *
 * Cosa conta: ogni indirizzo di destinatario di ogni email spedita dal plugin (comunicazioni, promemoria, avvisi, conferme, ricevute, link di accesso,
 * messaggi alla segreteria), qualunque sia l'esito. Non conta la posta di WordPress e degli altri plugin. Il conteggio è per ora piena (:00-:59),
 * si ricorda in un'opzione e si scrive a fine richiesta; due richieste insieme possono superare il tetto di pochi messaggi: è un freno, non un contatore bancario.
 *
 * Cosa succede oltre il tetto:
 * - comunicazioni a gruppi e promemoria automatici (hanno già una coda o un nuovo tentativo): usano solo la parte del tetto che non è riservata alle email singole
 *   (un quinto, almeno 5); finita quella si fermano e riprendono da soli all'ora piena dopo, senza perdere né ripetere nessuno;
 * - email singole (conferma di prenotazione o pagamento, ricevuta, link di accesso, primo accesso, avvisi alla segreteria, avvisi di un'attività): non si fermano
 *   mai e non si perdono; contano e, se superano il tetto, tolgono posto alle comunicazioni dell'ora dopo.
 */
final class MailBudget {

	const OPTION = 'asem_mail_budget';

	/** @var int|null ora piena a cui si riferisce il conteggio in memoria */
	private static $slot = null;
	/** @var int email contate in quest'ora (quelle già scritte più quelle di questa richiesta) */
	private static $used = 0;
	/** @var int email contate da questa richiesta e non ancora scritte */
	private static $added = 0;
	/** @var bool */
	private static $hooked = false;
	/** @var int|null solo per le prove: l'ora che si vuole simulare */
	public static $clock = null;

	private static function now(): int {
		return null !== self::$clock ? (int) self::$clock : time();
	}

	/** Il tetto scelto in Limiti. */
	public static function cap(): int {
		return max( 1, (int) apply_filters( 'asem_mail_per_hour', Limits::get( 'mail_per_hour' ) ) ); // il filtro serve a chi gestisce l'hosting e alle prove
	}

	private static function load(): void {
		$slot = MailBudgetRules::slot( self::now() );
		if ( self::$slot === $slot ) {
			return;
		}
		self::flush(); // scatta una nuova ora (o è la prima lettura): quanto annotato prima si scrive, poi si riparte da quello salvato per quest'ora
		$v            = get_option( self::OPTION, array() );
		self::$slot   = $slot;
		self::$added  = 0;
		self::$used   = is_array( $v ) && isset( $v['slot'], $v['n'] ) && (int) $v['slot'] === $slot ? max( 0, (int) $v['n'] ) : 0;
	}

	/** Email già contate in quest'ora. */
	public static function used(): int {
		self::load();
		return self::$used;
	}

	/** Annota le email spedite ($n = destinatari). */
	public static function count( int $n ): void {
		if ( $n < 1 ) {
			return;
		}
		self::load();
		self::$used  += $n;
		self::$added += $n;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 6 );
		}
	}

	/** Scrive il conteggio: parte dal valore salvato adesso (un'altra richiesta può averlo aumentato) e aggiunge quanto contato qui. */
	public static function flush(): void {
		self::$hooked = false;
		if ( self::$added < 1 || null === self::$slot ) {
			return;
		}
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$v     = get_option( self::OPTION, array() );
		$saved = is_array( $v ) && isset( $v['slot'], $v['n'] ) && (int) $v['slot'] === self::$slot ? max( 0, (int) $v['n'] ) : 0;
		$total = $saved + self::$added;
		update_option( self::OPTION, array( 'slot' => self::$slot, 'n' => $total ), false );
		self::$used  = $total;
		self::$added = 0;
	}

	/** Quante email di comunicazioni e promemoria possono ancora partire in quest'ora (la parte riservata alle email singole non si tocca). */
	public static function room_bulk(): int {
		return MailBudgetRules::bulk_room( self::cap(), self::used() );
	}

	/** Quando riprendono le comunicazioni fermate dal tetto: poco dopo l'inizio dell'ora piena successiva. */
	public static function resume_at(): int {
		return MailBudgetRules::next_slot_at( self::now() ) + 30;
	}

	/** Solo per le prove: azzera il conteggio (memoria e database). */
	public static function reset(): void {
		delete_option( self::OPTION );
		self::$slot   = null;
		self::$used   = 0;
		self::$added  = 0;
		self::$clock  = null;
	}

	/** Frase per le pagine delle comunicazioni quando il tetto ha fermato l'invio (stringa vuota se c'è posto). */
	public static function waiting_text(): string {
		if ( self::room_bulk() > 0 ) {
			return '';
		}
		return Texts::t( 'Il tetto di {n} email all\'ora è stato raggiunto: le comunicazioni in coda riprendono da sole alle {ora}.', array( 'n' => self::cap(), 'ora' => wp_date( 'H:i', self::resume_at() ) ) );
	}

	/**
	 * La posta del sito passa da un servizio esterno (SMTP di un fornitore, plugin di posta noto)? Si legge la configurazione di invio come la vedrebbe WordPress:
	 * è un lavoro da pagina Limiti, non da fare a ogni richiesta.
	 */
	public static function external_service(): bool {
		$mailer = '';
		$host   = '';
		try {
			if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) && is_readable( ABSPATH . WPINC . '/PHPMailer/PHPMailer.php' ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}
			if ( class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
				$pm = new \PHPMailer\PHPMailer\PHPMailer( true );
				do_action_ref_array( 'phpmailer_init', array( &$pm ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook di WordPress, non del plugin; gli stessi ganci che WordPress esegue prima di ogni invio: un plugin SMTP imposta qui il suo server
				$mailer = (string) $pm->Mailer;
				$host   = (string) $pm->Host;
			}
		} catch ( \Throwable $e ) {
			$mailer = '';
		}
		$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		}
		return MailBudgetRules::looks_external( $mailer, $host, (bool) has_filter( 'pre_wp_mail' ), $active );
	}
}
