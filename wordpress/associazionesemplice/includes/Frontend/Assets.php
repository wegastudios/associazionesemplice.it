<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Color;
use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/** Stile e script del front-end: caricati solo quando serve (quando una vista o un riquadro viene stampato). */
final class Assets {

	/**
	 * Colore del sito con quel nome («primary» o «secondary»), letto dal tema: prima il colore globale di Elementor (kit attivo), poi la
	 * tavolozza dei temi a blocchi. Stringa vuota se non si trova.
	 */
	public static function theme_color( string $slug ): string {
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				if ( $kit ) {
					foreach ( (array) $kit->get_settings( 'system_colors' ) as $c ) {
						if ( is_array( $c ) && $slug === ( $c['_id'] ?? '' ) && '' !== Color::normalize( (string) ( $c['color'] ?? '' ) ) ) {
							return Color::normalize( (string) $c['color'] );
						}
					}
				}
			}
			if ( function_exists( 'wp_get_global_settings' ) ) {
				foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $c ) {
					if ( is_array( $c ) && $slug === ( $c['slug'] ?? '' ) && '' !== Color::normalize( (string) ( $c['color'] ?? '' ) ) ) {
						return Color::normalize( (string) $c['color'] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/** Colore principale del sito (vedi {@see Assets::theme_color()}). */
	public static function theme_accent(): string {
		return self::theme_color( 'primary' );
	}

	/** Colore principale da usare: quello scelto nell'Aspetto, altrimenti quello del sito, altrimenti $fallback. */
	public static function accent( string $fallback = '' ): string {
		$own = Color::normalize( (string) Settings::get( 'accent_color' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$theme = self::theme_accent();
		return '' !== $theme ? $theme : $fallback;
	}

	/** Colore secondario da usare: quello scelto, altrimenti quello del sito, altrimenti $fallback. */
	public static function secondary( string $fallback = '' ): string {
		$own = Color::normalize( (string) Settings::get( 'secondary_color' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$theme = self::theme_color( 'secondary' );
		return '' !== $theme ? $theme : $fallback;
	}

	/** Logo del sito (quello di Elementor, poi quello del tema, poi l'icona del sito): indirizzo dell'immagine o vuoto. */
	public static function site_logo_url(): string {
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
				$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
				$img = $kit ? (array) $kit->get_settings( 'site_logo' ) : array();
				if ( ! empty( $img['url'] ) ) {
					return esc_url_raw( (string) $img['url'] );
				}
			}
			$id = (int) get_theme_mod( 'custom_logo' );
			if ( $id > 0 ) {
				$u = wp_get_attachment_image_url( $id, 'medium' );
				if ( $u ) {
					return (string) $u;
				}
			}
			$icon = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 192 ) : '';
			return $icon;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Logo da usare: quello scelto nell'Aspetto (libreria media), altrimenti quello del sito. Indirizzo dell'immagine o vuoto. */
	public static function logo_url(): string {
		$id = (int) Settings::get( 'logo_id' );
		if ( $id > 0 ) {
			$u = wp_get_attachment_image_url( $id, 'medium' );
			if ( $u ) {
				return (string) $u;
			}
		}
		return self::site_logo_url();
	}

	/** CSS generato dai colori (scelti o presi dal sito). Stringa vuota = restano i colori di riserva del foglio di stile. */
	public static function inline_css(): string {
		$accent = self::accent();
		$sec    = self::secondary();
		if ( '' === $accent && '' === $sec ) {
			return '';
		}
		$css  = '.asemf{';
		$css .= '' !== $accent ? '--asemf-accent:' . $accent . ';--asemf-accent-text:' . Color::text_on( $accent ) . ';' : '';
		$css .= '' !== $sec ? '--asemf-accent-2:' . $sec . ';' : '';
		return $css . '}';
	}

	/**
	 * Viste che, viste da un anonimo, possono stare in una pagina conservata: elenchi e pagine degli eventi, documenti e convenzioni pubblici, coordinate
	 * per il bonifico, donazioni, informativa. Ogni altra vista (area soci, moduli, prenotazione, estensioni sconosciute) cambia con chi guarda o porta un modulo.
	 */
	const PUBLIC_VIEWS = array(
		'eventi', 'calendario_eventi', 'prossimi_eventi', 'evento_locandina', 'evento_dati', 'evento_dove', 'evento_navigazione', 'evento_condividi',
		'documenti', 'convenzioni', 'bonifico', 'donazioni', 'privacy',
	);

	/** @var array<int,array{found:bool,private:bool}> risultato della lettura del contenuto, per contenuto (una sola volta a richiesta) */
	private static $scanned = array();

	/**
	 * Cosa di Associazione Semplice c'è in un testo (contenuto, blocchi o dati di Elementor): niente, solo viste pubbliche o anche viste che cambiano con chi guarda.
	 * Una vista che non si riconosce vale come «privata»: nel dubbio la pagina resta fuori dalla cache.
	 *
	 * @return array{found:bool,private:bool}
	 */
	public static function scan( string $text ): array {
		$found   = false;
		$private = false;
		if ( false === strpos( $text, 'associazionesemplice' ) ) {
			return array( 'found' => false, 'private' => false );
		}
		if ( preg_match_all( '/(?<!\[)\[associazionesemplice_([a-z0-9_]+)/', $text, $m ) ) { // shortcode (anche dentro il blocco «Shortcode»)
			$found = true;
			foreach ( $m[1] as $view ) {
				if ( ! in_array( $view, self::PUBLIC_VIEWS, true ) ) {
					$private = true;
				}
			}
		}
		if ( preg_match_all( '#<!--\s+wp:associazionesemplice/(vista|riservato)(\s+\{.*?\})?\s*/?-->#s', $text, $m, PREG_SET_ORDER ) ) { // blocchi Gutenberg
			$found = true;
			foreach ( $m as $block ) {
				if ( 'riservato' === $block[1] ) {
					$private = true;
					continue;
				}
				$attrs = isset( $block[2] ) ? json_decode( trim( $block[2] ), true ) : null;
				$view  = is_array( $attrs ) && isset( $attrs['vista'] ) ? (string) $attrs['vista'] : 'area_soci';
				if ( ! in_array( $view, self::PUBLIC_VIEWS, true ) ) {
					$private = true;
				}
			}
		}
		$widgets = substr_count( $text, 'associazionesemplice_view' ); // widget di Elementor (le impostazioni uguali a quelle di fabbrica non si scrivono: «area_soci»)
		if ( $widgets > 0 || false !== strpos( $text, 'associazionesemplice_reserved' ) ) {
			$found = true;
			if ( false !== strpos( $text, 'associazionesemplice_reserved' ) ) {
				$private = true;
			}
			$views = preg_match_all( '/"view":"([a-z0-9_]+)"/', $text, $m ) ? $m[1] : array();
			if ( count( $views ) < $widgets || array_diff( $views, self::PUBLIC_VIEWS ) ) {
				$private = true;
			}
		}
		return array( 'found' => $found, 'private' => $private );
	}

	/** Lettura del contenuto della pagina aperta (se è un contenuto singolo): testo, blocchi e dati di Elementor. */
	public static function queried_uses(): array {
		$none = array( 'found' => false, 'private' => false );
		if ( ! is_singular() ) {
			return $none;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) {
			return $none;
		}
		if ( ! isset( self::$scanned[ $id ] ) ) {
			$post = get_post( $id );
			$text = $post ? (string) $post->post_content : '';
			$el   = get_post_meta( $id, '_elementor_data', true );
			if ( is_string( $el ) && '' !== $el ) {
				$text .= "\n" . $el;
			}
			self::$scanned[ $id ] = self::scan( $text );
		}
		return self::$scanned[ $id ];
	}

	/** Se la pagina porta una vista del plugin, gli stili si chiedono subito (nell'intestazione) invece che mentre si scrive il contenuto (nel piede). */
	public static function early_enqueue(): void {
		if ( self::queried_uses()['found'] ) {
			self::enqueue();
		}
	}

	public static function enqueue(): void {
		if ( wp_style_is( 'asem-frontend', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'asem-frontend', ASEM_URL . 'assets/frontend.css', array(), \AssociazioneSemplice\Plugin::asset_version( 'frontend.css' ) );
		$css = self::inline_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'asem-frontend', $css );
		}
		wp_enqueue_script( 'asem-frontend', ASEM_URL . 'assets/frontend.js', array(), \AssociazioneSemplice\Plugin::asset_version( 'frontend.js' ), true );
	}
}
