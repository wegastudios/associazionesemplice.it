<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\CardToken;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina che si apre scansionando il QR della tessera: dice se la tessera è valida in questo momento.
 * Mostra solo nome, tipo, numero e validità (mai email, telefono o altro). Non viene indicizzata né messa in cache.
 * Funziona solo se il gestore ha attivato il QR della tessera (impostazione, spenta di default).
 */
final class CardVerify {

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	/** Intestazioni comuni delle pagine di verifica (tessera e biglietti). */
	public static function send_headers(): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' ); // niente pagine di verifica o moduli di ingresso dentro un frame di un altro sito (clickjacking)
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['asem_card'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$html = self::page( sanitize_text_field( wp_unslash( $_GET['asem_card'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::send_headers();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- già escapato in page()
		exit;
	}

	/** @return array status (valid|expired|invalid|suspended|disabled), person (o null), until (data o null) */
	public static function result( string $param ): array {
		if ( ! Settings::card_qr_enabled() ) {
			return array( 'status' => 'disabled', 'person' => null, 'until' => null );
		}
		$parsed = CardToken::parse( $param );
		if ( ! $parsed || ! CardToken::valid( $parsed[0], $parsed[1], Settings::card_secret() ) ) {
			return array( 'status' => 'invalid', 'person' => null, 'until' => null );
		}
		if ( ! Edition::allows( 'member_area' ) ) {
			return array( 'status' => 'suspended', 'person' => null, 'until' => null );
		}
		$people = Plugin::people();
		$p      = $people->get( $parsed[0] );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			return array( 'status' => 'invalid', 'person' => null, 'until' => null );
		}
		$until = $people->active_until( (int) $p['id'] );
		if ( $people->is_suspended( (int) $p['id'] ) ) {
			return array( 'status' => 'expired', 'person' => $p, 'until' => $until ); // sospeso o uscito dall'associazione: la tessera non vale, anche se non è scaduta
		}
		if ( MemberType::is_auto_renewed( $p['type'] ) ) {
			return array( 'status' => 'valid', 'person' => $p, 'until' => null );
		}
		return array( 'status' => $until && $until >= current_time( 'Y-m-d' ) ? 'valid' : 'expired', 'person' => $p, 'until' => $until );
	}

	public static function page( string $param ): string {
		$r   = self::result( $param );
		$map = array(
			'valid'     => array( 'Tessera valida', '#1a7f37' ),
			'expired'   => array( 'Tessera non valida', '#b32d2e' ),
			'invalid'   => array( 'QR non valido', '#b32d2e' ),
			'suspended' => array( 'Servizio sospeso', '#8a6d00' ),
			'disabled'  => array( 'Verifica non attiva', '#8a6d00' ),
		);
		list( $title, $color ) = $map[ $r['status'] ];
		if ( $r['person'] ) {
			$p     = $r['person'];
			$until = MemberType::is_auto_renewed( $p['type'] ) && 'valid' === $r['status'] ? 'Sempre rinnovata' : ( $r['until'] ? ( new \DateTimeImmutable( $r['until'] ) )->format( 'd/m/Y' ) : '—' );
			$body  = '<div class="n">' . esc_html( trim( $p['first_name'] . ' ' . $p['last_name'] ) ) . '</div><div class="t">' . esc_html( \AssociazioneSemplice\Levels::label( $p ) ) . '</div>'
				. '<dl><div><dt>Tessera n.</dt><dd>' . esc_html( (string) ( $p['card_number'] ?: '—' ) ) . '</dd></div><div><dt>Valida fino al</dt><dd>' . esc_html( $until ) . '</dd></div></dl>';
		} elseif ( 'invalid' === $r['status'] ) {
			$body = '<p>Il codice non corrisponde a nessuna tessera attiva. Può essere stato sostituito: chiedi al socio di mostrare quello aggiornato.</p>';
		} else {
			$body = '<p>La verifica non è disponibile al momento.</p>';
		}
		return self::layout( $title, $color, $body );
	}

	/** Il foglio di stile delle pagine di verifica, registrato e stampato con le funzioni di WordPress. */
	private static function styles(): string {
		wp_register_style( 'asem-verify', ASEM_URL . 'assets/verify.css', array(), \AssociazioneSemplice\Plugin::asset_version( 'verify.css' ) );
		ob_start();
		wp_print_styles( 'asem-verify' );
		return (string) ob_get_clean();
	}

	/** Pagina completa (senza il tema del sito) con la fascia di stato e il riquadro dei dettagli. */
	public static function layout( string $title, string $color, string $body ): string {
		$assoc  = (string) Settings::get( 'association_name' );
		$accent = Assets::accent( '#2271b1' ); // colore scelto, altrimenti quello del sito (Elementor o tema)
		return \AssociazioneSemplice\Texts::html( '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title>' . self::styles() . '</head>'
			. '<body style="--c:' . esc_attr( $color ) . ';--a:' . esc_attr( $accent ) . '"><div class="c">'
			. '<div class="s">' . esc_html( $title ) . '</div><div class="b">' . ( '' !== Assets::logo_url() ? '<img src="' . esc_url( Assets::logo_url() ) . '" alt="" style="display:block;max-height:48px;max-width:180px;margin:0 0 10px">' : '' ) . ( '' !== $assoc ? '<div class="a">' . esc_html( $assoc ) . '</div>' : '' ) . $body . '</div></div></body></html>' );
	}
}
