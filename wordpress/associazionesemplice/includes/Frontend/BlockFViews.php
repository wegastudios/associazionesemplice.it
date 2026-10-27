<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Conventions;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;

defined( 'ABSPATH' ) || exit;

/** Viste delle convenzioni: per i soci in regola (area riservata, `[convenzioni_soci]`) e l'elenco pubblico (`[convenzioni]`). */
final class BlockFViews {

	public static function register(): void {
		add_filter( 'asem_area_section', array( __CLASS__, 'area_section' ), 10, 3 );
		// Nomi brevi degli shortcode, oltre a quelli con il prefisso [associazionesemplice_…]
		add_shortcode( 'convenzioni_soci', array( __CLASS__, 'shortcode_members' ) );
		add_shortcode( 'convenzioni', array( __CLASS__, 'shortcode_public' ) );
	}

	/** @param mixed $atts */
	public static function shortcode_members( $atts = array() ): string {
		return Shortcodes::render_view( 'convenzioni_soci', (array) $atts );
	}

	/** @param mixed $atts */
	public static function shortcode_public( $atts = array() ): string {
		return Shortcodes::render_view( 'convenzioni', (array) $atts );
	}

	/** Sezione dell'area soci aggiunta da questo blocco. */
	public static function area_section( $html, $section, $person ) {
		if ( 'convenzioni' === $section ) {
			return (string) $html . self::section_members( (array) $person );
		}
		return $html;
	}

	private static function logo( array $c, string $class = 'asemf-conv-logo' ): string {
		$u = Conventions::logo_url( $c );
		return '' !== $u ? '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $u ) . '" alt="' . esc_attr( (string) $c['name'] ) . '" loading="lazy">' : '';
	}

	private static function period( array $c ): string {
		if ( ! $c['valid_to'] ) {
			return '';
		}
		return 'valida fino al ' . ( new \DateTimeImmutable( (string) $c['valid_to'] ) )->format( 'd/m/Y' );
	}

	/** Scelta della categoria: un modulo che ricarica la pagina (nessuna cache, nessuno script). */
	private static function filter_form( array $cats, string $current ): string {
		if ( count( $cats ) < 2 ) {
			return '';
		}
		$vista = isset( $_GET['asemf_vista'] ) ? sanitize_key( wp_unslash( $_GET['asemf_vista'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo lettura: resta nella stessa parte dell'area soci
		$html  = '<form method="get" class="asemf-conv-filter">' . ( '' !== $vista ? '<input type="hidden" name="asemf_vista" value="' . esc_attr( $vista ) . '">' : '' ) . '<label>Categoria <select name="conv_cat"><option value="">Tutte</option>';
		foreach ( $cats as $c ) {
			$html .= '<option value="' . esc_attr( $c ) . '"' . selected( $c, $current, false ) . '>' . esc_html( $c ) . '</option>';
		}
		return $html . '</select></label> <button type="submit" class="asemf-btn">Filtra</button></form>';
	}

	private static function requested_category(): string {
		return isset( $_GET['conv_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['conv_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- solo lettura: filtro dell'elenco
	}

	private static function contacts( array $c ): string {
		$bits = array();
		if ( '' !== $c['address'] ) {
			$bits[] = esc_html( $c['address'] );
		}
		if ( '' !== $c['phone'] ) {
			$bits[] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ) . '">' . esc_html( $c['phone'] ) . '</a>';
		}
		if ( '' !== $c['email'] ) {
			$bits[] = '<a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a>';
		}
		if ( '' !== $c['website'] ) {
			$bits[] = '<a href="' . esc_url( $c['website'] ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://(www\.)?#i', '', rtrim( $c['website'], '/' ) ) ) . '</a>';
		}
		return $bits ? '<div class="asemf-small">' . implode( ' · ', $bits ) . '</div>' : '';
	}

	// ---------- Soci ----------

	/** Le convenzioni valide oggi per questo socio; vuoto se non ha diritto a nessuna (anche per i tutori non soci). */
	public static function section_members( array $p ): string {
		if ( ! MemberType::is_member( (string) $p['type'] ) ) {
			return '';
		}
		$all = Conventions::for_person( $p );
		if ( ! $all ) {
			return '';
		}
		Assets::enqueue();
		$cat  = self::requested_category();
		$list = $cat ? array_values( array_filter( $all, function ( $c ) use ( $cat ) {
			return $c['category'] === $cat;
		} ) ) : $all;
		$name = trim( $p['first_name'] . ' ' . $p['last_name'] );
		$until = Plugin::people()->active_until( (int) $p['id'] );
		$card  = '' !== (string) ( $p['card_number'] ?? '' ) ? 'tessera n. ' . $p['card_number'] : 'tessera senza numero';
		$id_line = $name . ' · ' . $card . ( $until ? ' · valida fino al ' . ( new \DateTimeImmutable( $until ) )->format( 'd/m/Y' ) : '' );

		$html = '<section class="asemf-section asemf-conventions"><h3>Convenzioni</h3>' . self::filter_form( Conventions::categories_of( $all ), $cat );
		if ( ! $list ) {
			return $html . '<div class="asemf-notice">Nessuna convenzione in questa categoria.</div></section>';
		}
		$html .= '<ul class="asemf-list asemf-conv-list">';
		foreach ( $list as $c ) {
			$period = self::period( $c );
			$html  .= '<li class="asemf-conv"><div class="asemf-conv-head">' . self::logo( $c ) . '<div><strong>' . esc_html( $c['name'] ) . '</strong>'
				. ( '' !== $c['category'] ? ' <span class="asemf-small asemf-muted">' . esc_html( $c['category'] ) . '</span>' : '' )
				. '<div>' . esc_html( $c['benefit'] ) . '</div>' . ( '' !== $period ? '<div class="asemf-small asemf-muted">' . esc_html( $period ) . '</div>' : '' ) . '</div></div>'
				. '<details class="asemf-details"><summary>Come usarla</summary>';
			if ( '' !== $c['code'] ) {
				$html .= '<p>Codice convenzione: <strong class="asemf-conv-code">' . esc_html( $c['code'] ) . '</strong></p>';
			}
			if ( '' !== (string) $c['terms'] ) {
				$html .= '<p class="asemf-small">' . nl2br( esc_html( $c['terms'] ) ) . '</p>';
			}
			$html .= '<p class="asemf-small">Per farti riconoscere mostra all\'esercente questa riga (o la tua tessera digitale): <strong>' . esc_html( $id_line ) . '</strong>.</p>' . self::contacts( $c ) . '</details></li>';
		}
		return $html . '</ul></section>';
	}

	/** Shortcode `[convenzioni_soci]`: serve l'accesso. */
	public static function members(): string {
		return Views::with_person(
			function ( $p ) {
				if ( ! MemberType::is_member( (string) $p['type'] ) ) {
					return '<div class="asemf-notice">Le convenzioni sono riservate ai soci in regola con la tessera.</div>';
				}
				$html = self::section_members( $p );
				return '' !== $html ? $html : '<div class="asemf-notice">Non ci sono convenzioni valide per te in questo momento. Se la tessera è scaduta, rinnovala per tornare a vederle.</div>';
			}
		);
	}

	// ---------- Pubblico ----------

	/** Elenco pubblico: solo le convenzioni segnate «pubbliche» e valide oggi; niente codice né condizioni. @param array $atts categoria */
	public static function public_list( array $atts ): string {
		Views::no_cache(); // l'elenco cambia con le date di validità
		$cat  = trim( (string) ( $atts['categoria'] ?? '' ) );
		$list = Conventions::public_list( $cat );
		if ( ! $list ) {
			return '<div class="asemf"><div class="asemf-notice">Nessuna convenzione disponibile.</div></div>';
		}
		Assets::enqueue();
		$html = '<div class="asemf"><section class="asemf-section asemf-conventions"><ul class="asemf-list asemf-conv-list">';
		foreach ( $list as $c ) {
			$html .= '<li class="asemf-conv"><div class="asemf-conv-head">' . self::logo( $c ) . '<div><strong>' . esc_html( $c['name'] ) . '</strong>'
				. ( '' !== $c['category'] ? ' <span class="asemf-small asemf-muted">' . esc_html( $c['category'] ) . '</span>' : '' )
				. '<div>' . esc_html( $c['benefit'] ) . '</div></div></div></li>';
		}
		return $html . '</ul></section></div>';
	}
}
