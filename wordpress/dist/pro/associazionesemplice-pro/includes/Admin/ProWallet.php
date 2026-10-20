<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Wallet;
use AssociazioneSemplice\WalletCredentials;

defined( 'ABSPATH' ) || exit;

/** Tessera nel telefono (Apple Wallet e Google Wallet) nella pagina della tessera. */
final class ProWallet {

	public static function register(): void {
		add_action( 'asem_card_switches', array( __CLASS__, 'switches' ) );
		add_action( 'asem_card_page_end', array( __CLASS__, 'sections' ) );
		add_filter( 'asem_card_settings', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function save( array $vals, array $p ): array {
		$vals['wallet_enabled'] = ! empty( $p['wallet_enabled'] ) ? 1 : 0;
		return $vals;
	}

	public static function switches(): void {
		if ( \AssociazioneSemplice\Edition::has( 'wallet' ) ) echo '<p><label><input type="checkbox" name="wallet_enabled" value="1"' . checked( ! empty( Settings::get( 'wallet_enabled' ) ), true, false ) . '> <strong>Apple Wallet e Google Wallet</strong></label><br>'
			. '<span class="description">Aggiunge i pulsanti per salvare la tessera nel telefono. Richiede le credenziali di Apple e/o Google, da inserire qui sotto dopo averlo attivato.</span></p>';
	}

	public static function sections(): void {
		if ( \AssociazioneSemplice\Edition::has( 'wallet' ) && ! empty( Settings::get( 'wallet_enabled' ) ) ) {
			echo '<div class="asem-cols"><div class="asem-col">';
			self::apple_card();
			echo '</div><div class="asem-col">';
			self::google_card();
			echo '</div></div>';
			self::test_card();
		}
	}

	private static function apple_card(): void {
		$cfg = Wallet::apple_config();
		echo '<div class="asem-card"><h2>Apple Wallet</h2>';
		if ( $cfg ) {
			$info = array( 'expires' => '' );
			try {
				$info = WalletCredentials::cert_info( $cfg['cert'] );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
			$days = $info['expires'] ? (int) floor( ( strtotime( $info['expires'] ) - time() ) / DAY_IN_SECONDS ) : null;
			echo '<p><strong class="asem-ok">Configurato</strong> · ' . esc_html( $cfg['pass_type'] ) . ' · team ' . esc_html( $cfg['team'] ) . '</p>';
			if ( null !== $days ) {
				echo '<p class="' . ( $days < 30 ? 'asem-neg' : 'description' ) . '">Il certificato scade il ' . esc_html( Ui::date( $info['expires'] ) ) . ( $days < 30 ? ' (tra ' . max( 0, $days ) . ' giorni: rinnovalo da Apple e ricaricalo qui)' : '' ) . '.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html già protetto dagli helper o numeri interi
			}
		} else {
			echo '<p>Non configurato. Servono un <strong>account Apple Developer</strong> dell\'associazione, un <strong>Pass Type ID</strong> con il suo certificato (esportato in un file <code>.p12</code>) e il certificato intermedio <strong>WWDR</strong> di Apple (<code>.cer</code>).</p>';
		}
		if ( Settings::secret_unreadable( 'wallet_apple_key_pem' ) ) {
			echo '<div class="notice notice-warning inline"><p>La chiave salvata non è leggibile su questo sito (il sito è stato spostato o copiato): ricarica il certificato.</p></div>';
		}
		Ui::form_open( 'asem_save_wallet_apple', Ui::url( 'asem-card' ), true );
		echo '<table class="form-table"><tbody>'
			. '<tr><th>File .p12</th><td><input type="file" name="apple_p12" accept=".p12,.pfx"><br><input type="password" name="apple_password" placeholder="password del file .p12" autocomplete="new-password"></td></tr>'
			. '<tr><th>Certificato WWDR</th><td><input type="file" name="apple_wwdr" accept=".cer,.pem,.crt"></td></tr>'
			. '<tr><th>Pass Type ID</th><td><input type="text" name="pass_type" value="' . esc_attr( (string) Settings::get( 'wallet_apple_pass_type' ) ) . '" placeholder="pass.it.miaassociazione.tessera"><p class="description">Viene compilato automaticamente dal certificato.</p></td></tr>'
			. '<tr><th>Team ID</th><td><input type="text" name="team" value="' . esc_attr( (string) Settings::get( 'wallet_apple_team' ) ) . '" maxlength="20"></td></tr>'
			. '</tbody></table>';
		submit_button( 'Salva Apple Wallet', 'primary', 'submit', false );
		Ui::form_close();
		if ( $cfg ) {
			echo ' ';
			Ui::form_open( 'asem_wallet_clear', Ui::url( 'asem-card' ), false, 'asem-inline' );
			echo Ui::hidden( 'which', 'apple' ) . '<button class="button" data-confirm="Togliere le credenziali di Apple Wallet?">Rimuovi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '<p class="description">La chiave privata viene cifrata nel database e non si legge più. Se il file <code>.p12</code> esportato da macOS non si legge, esportalo di nuovo con una password o chiedi a chi gestisce il server.</p></div>';
	}

	private static function google_card(): void {
		$cfg = Wallet::google_config();
		echo '<div class="asem-card"><h2>Google Wallet</h2>';
		if ( $cfg ) {
			echo '<p><strong class="asem-ok">Configurato</strong> · emittente ' . esc_html( $cfg['issuer'] ) . ' · ' . esc_html( $cfg['email'] ) . '</p>';
		} else {
			echo '<p>Non configurato. Servono un account <strong>Google Pay &amp; Wallet Console</strong> (ID emittente), l\'accesso ai pass di tipo "Generico" e un <strong>account di servizio</strong> di Google Cloud con la sua chiave in formato JSON.</p>';
		}
		if ( Settings::secret_unreadable( 'wallet_google_key_pem' ) ) {
			echo '<div class="notice notice-warning inline"><p>La chiave salvata non è leggibile su questo sito: ricarica il file JSON.</p></div>';
		}
		Ui::form_open( 'asem_save_wallet_google', Ui::url( 'asem-card' ), true );
		echo '<table class="form-table"><tbody>'
			. '<tr><th>ID emittente</th><td><input type="text" name="issuer" value="' . esc_attr( (string) Settings::get( 'wallet_google_issuer' ) ) . '" placeholder="3388000000012345678"></td></tr>'
			. '<tr><th>Chiave JSON</th><td><input type="file" name="google_json" accept=".json,application/json"></td></tr>'
			. '</tbody></table>';
		submit_button( 'Salva Google Wallet', 'primary', 'submit', false );
		Ui::form_close();
		if ( $cfg ) {
			echo ' ';
			Ui::form_open( 'asem_wallet_clear', Ui::url( 'asem-card' ), false, 'asem-inline' );
			echo Ui::hidden( 'which', 'google' ) . '<button class="button" data-confirm="Togliere le credenziali di Google Wallet?">Rimuovi</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::form_close();
		}
		echo '<p class="description">Finché l\'emittente è in modalità di prova, Google permette di salvare le tessere solo agli utenti di test che indichi nella console.</p></div>';
	}

	/** Prova con un socio a scelta: scarica la tessera Apple o apre il link Google. */
	private static function test_card(): void {
		if ( ! Wallet::apple_config() && ! Wallet::google_config() ) {
			return;
		}
		$id = Ui::get_int( 'test_person' );
		echo '<div class="asem-card"><h2>Prova</h2><form method="get"><input type="hidden" name="page" value="asem-card"><select name="test_person">';
		echo '<option value="">— scegli un socio —</option>';
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::is_member( $p['type'] ) ) {
				echo '<option value="' . (int) $p['id'] . '"' . selected( $id, (int) $p['id'], false ) . '>' . esc_html( $p['last_name'] . ' ' . $p['first_name'] ) . '</option>';
			}
		}
		echo '</select> <button class="button">Mostra</button></form>';
		if ( $id ) {
			if ( Wallet::apple_config() ) {
				echo '<p><a class="button" href="' . esc_url( Wallet::apple_url( $id ) ) . '">Scarica la tessera per Apple Wallet (.pkpass)</a></p>';
			}
			if ( Wallet::google_config() ) {
				try {
					echo '<p><a class="button" href="' . esc_url( Wallet::google_url( $id ) ) . '" target="_blank" rel="noopener">Apri il salvataggio su Google Wallet</a></p>';
				} catch ( \InvalidArgumentException $e ) {
					echo '<p class="asem-neg">' . esc_html( $e->getMessage() ) . '</p>';
				}
			}
		}
		echo '</div>';
	}
}
