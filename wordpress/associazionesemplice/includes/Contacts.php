<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Contatti della segreteria (email e telefono): obbligatori nella configurazione guidata e nei dati dell'ente.
 * Li leggono i soci nelle email (risposta e riga in calce), nell'informativa sulla privacy e nell'area soci.
 */
final class Contacts {

	const MAX_PHONE  = 30;
	const MIN_DIGITS = 6;

	public static function email(): string {
		$e = trim( (string) Settings::get( 'secretary_email' ) );
		return is_email( $e ) ? strtolower( $e ) : '';
	}

	public static function phone(): string {
		$p = trim( (string) Settings::get( 'secretary_phone' ) );
		return self::phone_valid( $p ) ? $p : '';
	}

	/** Telefono della segreteria: almeno 6 cifre, ammessi + - . ( ) e spazi, al massimo 30 caratteri. */
	public static function phone_valid( string $raw ): bool {
		$raw = trim( $raw );
		return '' !== $raw && mb_strlen( $raw ) <= self::MAX_PHONE && 1 === preg_match( '/^[0-9+\-.()\s]+$/', $raw ) && strlen( (string) preg_replace( '/\D+/', '', $raw ) ) >= self::MIN_DIGITS;
	}

	/** Ci sono sia l'email sia il telefono della segreteria (validi)? Unico punto di controllo: nessuna email viene bloccata se mancano (per ora). */
	public static function complete(): bool {
		return '' !== self::email() && '' !== self::phone();
	}

	/** Manca l'email o il telefono (o non sono validi)? */
	public static function missing(): bool {
		return ! self::complete();
	}

	/**
	 * Controlla i due campi.
	 *
	 * @return string[] un messaggio per ogni campo sbagliato
	 */
	public static function validate( string $email, string $phone ): array {
		$errors = array();
		$email  = trim( $email );
		$phone  = trim( $phone );
		if ( '' === $email ) {
			$errors[] = Texts::t( 'Indica l\'email della segreteria.' );
		} elseif ( ! is_email( $email ) ) {
			$errors[] = Texts::t( 'L\'email della segreteria non è un indirizzo valido.' );
		}
		if ( '' === $phone ) {
			$errors[] = Texts::t( 'Indica il telefono della segreteria.' );
		} elseif ( ! self::phone_valid( $phone ) ) {
			$errors[] = Texts::t( 'Indica un telefono valido per la segreteria (almeno 6 cifre; ammessi + - . ( ) e spazi).' );
		}
		return $errors;
	}

	/** «Segreteria: email · telefono», solo con ciò che c'è; vuota se non c'è nulla. */
	public static function line(): string {
		$e = self::email();
		$p = self::phone();
		if ( '' !== $e && '' !== $p ) {
			return Texts::t( 'Segreteria: {email} · {telefono}', array( 'email' => $e, 'telefono' => $p ) );
		}
		if ( '' !== $e || '' !== $p ) {
			return Texts::t( 'Segreteria: {contatto}', array( 'contatto' => '' !== $e ? $e : $p ) );
		}
		return '';
	}

	/** «Per esercitare i tuoi diritti scrivi a … o chiama …», con ciò che c'è; vuota se non c'è nulla. */
	public static function rights_line(): string {
		$e = self::email();
		$p = self::phone();
		if ( '' !== $e && '' !== $p ) {
			return Texts::t( 'Per esercitare i tuoi diritti scrivi a {email} o chiama {telefono}.', array( 'email' => $e, 'telefono' => $p ) );
		}
		if ( '' !== $e ) {
			return Texts::t( 'Per esercitare i tuoi diritti scrivi a {email}.', array( 'email' => $e ) );
		}
		if ( '' !== $p ) {
			return Texts::t( 'Per esercitare i tuoi diritti chiama {telefono}.', array( 'telefono' => $p ) );
		}
		return '';
	}

	/** Intestazione Reply-To per le email ai soci (vuota se manca l'email). */
	public static function reply_to_header(): string {
		$e = self::email();
		return '' !== $e ? 'Reply-To: ' . $e : '';
	}

	/** Testo del promemoria: per chi modifica le impostazioni e per gli altri. */
	public static function reminder_text( bool $can_edit ): string {
		return $can_edit
			? Texts::t( 'Mancano i contatti della segreteria (email e telefono): finché mancano, l\'associazione non scrive ai soci (comunicazioni e ricevute inviate a mano). Li vedono anche nell\'informativa sulla privacy.' )
			: Texts::t( 'Mancano i contatti della segreteria: chiedi a chi amministra di inserirli. Finché mancano, l\'associazione non scrive ai soci.' );
	}

	/**
	 * Punto di controllo degli invii MANUALI (comunicazioni, avvisi, ricevute inviate dall'operatore): senza contatti completi non si scrive ai soci.
	 * Le email automatiche (accessi, conferme, promemoria, ricevuta del pagamento online) partono comunque e non passano da qui.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_can_write( bool $receipt = false ): void {
		if ( self::complete() ) {
			return;
		}
		$msg = Texts::t( 'Prima inserisci email e telefono della segreteria (Impostazioni › Ente): senza, l\'associazione non scrive ai soci.' );
		if ( $receipt ) {
			$msg .= ' ' . Texts::t( 'Intanto scarica la ricevuta e consegnala a mano.' );
		}
		throw new \InvalidArgumentException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
	}

	/**
	 * Promemoria fisso (non si chiude) finché manca un contatto; vuoto se non serve o se chi guarda non usa il gestionale.
	 *
	 * @param bool $button con il pulsante «Inseriscili ora» (solo per chi può modificare le impostazioni)
	 */
	public static function reminder_html( bool $button = true ): string {
		if ( ! self::missing() ) {
			return '';
		}
		$can = current_user_can( Plugin::CAP );
		if ( ! $can && ! current_user_can( Plugin::CAP_OPS ) ) {
			return '';
		}
		$html = '<div class="notice notice-warning inline asem-contacts-reminder"><p>' . esc_html( self::reminder_text( $can ) );
		if ( $can && $button ) {
			$html .= ' <a class="button" href="' . esc_url( Admin\Ui::url( 'asem-entity' ) . '#asem-contacts' ) . '">' . esc_html( Texts::t( 'Inseriscili ora' ) ) . '</a>';
		}
		return $html . '</p></div>';
	}

}
