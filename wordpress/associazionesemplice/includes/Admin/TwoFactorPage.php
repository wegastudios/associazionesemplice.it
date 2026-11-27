<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Html;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\TwoFactor;
use AssociazioneSemplice\TwoFactorWp;

defined( 'ABSPATH' ) || exit;

/**
 * Verifica in due passaggi nel pannello: la pagina «Verifica in due passaggi» per il proprio utente WordPress (accensione con QR e primo codice,
 * codici di riserva, spegnimento e nuovi codici con un codice valido) e lo spegnimento da parte dell'amministratore dalla scheda della persona
 * o del dipendente (mai su sé stesso). Le azioni per sé valgono per chi lavora nel pannello; lo spegnimento per un altro solo per l'amministratore.
 */
final class TwoFactorPage {

	public static function register(): void {
		Actions::add_handler( 'asem_tfa_wp_start', array( __CLASS__, 'start' ), false );
		Actions::add_handler( 'asem_tfa_wp_confirm', array( __CLASS__, 'confirm' ), false );
		Actions::add_handler( 'asem_tfa_wp_cancel', array( __CLASS__, 'cancel' ), false );
		Actions::add_handler( 'asem_tfa_wp_off', array( __CLASS__, 'off' ), false );
		Actions::add_handler( 'asem_tfa_wp_regen', array( __CLASS__, 'regen' ), false );
		Actions::add_handler( 'asem_tfa_admin_off', array( __CLASS__, 'admin_off' ), true );
	}

	private static function me(): int {
		if ( '' !== TwoFactorWp::other_plugin() ) {
			throw new \InvalidArgumentException( 'La verifica in due passaggi degli utenti WordPress la gestisce un altro plugin.' );
		}
		return TwoFactor::user( get_current_user_id() );
	}

	private static function back(): string {
		return Ui::url( 'asem-tfa' );
	}

	private static function code( array $p ): string {
		return isset( $p['tfa_code'] ) && is_string( $p['tfa_code'] ) ? trim( $p['tfa_code'] ) : '';
	}

	public static function start( array $p ): array {
		TwoFactor::start_setup( self::me() );
		return array( self::back(), 'Inquadra il codice QR con l\'app e scrivi il codice che compare per confermare.' );
	}

	public static function confirm( array $p ): array {
		TwoFactor::confirm_setup( self::me(), self::code( $p ) );
		return array( self::back(), 'Verifica in due passaggi accesa. Salva ora i codici di riserva: non li vedrai più.' );
	}

	public static function cancel( array $p ): array {
		TwoFactor::cancel_setup( self::me() );
		return array( self::back(), 'Accensione annullata.' );
	}

	public static function off( array $p ): array {
		$u = wp_get_current_user();
		if ( TwoFactorWp::required_for_user( $u ) && ! TwoFactor::needs_reset( self::me() ) ) {
			throw new \InvalidArgumentException( TwoFactorWp::CANT_OFF ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		TwoFactor::turn_off( self::me(), self::code( $p ) );
		return array( self::back(), 'Verifica in due passaggi spenta.' );
	}

	public static function regen( array $p ): array {
		TwoFactor::regenerate_backup( self::me(), self::code( $p ) );
		return array( self::back(), 'Nuovi codici di riserva pronti: salvali ora, quelli vecchi non valgono più.' );
	}

	/**
	 * Lo spegnimento da parte dell'amministratore: «person_id» (la scheda di un socio con l'accesso proprio, oppure il suo utente WordPress) o
	 * «user_id» (un dipendente). Mai su sé stesso.
	 */
	public static function admin_off( array $p ): array {
		if ( ! current_user_can( Plugin::CAP ) ) {
			throw new \InvalidArgumentException( 'Solo l\'amministratore può spegnere la verifica in due passaggi di un\'altra persona.' );
		}
		$me  = get_current_user_id();
		$pid = (int) ( $p['person_id'] ?? 0 );
		$uid = (int) ( $p['user_id'] ?? 0 );
		$back = ! empty( $p['_back'] ) ? (string) $p['_back'] : Ui::url( 'asem' );
		if ( $pid > 0 ) {
			$person = Plugin::people()->get( $pid );
			if ( ! $person ) {
				throw new \InvalidArgumentException( 'Persona non trovata.' );
			}
			$uid = (int) ( $person['wp_user_id'] ?? 0 );
			if ( $uid > 0 && $uid === $me ) {
				throw new \InvalidArgumentException( 'Per te stesso usa la pagina «Verifica in due passaggi» del tuo profilo.' );
			}
			$done = TwoFactor::admin_off( $pid, $me, (string) $person['email'], (string) $person['first_name'] );
			if ( $uid > 0 ) {
				$u    = get_userdata( $uid );
				$done = TwoFactor::admin_off( TwoFactor::user( $uid ), $me, $done || ! $u ? '' : (string) $u->user_email, (string) $person['first_name'] ) || $done;
			}
		} else {
			$u = $uid > 0 ? get_userdata( $uid ) : false;
			if ( ! $u ) {
				throw new \InvalidArgumentException( 'Utente non trovato.' );
			}
			if ( $uid === $me ) {
				throw new \InvalidArgumentException( 'Per te stesso usa la pagina «Verifica in due passaggi» del tuo profilo.' );
			}
			$done = TwoFactor::admin_off( TwoFactor::user( $uid ), $me, (string) $u->user_email, (string) $u->first_name );
		}
		return array( $back, $done ? 'Verifica in due passaggi spenta. Abbiamo avvisato l\'interessato per email.' : 'La verifica in due passaggi era già spenta.' );
	}

	/** Pulsante «Spegni la verifica in due passaggi» per le schede (solo amministratore, mai per sé). $fields: person_id o user_id. */
	public static function admin_off_button( array $fields, string $back, int $target_user = 0 ): string {
		if ( ! current_user_can( Plugin::CAP ) || ( $target_user > 0 && $target_user === get_current_user_id() ) ) {
			return '';
		}
		return Ui::confirm_button( 'asem_tfa_admin_off', $back, $fields, 'Spegni la verifica in due passaggi', 'Spegnere la verifica in due passaggi? Gli mandiamo un\'email; se l\'associazione la richiede, la dovrà riaccendere al prossimo ingresso.' );
	}

	/** Riquadro della scheda della persona: stato della verifica e pulsante dell'amministratore. */
	public static function person_panel( array $p ): string {
		$pid = (int) $p['id'];
		$uid = (int) ( $p['wp_user_id'] ?? 0 );
		$on  = TwoFactor::is_on( $pid ) || ( $uid > 0 && TwoFactor::is_on( TwoFactor::user( $uid ) ) );
		if ( ! $on ) {
			return '';
		}
		return '<p><strong>Verifica in due passaggi</strong>: accesa. <span class="description">Se ha perso il telefono e i codici di riserva puoi spegnerla tu.</span> '
			. self::admin_off_button( array( 'person_id' => $pid ), Ui::url( 'asem-person', array( 'id' => $pid ) ), $uid ) . '</p>';
	}

	/** Riga della scheda del dipendente. */
	public static function user_panel( \WP_User $u, string $back ): string {
		if ( ! TwoFactor::is_on( TwoFactor::user( (int) $u->ID ) ) ) {
			return '';
		}
		return '<p><strong>Verifica in due passaggi</strong>: accesa. ' . self::admin_off_button( array( 'user_id' => (int) $u->ID ), $back, (int) $u->ID ) . '</p>';
	}

	private static function field( string $label ): string {
		return '<p><label>' . esc_html( $label ) . '<br><input type="text" name="tfa_code" required autocomplete="one-time-code" inputmode="numeric" maxlength="11" class="regular-text"></label></p>';
	}

	/** La pagina per il proprio utente. */
	public static function render(): void {
		Ui::header( 'Verifica in due passaggi' );
		echo wp_kses( self::body( wp_get_current_user() ), Html::allowed() );
		Ui::footer();
	}

	/** Contenuto della pagina (anche per le prove). */
	public static function body( \WP_User $u ): string {
		$other = TwoFactorWp::other_plugin();
		if ( '' !== $other ) {
			return '<p>' . esc_html( 'La verifica in due passaggi del tuo utente la gestisce il plugin «' . $other . '».' ) . '</p>';
		}
		$s     = TwoFactor::user( (int) $u->ID );
		$html  = '';
		$intro = '<p>Oltre alla password, per entrare ti chiediamo un codice che cambia ogni 30 secondi, da un\'app sul telefono (per esempio Google Authenticator, Microsoft Authenticator o Aegis). Così nessuno entra con la sola password. Le password per le applicazioni e XML-RPC non funzionano più per il tuo utente, perché non chiedono il codice.</p>';
		if ( TwoFactorWp::user_must_setup( $u ) ) {
			$html .= '<div class="notice notice-warning inline"><p><strong>L\'associazione richiede la verifica in due passaggi.</strong> Accendila per continuare a usare il pannello.</p></div>';
		}
		$codes = TwoFactor::take_new_codes( $s );
		if ( $codes ) {
			$list = '';
			foreach ( $codes as $c ) {
				$list .= '<li><code>' . esc_html( TwoFactor::show_backup( (string) $c ) ) . '</code></li>';
			}
			$html .= '<div class="notice notice-success inline"><p><strong>I tuoi codici di riserva.</strong> Scrivili o stampali e tienili in un posto sicuro: li vedi solo adesso. Se perdi il telefono, ognuno ti fa entrare una volta al posto del codice dell\'app.</p><ul>' . $list . '</ul></div>';
		}
		if ( TwoFactor::is_on( $s ) ) {
			if ( TwoFactor::needs_reset( $s ) ) {
				return $html . '<div class="notice notice-error inline"><p>' . esc_html( 'La verifica in due passaggi va riconfigurata: il codice dell\'app non si può più controllare. Spegnila con un codice di riserva (o chiedi all\'amministratore) e riaccendila.' ) . '</p></div>'
					. self::form( 'asem_tfa_wp_off', self::field( 'Codice di riserva' ), 'Spegni per riaccenderla' );
			}
			$html .= '<p>È <strong>accesa</strong>. Codici di riserva ancora da usare: <strong>' . (int) TwoFactor::backup_left( $s ) . '</strong>.</p>'
				. '<h2>Nuovi codici di riserva</h2><p class="description">I codici di riserva che hai adesso smettono di valere.</p>'
				. self::form( 'asem_tfa_wp_regen', self::field( 'Codice dell\'app o di riserva' ), 'Crea nuovi codici' );
			if ( ! TwoFactorWp::required_for_user( $u ) ) {
				$html .= '<h2>Spegni la verifica in due passaggi</h2>' . self::form( 'asem_tfa_wp_off', self::field( 'Codice dell\'app o di riserva' ), 'Spegni' );
			}
			return $html;
		}
		$secret = TwoFactor::pending_secret( $s );
		if ( '' !== $secret ) {
			$issuer = (string) \AssociazioneSemplice\Settings::get( 'association_name' );
			$issuer = '' !== $issuer ? $issuer : (string) get_bloginfo( 'name' );
			try {
				$qr = \AssociazioneSemplice\QrCode::svg( TwoFactor::otpauth_uri( $secret, $issuer, (string) $u->user_email ), 4, 'Codice QR per l\'app di autenticazione', false );
			} catch ( \InvalidArgumentException $e ) {
				$qr = '';
			}
			return $html . $intro . '<ol><li>Apri l\'app sul telefono e aggiungi un account inquadrando questo codice QR.</li><li>Se non puoi inquadrarlo, scegli «inserisci una chiave» e copia la chiave qui sotto.</li><li>Scrivi il codice di 6 cifre che compare nell\'app.</li></ol>'
				. ( '' !== $qr ? '<div style="max-width:220px">' . $qr . '</div>' : '' )
				. '<p>Chiave da copiare: <code>' . esc_html( TwoFactor::grouped( $secret ) ) . '</code></p>'
				. self::form( 'asem_tfa_wp_confirm', self::field( 'Codice di 6 cifre dell\'app' ), 'Conferma e accendi' )
				. self::form( 'asem_tfa_wp_cancel', '', 'Annulla' );
		}
		return $html . '<p>È <strong>spenta</strong>.</p>' . $intro . self::form( 'asem_tfa_wp_start', '', 'Accendi la verifica in due passaggi' );
	}

	private static function form( string $action, string $fields, string $button ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="_back" value="' . esc_url( self::back() ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false ) . $fields
			. '<p><button class="button button-primary">' . esc_html( $button ) . '</button></p></form>';
	}

	/** Nel profilo di WordPress: lo stato e il collegamento alla pagina. */
	public static function profile_box( $user ): void {
		if ( ! $user instanceof \WP_User || (int) $user->ID !== get_current_user_id() || ! current_user_can( Plugin::CAP_OPS ) || '' !== TwoFactorWp::other_plugin() ) {
			return;
		}
		$on = TwoFactor::is_on( TwoFactor::user( (int) $user->ID ) );
		echo '<h2 id="asem-tfa">Verifica in due passaggi</h2><p>' . ( $on ? 'È <strong>accesa</strong>.' : 'È <strong>spenta</strong>.' ) . ' <a class="button" href="' . esc_url( TwoFactorWp::setup_url() ) . '">' . esc_html( $on ? 'Gestisci' : 'Accendila' ) . '</a></p>';
	}
}
