<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Raccolta di donazioni, aperta anche a chi non è socio. Con un metodo di pagamento attivo (Stripe, PayPal, SumUp, Satispay) la donazione passa dal
 * pagamento del sito: a pagamento confermato si registra da sola in prima nota come «Erogazione liberale», con la sua ricevuta.
 * Senza un metodo attivo resta il pulsante «Dona» di PayPal (paypal.com/donate, già compilato con conto, causale e importo): il pagamento avviene tutto
 * su PayPal e non lascia traccia nel gestionale (nessun webhook né IPN): si registra a mano in prima nota («Nuovo incasso», categoria «Erogazione liberale»).
 */
final class Donations {

	const URL         = 'https://www.paypal.com/donate';
	const MAX_AMOUNTS = 8;
	const MAX_CENTS   = 1000000; // 10.000 € per importo proposto

	/** Email PayPal oppure ID commerciante (13 lettere e cifre maiuscole). Altro => vuoto. */
	public static function clean_account( string $v ): string {
		$v = trim( $v );
		if ( preg_match( '/^[A-Z0-9]{13}$/', $v ) ) {
			return $v;
		}
		return is_email( $v ) ? strtolower( mb_substr( $v, 0, 190 ) ) : '';
	}

	/** «5; 10 20;7,50» => «5;10;20;7,50» (euro interi o con i decimali, in ordine, senza doppioni, al massimo 8). */
	public static function clean_amounts( string $v ): string {
		$out = array();
		foreach ( preg_split( '/[;\s]+/', trim( $v ) ) ?: array() as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$cents = Money::parse( $p );
			if ( null === $cents || $cents <= 0 || $cents > self::MAX_CENTS ) {
				continue;
			}
			$out[ $cents ] = 0 === $cents % 100 ? (string) intdiv( $cents, 100 ) : Money::plain( $cents ); // «5» e «7,50»
		}
		ksort( $out );
		return implode( ';', array_slice( array_values( $out ), 0, self::MAX_AMOUNTS ) );
	}

	/** @return string[] importi proposti, in euro, come testo («5», «7,50») */
	public static function amounts(): array {
		$s = self::clean_amounts( (string) Settings::get( 'donate_amounts' ) );
		return '' === $s ? array() : explode( ';', $s );
	}

	public static function account(): string {
		return self::clean_account( (string) Settings::get( 'donate_paypal' ) );
	}

	/** Il conto PayPal delle donazioni senza mostrarlo per intero: «ma••••@dominio.it» o «••••1234». */
	public static function mask_account( string $account ): string {
		$account = trim( $account );
		if ( '' === $account ) {
			return '';
		}
		$at = strrpos( $account, '@' );
		if ( false !== $at && $at > 0 ) {
			return substr( $account, 0, min( 2, $at ) ) . '••••' . substr( $account, $at );
		}
		return '••••' . substr( $account, -4 );
	}

	/** Il conto che riceve le donazioni è cambiato? (Dopo la pulizia, senza distinguere maiuscole.) */
	public static function account_changed( string $old, string $new ): bool {
		return strtolower( self::clean_account( $old ) ) !== strtolower( self::clean_account( $new ) );
	}

	/** Registra il cambio del conto delle donazioni e avvisa per email gli amministratori. */
	public static function notify_change( string $old, string $new ): void {
		if ( ! self::account_changed( $old, $new ) ) {
			return;
		}
		$was = self::mask_account( self::clean_account( $old ) );
		$now = self::mask_account( self::clean_account( $new ) );
		Audit::log( 'donate.account_changed', 'settings', 0, array( 'prima' => $was, 'dopo' => $now ) );
		$text = 'Il conto PayPal che riceve le donazioni è stato modificato da ' . Bank::acting_user() . ".\n\nPrima: " . ( '' !== $was ? $was : 'nessuno' ) . "\nDopo: " . ( '' !== $now ? $now : 'nessuno' )
			. "\n\nSe non l'hai fatto tu, cambia subito le password degli amministratori e controlla le impostazioni delle donazioni.";
		foreach ( Bank::admin_recipients() as $addr ) {
			Texts::mail( $addr, Texts::subject( 'Modificato il conto PayPal delle donazioni' ), $text );
		}
	}

	/**
	 * Come si dona: «checkout» = il pagamento del sito (Stripe, PayPal, SumUp o Satispay: la donazione si registra da sola in prima nota con la sua ricevuta);
	 * «paypal» = il pulsante «Dona» di PayPal, solo per chi non ha un metodo di pagamento attivo (niente movimento né ricevuta); vuoto = nessuna donazione.
	 */
	public static function mode(): string {
		if ( empty( Settings::get( 'donate_enabled' ) ) ) {
			return '';
		}
		if ( self::checkout_available() ) {
			return 'checkout';
		}
		return '' !== self::account() ? 'paypal' : '';
	}

	/** C'è un metodo di pagamento online con cui far donare dal sito? (lo dice la Pro: nella versione gratuita no) */
	public static function checkout_available(): bool {
		return (bool) apply_filters( 'asem_donation_checkout_available', false );
	}

	public static function enabled(): bool {
		return '' !== self::mode();
	}

	/** Causale: quella scelta, altrimenti «Donazione a <ente>». */
	public static function purpose(): string {
		$p = trim( (string) Settings::get( 'donate_purpose' ) );
		if ( '' !== $p ) {
			return $p;
		}
		$name = trim( (string) Settings::get( 'association_name' ) );
		return '' !== $name ? 'Donazione a ' . $name : 'Donazione';
	}

	/** Importo nel formato di PayPal (punto decimale) a partire da quello scritto in euro. */
	public static function paypal_amount( string $euro ): string {
		$cents = Money::parse( $euro );
		return null === $cents || $cents <= 0 ? '' : number_format( $cents / 100, 2, '.', '' );
	}

	// ---------- Campagne: ogni raccolta ha il suo shortcode ----------

	const OPT_CAMPAIGNS = 'asem_donate_campaigns';
	const OPT_NEXT_ID   = 'asem_donate_next_id';

	/** @return array<int,array{id:int,name:string,purpose:string,amounts:string,note:string,active:int}> raccolte salvate, per numero */
	public static function campaigns(): array {
		$all = get_option( self::OPT_CAMPAIGNS, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $c ) {
			if ( is_array( $c ) && ! empty( $c['id'] ) ) {
				$out[ (int) $c['id'] ] = array(
					'id'      => (int) $c['id'],
					'name'    => (string) ( $c['name'] ?? '' ),
					'purpose' => (string) ( $c['purpose'] ?? '' ),
					'amounts' => (string) ( $c['amounts'] ?? '' ),
					'note'    => (string) ( $c['note'] ?? '' ),
					'active'  => empty( $c['active'] ) ? 0 : 1,
				);
			}
		}
		ksort( $out );
		return $out;
	}

	public static function campaign( int $id ): ?array {
		return self::campaigns()[ $id ] ?? null;
	}

	/** Lo shortcode da incollare nel sito per una raccolta (0 = la raccolta generale). */
	public static function shortcode( int $id = 0 ): string {
		return $id > 0 ? '[associazionesemplice_donazioni id="' . $id . '"]' : '[associazionesemplice_donazioni]';
	}

	/**
	 * Crea (id = 0) o aggiorna una raccolta e ne restituisce il numero. Titolo, importi e testo sono facoltativi: senza, vale la raccolta generale.
	 *
	 * @param array $in name, purpose, amounts, note, active
	 */
	public static function save_campaign( int $id, array $in ): int {
		$name = mb_substr( trim( (string) ( $in['name'] ?? '' ) ), 0, 80 );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Dai un nome alla raccolta (serve a riconoscerla nell\'elenco).' );
		}
		$all = self::campaigns();
		if ( $id > 0 && ! isset( $all[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Raccolta non trovata.' );
		}
		if ( 0 === $id ) {
			$max = Limits::get( 'donate_max_campaigns' );
			if ( count( $all ) >= $max ) {
				throw new \InvalidArgumentException( 'Hai già ' . $max . ' raccolte: eliminane una o alza il limite in Impostazioni → Limiti e soglie.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno con un numero
			}
			$id = max( (int) get_option( self::OPT_NEXT_ID, 0 ), $all ? max( array_keys( $all ) ) : 0 ) + 1;
			update_option( self::OPT_NEXT_ID, $id, false ); // i numeri non si riusano: lo shortcode di una raccolta eliminata non ne indica un'altra
		}
		$all[ $id ] = array(
			'id'      => $id,
			'name'    => $name,
			'purpose' => mb_substr( trim( (string) ( $in['purpose'] ?? '' ) ), 0, 120 ),
			'amounts' => self::clean_amounts( (string) ( $in['amounts'] ?? '' ) ),
			'note'    => mb_substr( trim( (string) ( $in['note'] ?? '' ) ), 0, 400 ),
			'active'  => empty( $in['active'] ) ? 0 : 1,
		);
		update_option( self::OPT_CAMPAIGNS, array_values( $all ), false );
		return $id;
	}

	public static function delete_campaign( int $id ): void {
		$all = self::campaigns();
		unset( $all[ $id ] );
		update_option( self::OPT_CAMPAIGNS, array_values( $all ), false );
	}

	/** Titolo e causale su PayPal di una raccolta: quelli suoi, altrimenti il suo nome. */
	public static function campaign_purpose( array $c ): string {
		return '' !== trim( $c['purpose'] ) ? trim( $c['purpose'] ) : trim( $c['name'] );
	}

	/** @return string[] importi proposti per la raccolta: i suoi, altrimenti quelli generali */
	public static function campaign_amounts( array $c ): array {
		$s = self::clean_amounts( $c['amounts'] );
		return '' === $s ? self::amounts() : explode( ';', $s );
	}

	/** Avviso sempre uguale: le donazioni esistono solo con PayPal configurato. */
	public static function disclaimer(): string {
		return 'Le donazioni sono disponibili solo con la raccolta accesa e un metodo di pagamento attivo (Pagamenti online) oppure, senza, il conto PayPal dell\'associazione. Senza, gli shortcode non mostrano nulla ai visitatori.';
	}
}
