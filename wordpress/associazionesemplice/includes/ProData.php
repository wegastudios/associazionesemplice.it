<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati delle funzioni aggiuntive nei punti di estensione generici di Associazione Semplice: esportazione e anonimizzazione della privacy,
 * eliminazione di eventi e iscrizioni, pagamenti online in corso.
 */
final class ProData {

	public static function register(): void {
		add_filter( 'asem_privacy_export', array( __CLASS__, 'export' ), 10, 2 );
		add_action( 'asem_person_anonymized', array( __CLASS__, 'anonymize' ), 10, 3 );
		add_filter( 'asem_online_payment_pending', array( __CLASS__, 'online_pending' ), 10, 3 );
		add_filter( 'asem_activity_delete_blockers', array( __CLASS__, 'delete_blockers' ), 10, 2 );
		add_action( 'asem_activity_deleted', array( __CLASS__, 'activity_deleted' ) );
		add_action( 'asem_registration_deleted', array( __CLASS__, 'registration_deleted' ), 10, 2 );
		add_filter( 'asem_activity_saved', array( __CLASS__, 'activity_saved' ), 10, 3 );
		add_filter( 'asem_activity_errors', array( __CLASS__, 'activity_errors' ), 10, 3 );
		add_filter( 'asem_session_errors', array( __CLASS__, 'session_errors' ), 10, 3 );
		add_filter( 'asem_wizard_next', array( __CLASS__, 'wizard_next' ), 10, 2 );
		add_filter( 'asem_activity_form_rows', array( __CLASS__, 'activity_form_rows' ), 10, 2 );
		add_filter( 'asem_entity_values', array( __CLASS__, 'entity_values' ), 10, 3 );
		add_filter( 'asem_guide_options', array( __CLASS__, 'guide_options' ) );
		add_filter( 'asem_scheduled_jobs', array( __CLASS__, 'jobs' ) );
		add_filter( 'asem_admin_post_classes', array( __CLASS__, 'admin_post_classes' ) );
		add_filter( 'asem_payments_service', array( __CLASS__, 'payments_service' ) );
		add_action( 'asem_rest_routes', array( __CLASS__, 'rest_routes' ) );
		add_filter( 'asem_privacy_uses', array( __CLASS__, 'privacy_uses' ) );
		add_filter( 'asem_privacy_processors', array( __CLASS__, 'privacy_processors' ), 10, 2 );
		add_filter( 'asem_privacy_transfer_note', array( __CLASS__, 'privacy_transfer_note' ), 10, 2 );
		add_filter( 'asem_text_plain', array( TextsEngine::class, 'plain' ), 10, 2 );
		add_filter( 'asem_text_html', array( TextsEngine::class, 'html' ), 10, 2 );
		add_action( 'asem_texts_flush', array( __CLASS__, 'texts_flush' ) );
		add_action( 'asem_texts_admin_buffer', array( TextsEngine::class, 'start_admin_buffer' ) );
		add_action( 'asem_notify_email', array( __CLASS__, 'notify_email' ), 10, 3 );
		add_filter( 'asem_online_dues', array( __CLASS__, 'online_dues' ), 10, 2 );
		add_filter( 'asem_reservation_active', array( __CLASS__, 'reservation_active' ), 10, 2 );
		add_action( 'asem_reservation_receipt', array( __CLASS__, 'reservation_receipt' ), 10, 4 );
		add_filter( 'asem_vat_row', array( __CLASS__, 'vat_row' ), 10, 5 );
		add_filter( 'asem_vat_input', array( __CLASS__, 'vat_input' ), 10, 2 );
	}

	/** Assicurazioni e presenze della persona nell'esportazione dei suoi dati. */
	public static function export( array $data, int $person_id ): array {
		$db = Db::db();
		$q  = function ( string $sql, array $args ) use ( $db ) {
			return $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();
		};
		$data['assicurazioni'] = $q( 'SELECT company AS compagnia, policy_no AS numero, valid_from AS dal, valid_to AS al FROM ' . Db::t( 'insurance' ) . ' WHERE person_id = %d ORDER BY valid_from', array( $person_id ) );
		$data['presenze']      = $q( 'SELECT a.name AS attivita, t.lesson_date AS data, t.present AS presente FROM ' . Db::t( 'attendance' ) . ' t JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id WHERE t.person_id = %d ORDER BY t.lesson_date', array( $person_id ) );
		return $data;
	}

	/** Anonimizzazione: polizze, destinatari delle comunicazioni, dispositivi delle notifiche e nome nelle voci dei pagamenti online. */
	public static function anonymize( int $person_id, int $uid, string $name = '' ): void {
		$db = Db::db();
		$db->delete( Db::t( 'insurance' ), array( 'person_id' => $person_id ) ); // numeri di polizza e note
		$db->update( Db::t( 'broadcast_rcpt' ), array( 'email' => '', 'name' => 'Persona anonimizzata' ), array( 'person_id' => $person_id ) ); // destinatari delle comunicazioni
		if ( $uid > 0 ) {
			$db->delete( Db::t( 'push_subs' ), array( 'user_id' => $uid ) ); // dispositivi con le notifiche
		}
		// pagamenti online: il nome scritto in ogni voce
		$pay = Db::t( 'payments' );
		foreach ( $db->get_results( $db->prepare( "SELECT id, items FROM $pay WHERE payer_person_id = %d OR items LIKE %s", $person_id, '%"person_id":' . $person_id . '%' ), ARRAY_A ) ?: array() as $r ) {
			$items = json_decode( (string) $r['items'], true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$changed = false;
			foreach ( $items as $k => $i ) {
				if ( is_array( $i ) && (int) ( $i['person_id'] ?? 0 ) === $person_id && isset( $i['person_name'] ) ) {
					$items[ $k ]['person_name'] = 'Persona anonimizzata';
					$changed                    = true;
				}
			}
			if ( $changed ) {
				$db->update( $pay, array( 'items' => wp_json_encode( $items ) ), array( 'id' => (int) $r['id'] ) );
			}
		}
	}

	/** Ha un pagamento online avviato da poco per questa prenotazione? */
	public static function online_pending( $pending, int $person_id, int $session_id ) {
		if ( $pending ) {
			return $pending;
		}
		$db    = Db::db();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( $db->get_results( $db->prepare( 'SELECT items FROM ' . Db::t( 'payments' ) . " WHERE payer_person_id = %d AND status IN ('created','pending') AND updated_at >= %s", $person_id, $since ), ARRAY_A ) ?: array() as $p ) {
			foreach ( (array) json_decode( (string) $p['items'], true ) as $it ) {
				if ( (int) ( $it['session_id'] ?? 0 ) === $session_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Un pagamento online in corso per l'evento ne impedisce l'eliminazione. */
	public static function delete_blockers( array $out, int $activity_id ): array {
		$db    = Db::db();
		$since = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		foreach ( $db->get_results( $db->prepare( 'SELECT id, items FROM ' . Db::t( 'payments' ) . " WHERE status IN ('created','pending') AND updated_at >= %s", $since ), ARRAY_A ) ?: array() as $pay ) {
			foreach ( (array) json_decode( (string) $pay['items'], true ) as $it ) {
				if ( (int) ( $it['activity_id'] ?? 0 ) === $activity_id ) {
					$out[] = 'C\'è un pagamento online in corso per questo evento: attendi che si concluda (o scada) prima di eliminarlo.';
					break 2;
				}
			}
		}
		return $out;
	}

	/** Eliminato un evento: presenze e collegamenti con WooCommerce. */
	public static function activity_deleted( int $activity_id ): void {
		$db = Db::db();
		$db->delete( Db::t( 'attendance' ), array( 'activity_id' => $activity_id ) );
		$db->delete( Db::t( 'woo_links' ), array( 'kind' => 'activity', 'ref_id' => $activity_id ) );
	}

	/** Salvata un'attività: crediti previsti e risorsa collegata (se ci sono prenotazioni già presenti sulla risorsa, un avviso). */
	public static function activity_saved( array $notes, int $activity_id, array $p ): array {
		if ( Edition::has( 'credits' ) && array_key_exists( 'credit_price', $p ) ) { // crediti previsti per l'attività (vuoto = non si paga in crediti)
			Credits::set_activity_price( $activity_id, (int) $p['credit_price'] );
		}
		$res_on = Edition::has( 'resources' ) && ( array_key_exists( 'resource_id', $p ) || array_key_exists( 'setup_before', $p ) || array_key_exists( 'setup_after', $p ) );
		if ( $res_on ) { // com'era prima: serve per distinguere le sovrapposizioni nuove da quelle già presenti e per tornare indietro
			$old_link  = Resources::activity_link( $activity_id );
			$old_setup = Db::db()->get_row( Db::db()->prepare( 'SELECT setup_before_min, setup_after_min FROM ' . Db::t( 'activities' ) . ' WHERE id = %d', $activity_id ), ARRAY_A ) ?: array( 'setup_before_min' => null, 'setup_after_min' => null );
			$old_over  = $old_link ? Resources::activity_overlaps( $activity_id, $old_link ) : array();
		}
		if ( Edition::has( 'resources' ) && ( array_key_exists( 'setup_before', $p ) || array_key_exists( 'setup_after', $p ) ) ) { // allestimento prima e dopo, prima del collegamento: il controllo dei conflitti lo usa
			Resources::set_activity_setup( $activity_id, ResourceRules::parse_duration( (string) ( $p['setup_before'] ?? '' ) ), ResourceRules::parse_duration( (string) ( $p['setup_after'] ?? '' ) ) );
		}
		if ( Edition::has( 'resources' ) && array_key_exists( 'resource_id', $p ) ) {
			$raw = trim( (string) $p['resource_id'] ); // vuoto = non indicata, «none» = nessuna risorsa (evento fuori sede)
			Resources::link_activity( $activity_id, 'none' === $raw ? 0 : ( '' === $raw ? null : (int) $raw ) );
		}
		if ( $res_on ) {
			$link = Resources::activity_link( $activity_id );
			$over = $link ? Resources::activity_overlaps( $activity_id, $link ) : array();
			$new  = array_diff_key( $over, $old_over );
			if ( $new ) { // niente sovrapposizioni nuove: risorsa e allestimento tornano com'erano
				Resources::set_activity_setup( $activity_id, null === $old_setup['setup_before_min'] ? null : (int) $old_setup['setup_before_min'], null === $old_setup['setup_after_min'] ? null : (int) $old_setup['setup_after_min'] );
				Resources::link_activity( $activity_id, $old_link );
				throw new \InvalidArgumentException( esc_html( 'Non posso salvare: la risorsa risulterebbe occupata due volte. Si sovrappone a ' . implode( '; ', array_slice( array_values( $new ), 0, 5 ) ) . '. Risorsa e allestimento sono rimasti come prima (gli altri dati sono stati salvati): cambia orari, risorsa o allestimento.' ) );
			}
			if ( $over ) { // quelle già presenti non si toccano: si segnalano e vanno sistemate a mano
				$notes[] = ' Attenzione: restano ' . count( $over ) . ( 1 === count( $over ) ? ' sovrapposizione già presente' : ' sovrapposizioni già presenti' ) . ' sulla risorsa (prossimi 120 giorni): vanno sistemate a mano.';
			}
		}
		return $notes;
	}

	/**
	 * Prima di salvare un'attività: un evento deve dire quale risorsa occupa (o che si tiene fuori sede), se ci sono risorse a fasce; i tempi di
	 * allestimento devono essere scritti in modo comprensibile.
	 *
	 * @param string[] $errors
	 * @return string[]
	 */
	public static function activity_errors( array $errors, string $kind, array $p ): array {
		if ( ! Edition::has( 'resources' ) || ! array_key_exists( 'resource_id', $p ) ) {
			return $errors;
		}
		if ( in_array( $kind, array( 'event', 'recurring' ), true ) && '' === trim( (string) $p['resource_id'] ) ) {
			foreach ( Resources::all() as $r ) {
				if ( ResourceRules::SLOT === $r['mode'] ) {
					$errors[] = 'Indica quale risorsa occupa l\'evento, oppure «Nessuna risorsa (evento fuori sede)».';
					break;
				}
			}
		}
		foreach ( array( 'setup_before', 'setup_after' ) as $k ) {
			if ( isset( $p[ $k ] ) ) {
				try {
					ResourceRules::parse_duration( (string) $p[ $k ] );
				} catch ( \InvalidArgumentException $e ) {
					$errors[] = 'Allestimento: ' . $e->getMessage();
					break;
				}
			}
		}
		return $errors;
	}

	/** Date nuove o spostate di un corso o evento: non possono sovrapporsi (allestimenti compresi) a prenotazioni o ad altre attività sulla risorsa. */
	public static function session_errors( array $errors, int $activity_id, array $dates ): array {
		if ( ! Edition::has( 'resources' ) || ! $dates ) {
			return $errors;
		}
		$over = Resources::dates_overlaps( $activity_id, $dates );
		if ( $over ) {
			$errors[] = 'Le date si sovrappongono (allestimenti compresi) a ' . implode( '; ', array_slice( $over, 0, 5 ) ) . ': scegli altri giorni o orari.';
		}
		return $errors;
	}

	/** Lavori periodici: risorse, comunicazioni e controllo dei pagamenti online. */
	public static function jobs( array $jobs ): array {
		return $jobs + array(
			'asem_release_resources'      => array( 'hourly', 1000, 'Resources', 'release_unpaid_job', 'resources' ),
			'asem_resource_reminders'     => array( 'hourly', 1500, 'Resources', 'reminders_job', 'resources' ),
			'asem_broadcast_batch'        => array( '', 0, 'Broadcasts', 'process_all', 'broadcasts' ),
			'asem_check_pending_payments' => array( 'hourly', 300, 'PaymentService', 'check_pending_job', 'payments' ),
		);
	}

	/** Azioni di admin-post gestite da classi caricate al bisogno. */
	public static function admin_post_classes( array $map ): array {
		return $map + array(
			'asem_receipt'      => 'Receipts',
			'asem_statement'    => 'Receipts',
			'asem_export_texts' => 'TextsEngine',
			'asem_wallet_apple' => 'Wallet',
		);
	}

	/** Servizi in uso che comportano altri destinatari dei dati: pagamenti online, Wallet, notifiche. */
	public static function privacy_uses( array $uses ): array {
		return $uses + array(
			'payments' => Edition::has( 'payments' ) && PaymentConfig::NONE !== (string) Settings::get( 'payment_provider' ),
			'wallet'   => Edition::has( 'wallet' ) && ! empty( Settings::get( 'wallet_enabled' ) ),
			'push'     => Edition::has( 'pwa' ) && ! empty( Settings::get( 'push_enabled' ) ),
		);
	}

	/** Fornitori da citare nell'informativa. */
	public static function privacy_processors( array $list, array $u ): array {
		if ( ! empty( $u['payments'] ) ) {
			$list[] = 'i gestori dei servizi di pagamento online scelti dall\'Associazione (ad esempio Stripe, PayPal o il negozio del sito): i dati della carta sono trattati direttamente da loro e non passano dal sito;';
		}
		if ( ! empty( $u['wallet'] ) ) {
			$list[] = 'Apple e Google, se si sceglie di salvare la tessera nel telefono (Apple Wallet, Google Wallet);';
		}
		if ( ! empty( $u['push'] ) ) {
			$list[] = 'i servizi di notifica dei browser e dei sistemi operativi, se si attivano le notifiche sul telefono;';
		}
		return $list;
	}

	/** Nota sul trasferimento dei dati fuori dall'UE. */
	public static function privacy_transfer_note( string $note, array $u ): string {
		if ( ! empty( $u['payments'] ) || ! empty( $u['wallet'] ) || ! empty( $u['push'] ) ) {
			return 'Alcuni dei servizi indicati (pagamenti, Wallet, notifiche) possono comportare il trasferimento di dati verso Paesi extra UE: avviene solo verso soggetti che offrono garanzie adeguate ai sensi degli artt. 44 e seguenti del GDPR (decisioni di adeguatezza o clausole contrattuali standard).';
		}
		return $note;
	}

	/** Dopo un salvataggio dei testi: rilegge dal database. */
	public static function texts_flush(): void {
		TextsEngine::flush();
		Terms::flush();
	}

	/** Notifica push a chi ha l'indirizzo email indicato (se ha l'app). */
	public static function notify_email( string $email, string $title, string $body ): void {
		if ( Edition::has( 'pwa' ) ) {
			Push::notify_email( $email, $title, $body );
		}
	}

	/** Quote di prenotazioni di risorse ancora da pagare online. */
	public static function online_dues( array $items, $actor ): array {
		return Edition::has( 'resources' ) ? array_merge( $items, Resources::online_dues( $actor ) ) : $items;
	}

	/** Una prenotazione di risorsa è ancora attiva? */
	public static function reservation_active( $active, int $id ) {
		return Edition::has( 'resources' ) && Resources::is_active( $id );
	}

	/** Incasso di una prenotazione registrato in prima nota: la quota della risorsa va nel suo fondo (se prevista). */
	public static function reservation_receipt( int $tx_id, int $reservation_id, int $cents, string $date ): void {
		if ( Edition::has( 'resources' ) ) {
			Resources::share_to_fund( $reservation_id, $tx_id, $cents, $date );
		}
	}

	/** Rotte REST dei pagamenti online. */
	public static function rest_routes( string $ns ): void {
		if ( Edition::installed( 'payments' ) ) {
			register_rest_route( $ns, '/webhooks/stripe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'stripe_webhook' ), 'permission_callback' => '__return_true' ) );
		}
	}

	/** Webhook di Stripe: pubblico ma accettato solo con firma valida (segreto del webhook impostato nel pannello). */
	public static function stripe_webhook( \WP_REST_Request $r ) {
		$payload = (string) $r->get_body();
		$secret  = Settings::secret( 'stripe_webhook_secret' );
		if ( ! StripeWebhook::verify( $payload, (string) $r->get_header( 'stripe-signature' ), $secret, time() ) ) {
			return new \WP_Error( 'asem_bad_signature', 'Firma non valida.', array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'asem_bad_payload', 'Contenuto non valido.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'received' => true, 'result' => PaymentService::engine()->handle_stripe_event( $event ) ) );
	}

	/** Il servizio dei pagamenti online. */
	public static function payments_service( $offline ) {
		return PaymentService::engine();
	}

	/** Funzioni facoltative della guida iniziale: pagamenti online, Wallet, assicurazioni, 5x1000, lingua. */
	public static function guide_options( array $list ): array {
		$out = array();
		if ( Edition::has( 'payments' ) ) {
			$out[] = array( 'title' => 'Pagamenti online', 'on' => PaymentConfig::NONE !== (string) Settings::get( 'payment_provider' ), 'page' => 'asem-payments', 'hint' => 'Carta, PayPal e altri metodi dall\'area soci.' );
		}
		foreach ( $list as $o ) {
			if ( 'asem-card' === $o['page'] && Edition::has( 'wallet' ) ) {
				$o['title'] = 'Tessera con QR e Wallet';
			}
			$out[] = $o;
			if ( 'asem-comms' === $o['page'] && 'Regolamento da accettare' === $o['title'] ) {
				if ( Edition::has( 'insurance' ) ) {
					$out[] = array( 'title' => 'Assicurazioni', 'on' => ProSettings::insurance_volunteers() || ProSettings::insurance_association(), 'page' => 'asem-volunteers', 'hint' => 'Polizze dell\'associazione e dei volontari, con le scadenze.' );
				}
				if ( Edition::has( 'fivepm' ) ) {
					$out[] = array( 'title' => '5x1000', 'on' => FivePerMille::enabled(), 'page' => 'asem-fivepm', 'hint' => 'Messaggio con il codice fiscale e rendiconto dei contributi.' );
				}
				if ( Edition::has( 'texts' ) ) {
					$out[] = array( 'title' => 'Lingua del sito', 'on' => Languages::current() !== Languages::DEFAULT_CODE, 'page' => 'asem-texts', 'hint' => 'Testi in un\'altra lingua, con i pacchetti di traduzione.' );
				}
			}
		}
		return $out;
	}

	/** Campi dell'IVA nei moduli di incasso e spesa. */
	public static function vat_row( $html, $rate, string $mode, string $what, bool $auto ) {
		return Edition::has( 'vat' ) ? Admin\VatFields::row( $rate, $mode, $what, $auto ) : $html;
	}

	/** Aliquota e modo letti dal modulo. */
	public static function vat_input( array $default, array $p ): array {
		return Edition::has( 'vat' ) ? Admin\VatFields::input( $p ) : $default;
	}

	/** Righe in più nel modulo dell'attività: risorsa collegata e prezzo in crediti. */
	public static function activity_form_rows( array $rows, ?int $activity_id ): array {
		if ( Edition::has( 'resources' ) ) {
			$rows[] = Admin\ResourcesPage::activity_row( $activity_id );
		}
		if ( Edition::has( 'credits' ) ) {
			$rows[] = Admin\CreditsPage::activity_row( $activity_id );
		}
		return $rows;
	}

	/** Dopo la configurazione guidata: con Stripe o PayPal si passa a inserire le chiavi. */
	public static function wizard_next( array $step, array $p ): array {
		$prov = (string) Settings::get( 'payment_provider' );
		if ( in_array( $prov, array( PaymentConfig::STRIPE, PaymentConfig::PAYPAL, PaymentConfig::BOTH ), true ) ) {
			return array( Admin\Ui::url( 'asem-payments' ), ' Ora inserisci le chiavi del fornitore scelto.' );
		}
		return $step;
	}

	/** Cancellata un'iscrizione a un corso: le sue presenze. */
	public static function registration_deleted( int $activity_id, int $person_id ): void {
		Db::db()->delete( Db::t( 'attendance' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
	}

	/** Dati dell'ente delle funzioni aggiuntive: tipo di ente e termini, partita IVA e dati fiscali. */
	public static function entity_values( array $vals, array $p, bool $full ): array {
		$txt = function ( string $k ) use ( $p ): string {
			return sanitize_text_field( (string) ( $p[ $k ] ?? '' ) );
		};
		$has = function ( string $k ) use ( $p, $full ): bool {
			return $full || array_key_exists( $k, $p );
		};
		if ( Edition::has( 'texts' ) && ( $has( 'entity_type' ) || $has( 'member_term' ) ) ) { // tipo di ente e termini: funzione di Associazione Semplice Pro
			$ent = mb_strtolower( trim( $txt( 'entity_type' ) ), 'UTF-8' );
			$mem = mb_strtolower( trim( $txt( 'member_term' ) ), 'UTF-8' );
			if ( ! isset( Terms::entity_types( (string) Settings::get( 'entity_types_custom' ) )[ $ent ] ) ) {
				throw new \InvalidArgumentException( 'Scegli un tipo di ente dall\'elenco.' );
			}
			if ( ! isset( Terms::member_terms( (string) Settings::get( 'member_terms_custom' ) )[ $mem ] ) ) {
				throw new \InvalidArgumentException( 'Scegli un termine per chi partecipa dall\'elenco.' );
			}
			$vals['entity_type'] = $ent;
			$vals['member_term'] = $mem;
		}
		if ( $has( 'has_vat' ) ) { // la partita IVA e tutto ciò che ne dipende si legge insieme
			$on = ! empty( $p['has_vat'] );
			$vat = Fiscal::normalize_vat( $txt( 'vat_number' ) );
			if ( $on && ! Fiscal::is_valid_vat( $vat ) ) {
				throw new \InvalidArgumentException( 'La partita IVA non è valida: sono 11 cifre, l\'ultima è di controllo.' );
			}
			$vals['has_vat']             = $on ? 1 : 0;
			$vals['vat_number']          = $on ? $vat : '';
			$vals['fiscal_regime']       = $txt( 'fiscal_regime' );
			$vals['vat_default_rate']    = (int) ( $p['vat_default_rate'] ?? 22 );
			$vals['vat_prices_mode']     = $txt( 'vat_prices_mode' );
			$vals['vat_membership_rate'] = $on ? ( $p['vat_membership_rate'] ?? '' ) : '';
			$vals['sdi_code']            = $txt( 'sdi_code' );
		}
		return $vals;
	}
}
