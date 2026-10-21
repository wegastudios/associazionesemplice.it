<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\CardLayout;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Scarico della tessera in PDF: per il socio collegato all'utente e per la segreteria, con un codice di sicurezza nel link. */
final class CardPdf {

	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function handle(): void {
		if ( ! isset( $_GET['asem_card_pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id    = absint( wp_unslash( $_GET['asem_card_pdf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$nonce = isset( $_GET['_n'] ) ? sanitize_text_field( wp_unslash( $_GET['_n'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'asem_card_pdf_' . $id ) ) {
			wp_die( 'Il link non è più valido: riapri la pagina della tessera e riprova.', 'Tessera', array( 'response' => 403 ) );
		}
		$mine = Access::person_for_user( get_current_user_id() );
		if ( ! ( $mine && (int) $mine['id'] === $id ) && ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_die( 'Non hai il permesso di scaricare questa tessera.', 'Tessera', array( 'response' => 403 ) );
		}
		if ( ! Settings::get( 'card_enabled' ) || ! Edition::allows( 'member_area' ) ) {
			wp_die( 'La tessera digitale non è disponibile.', 'Tessera', array( 'response' => 403 ) );
		}
		$p = Plugin::people()->get( $id );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			wp_die( 'Tessera non trovata.', 'Tessera', array( 'response' => 404 ) );
		}
		$pdf = CardLayout::pdf( $p );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="tessera-' . ( '' !== (string) $p['card_number'] ? sanitize_file_name( (string) $p['card_number'] ) : (int) $id ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- file PDF binario generato dal plugin
		exit;
	}
}
