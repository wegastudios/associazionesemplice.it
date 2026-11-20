<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\ClientIp;
use AssociazioneSemplice\Donations;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\PaymentDonation;
use AssociazioneSemplice\PaymentService;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\PrivacyNotice;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\TaxCode;

defined( 'ABSPATH' ) || exit;

/**
 * La pagina delle donazioni col pagamento del sito (Stripe, PayPal, SumUp, Satispay), aperta anche a chi non è socio: importo, dati per la ricevuta,
 * metodo. A pagamento confermato la donazione entra in prima nota come «Erogazione liberale» con la sua ricevuta (vedi PaymentService::finalize).
 * Difese contro spam e abusi, senza servizi esterni: campo esca, gettone firmato (e troppo rapido = programma), limite per connessione e per email,
 * importo tra 1 € e 1.000 €.
 */
final class DonationPage {

	/** @var array|null esito dell'invio di questa richiesta: errors, post */
	private static $result = null;

	public static function register(): void {
		add_filter( 'asem_donation_checkout_available', array( __CLASS__, 'available' ) );
		add_filter( 'asem_donation_page', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 3 );
	}

	/** Si può donare dal pagamento del sito: Pro con i pagamenti e almeno un metodo attivo (il checkout di WooCommerce non vale). */
	public static function available( $on ): bool {
		return Edition::has( 'payments' ) && PaymentService::engine()->application_online();
	}

	/** @return string[] metodi attivi per la donazione */
	private static function providers(): array {
		return array_values( array_diff( PaymentService::engine()->providers(), array( PaymentConfig::WOOCOMMERCE ) ) );
	}

	private static function fail( array $errors, array $post ): void {
		self::$result = array( 'errors' => $errors, 'post' => $post );
	}

	/** Invio del modulo: se tutto è a posto si va alla pagina di pagamento del gestore, altrimenti la pagina mostra gli errori. */
	public static function handle(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! isset( $_POST['asem_donate_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- gettone firmato, vedi sotto
			return;
		}
		$post = Plugin::post_data(); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $post['website'] ) ) { // campo esca compilato
			self::fail( array( 'Non è stato possibile avviare il pagamento: riprova.' ), array() );
			return;
		}
		$token = Application::check_token( (string) ( $post['t'] ?? '' ), time() );
		if ( 'invalid' === $token ) {
			self::fail( array( 'La pagina è rimasta aperta troppo a lungo: ricaricala e riprova.' ), $post );
			return;
		}
		if ( 'early' === $token ) {
			self::fail( array( 'Non è stato possibile avviare il pagamento: riprova.' ), $post );
			return;
		}
		$ip_key = 'asem_don_ip_' . md5( ClientIp::get() );
		$ip_n   = (int) get_transient( $ip_key );
		if ( $ip_n >= Limits::get( 'donate_per_ip' ) ) {
			self::fail( array( 'Troppi tentativi: riprova tra un\'ora.' ), $post );
			return;
		}
		set_transient( $ip_key, $ip_n + 1, HOUR_IN_SECONDS );

		$errors = array();
		$amount = 'other' === (string) ( $post['amount'] ?? '' ) ? (string) ( $post['amount_other'] ?? '' ) : (string) ( $post['amount'] ?? '' );
		$cents  = 'other' === (string) ( $post['amount'] ?? '' ) ? PaymentDonation::parse_amount( $amount ) : self::parse_cents( $amount );
		if ( null === $cents ) {
			$errors[] = 'Scegli un importo tra ' . Money::format( PaymentDonation::MIN_CENTS ) . ' e ' . Money::format( PaymentDonation::MAX_CENTS ) . '.';
		}
		$first = mb_substr( trim( (string) ( $post['first_name'] ?? '' ) ), 0, 120 );
		$last  = mb_substr( trim( (string) ( $post['last_name'] ?? '' ) ), 0, 120 );
		$email = strtolower( trim( (string) ( $post['email'] ?? '' ) ) );
		if ( '' === $first || '' === $last ) {
			$errors[] = 'Scrivi nome e cognome.';
		}
		if ( ! is_email( $email ) ) {
			$errors[] = 'Scrivi un indirizzo email valido: ci serve per mandarti la ricevuta.';
		}
		$tax = TaxCode::normalize( trim( (string) ( $post['tax_code'] ?? '' ) ) );
		if ( '' !== $tax && ! TaxCode::is_valid( $tax ) ) {
			$errors[] = 'Il codice fiscale non sembra corretto: controllalo oppure lascialo vuoto.';
		}
		$providers = self::providers();
		$provider  = (string) ( $post['method'] ?? '' );
		if ( '' === $provider && $providers ) {
			$provider = $providers[0];
		}
		if ( ! in_array( $provider, $providers, true ) ) {
			$errors[] = 'Scegli come pagare.';
		}
		if ( $errors ) {
			self::fail( $errors, $post );
			return;
		}
		$em_key = 'asem_don_em_' . md5( $email );
		$em_n   = (int) get_transient( $em_key );
		if ( $em_n >= Limits::get( 'donate_per_email' ) ) {
			self::fail( array( 'Con questo indirizzo email sono già stati avviati molti pagamenti: riprova tra un\'ora.' ), $post );
			return;
		}
		set_transient( $em_key, $em_n + 1, HOUR_IN_SECONDS );

		$campaign = (int) ( $post['campaign'] ?? 0 ) > 0 ? Donations::campaign( (int) $post['campaign'] ) : null;
		$purpose  = $campaign && ! empty( $campaign['active'] ) ? Donations::campaign_purpose( $campaign ) : Donations::purpose(); // la causale la decide il sito, non il modulo
		$back     = remove_query_arg( PayReturn::PARAMS, Restrict::current_url() );
		try {
			$donor = PaymentDonation::donor(
				array(
					'first_name' => $first,
					'last_name'  => $last,
					'email'      => $email,
					'tax_code'   => $tax,
					'address'    => (string) ( $post['address'] ?? '' ),
					'zip'        => (string) ( $post['zip'] ?? '' ),
					'city'       => (string) ( $post['city'] ?? '' ),
				)
			);
			$url   = PaymentService::engine()->donation_checkout( $donor, (int) $cents, $purpose, $back, $provider );
		} catch ( \InvalidArgumentException $e ) {
			self::fail( array( $e->getMessage() ), $post );
			return;
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- pagina di pagamento del gestore scelto (Stripe, PayPal, SumUp, Satispay)
		exit;
	}

	/** Importo scelto tra quelli proposti: centesimi interi, nei limiti ammessi. */
	private static function parse_cents( string $v ): ?int {
		if ( ! ctype_digit( $v ) ) {
			return null;
		}
		$c = (int) $v;
		return $c >= PaymentDonation::MIN_CENTS && $c <= PaymentDonation::MAX_CENTS ? $c : null;
	}

	private static function field( string $label, string $name, string $type, array $post, string $auto, bool $required, string $extra = '' ): string {
		return '<label>' . esc_html( $label ) . ( $required ? ' *' : '' ) . ' <input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $post[ $name ] ?? '' ) ) . '"'
			. ( $required ? ' required' : '' ) . ( '' !== $auto ? ' autocomplete="' . esc_attr( $auto ) . '"' : '' ) . $extra . '></label>';
	}

	/**
	 * Il modulo della pagina delle donazioni.
	 *
	 * @param string $html già pronto (vuoto)
	 * @param array  $ctx  campaign (raccolta o null), amounts (importi proposti, in euro), purpose, note
	 */
	public static function render( $html, $ctx ): string {
		if ( ! self::available( true ) ) {
			return (string) $html;
		}
		Assets::enqueue();
		$ctx       = (array) $ctx;
		$post      = self::$result ? (array) self::$result['post'] : array();
		$errors    = self::$result ? (array) self::$result['errors'] : array();
		$campaign  = $ctx['campaign'] ?? null;
		$providers = self::providers();
		$all       = Settings::all();
		$out       = Views::flash() . '<section class="asemf-section asemf-donate asemf-donate-checkout" id="donazione"><h3>' . esc_html( (string) ( $ctx['purpose'] ?? '' ) ) . '</h3>';
		if ( ! empty( $ctx['note'] ) ) {
			$out .= '<p>' . esc_html( (string) $ctx['note'] ) . '</p>';
		}
		if ( $errors ) {
			$out .= '<div class="asemf-notice asemf-err" role="alert"><ul style="margin:0;padding-left:1.2em">';
			foreach ( $errors as $e ) {
				$out .= '<li>' . esc_html( (string) $e ) . '</li>';
			}
			$out .= '</ul></div>';
		}
		$sel   = (string) ( $post['amount'] ?? '' );
		$radio = '';
		$n     = 0;
		foreach ( (array) ( $ctx['amounts'] ?? array() ) as $a ) {
			$cents = PaymentDonation::parse_amount( (string) $a );
			if ( null === $cents ) {
				continue;
			}
			$radio .= '<label class="asemf-check"><input type="radio" name="amount" value="' . (int) $cents . '"' . checked( (string) $cents === $sel || ( '' === $sel && 0 === $n ), true, false ) . '> ' . esc_html( Money::format( $cents ) ) . '</label>';
			++$n;
		}
		$radio .= '<label class="asemf-check"><input type="radio" name="amount" value="other"' . checked( 'other' === $sel || ( '' === $sel && 0 === $n ), true, false ) . '> Un altro importo: <input type="text" name="amount_other" value="' . esc_attr( (string) ( $post['amount_other'] ?? '' ) ) . '" size="6" inputmode="decimal" aria-label="Importo in euro"> €</label>';
		$form  = '<form class="asemf-form" method="post" action="' . esc_url( Restrict::current_url() . '#donazione' ) . '">'
			. '<input type="hidden" name="asem_donate_go" value="1"><input type="hidden" name="campaign" value="' . (int) ( $campaign['id'] ?? 0 ) . '"><input type="hidden" name="t" value="' . esc_attr( Application::make_token( time() ) ) . '">'
			. '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>Non compilare questo campo <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<fieldset><legend>Importo</legend>' . $radio . '</fieldset>'
			. '<div class="asemf-fields">'
			. self::field( 'Nome', 'first_name', 'text', $post, 'given-name', true, ' maxlength="120"' ) . self::field( 'Cognome', 'last_name', 'text', $post, 'family-name', true, ' maxlength="120"' )
			. self::field( 'Email', 'email', 'email', $post, 'email', true, ' maxlength="190"' )
			. self::field( 'Codice fiscale (facoltativo)', 'tax_code', 'text', $post, 'off', false, ' maxlength="16"' )
			. '</div><p class="asemf-small asemf-muted">Il codice fiscale serve per la ricevuta valida ai fini fiscali.</p>'
			. '<div class="asemf-fields">'
			. self::field( 'Indirizzo (facoltativo)', 'address', 'text', $post, 'street-address', false, ' maxlength="190"' ) . self::field( 'CAP', 'zip', 'text', $post, 'postal-code', false, ' maxlength="12"' ) . self::field( 'Comune', 'city', 'text', $post, 'address-level2', false, ' maxlength="100"' )
			. '</div>';
		if ( count( $providers ) > 1 ) {
			$form .= '<fieldset><legend>Come vuoi pagare</legend>';
			foreach ( $providers as $i => $g ) {
				$form .= '<label class="asemf-check"><input type="radio" name="method" value="' . esc_attr( $g ) . '"' . checked( (string) ( $post['method'] ?? '' ) === $g || ( '' === (string) ( $post['method'] ?? '' ) && 0 === $i ), true, false ) . '> ' . esc_html( PaymentConfig::label( $g, $all ) ) . '</label>';
			}
			$form .= '</fieldset>';
		} elseif ( $providers ) {
			$form .= '<input type="hidden" name="method" value="' . esc_attr( $providers[0] ) . '">';
		}
		$url   = trim( (string) Settings::get( 'privacy_url' ) );
		$form .= '<p class="asemf-small asemf-muted">Inviando il modulo dichiari di aver letto ' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">l\'informativa sulla privacy</a>' : 'l\'informativa sulla privacy (qui sotto)' ) . '.</p>';
		if ( '' === $url ) {
			$form .= '<details class="asemf-details"><summary>Informativa sulla privacy</summary>' . PrivacyNotice::html() . '</details>';
		}
		$note  = PaymentDonation::deductible_note();
		$form .= '<p><button type="submit" class="asemf-btn wp-element-button">Dona</button></p>'
			. '<p class="asemf-small asemf-muted">Paghi sul sito del gestore del pagamento: qui non passa nessun dato di carta.' . ( '' !== $note ? ' ' . esc_html( $note ) : '' ) . '</p></form>';
		return $out . $form . '</section>';
	}
}
