<?php
namespace AssociazioneSemplice\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode `[associazionesemplice_*]`: funzionano in qualunque punto (Gutenberg blocco "Shortcode", Elementor widget
 * "Shortcode", editor classico, template). Blocchi Gutenberg e widget Elementor usano le stesse viste.
 *
 *  [associazionesemplice_area_soci sezioni="tessera,attivita,ospiti,profilo,volontario"]
 *  [associazionesemplice_tessera] [associazionesemplice_mie_attivita] [associazionesemplice_ospiti] [associazionesemplice_profilo] [associazionesemplice_area_volontari]
 *  [associazionesemplice_attivita anno="2025/2026" tipo="corso|evento|ricorrente" id="12" date="5"]
 *  [associazionesemplice_prossimi_eventi limite="5" prenotazione="si|no"]
 *  [associazionesemplice_accesso]
 *  [associazionesemplice_riservato accesso="soci|volontari|attivita" attivita="12,13"]…[/associazionesemplice_riservato]   (vedi Restrict)
 */
final class Shortcodes {

	/** Viste disponibili: slug => etichetta. */
	const BASE_VIEWS = array(
		'area_soci'       => 'Area soci (tessera, attività, ospiti, profilo)',
		'tessera'         => 'Tessera digitale',
		'mie_attivita'    => 'Le mie attività e prenotazioni',
		'pagamenti'       => 'Pagamenti da fare (e, se attivi, pagamento online)',
		'regolamento'     => 'Regolamento da accettare',
		'segreteria'      => 'Area segreteria (richieste, soci, comunicazioni)',
		'tesoriere'       => 'Area tesoriere (incassi, spese, iscrizioni, vendita eventi)',
		'spese'           => 'Spese del tesoriere (nome precedente di «Area tesoriere»)',
		'ingressi'        => 'Ingressi agli eventi (prenotati e QR, per responsabile e staff)',
		'avvisi'          => 'Avvisi dei volontari agli iscritti (bacheca)',
		'ospiti'          => 'I miei ospiti',
		'minori'          => 'I miei figli (minori di cui sei tutore)',
		'adesione'        => 'Domanda di adesione online (pubblico, per chi non è ancora socio)',
		'profilo'         => 'Il mio profilo',
		'area_volontari'  => 'Area volontari (le attività di cui sei referente)',
		'calendario'      => 'Calendario di corsi ed eventi (soci)',
		'attivita'        => 'Elenco attività ed eventi (pubblico)',
		'prossimi_eventi' => 'Prossimi eventi (pubblico)',
		'bonifico'        => 'Coordinate per il bonifico (pubblico, se attivo)',
		'donazioni'       => 'Donazioni con PayPal (pubblico, se attive)',
		'privacy'         => 'Informativa privacy (pubblico, compilata con i dati dell\'ente)',
		'accesso'         => 'Accesso / login',
	);

	/** Tutte le viste, comprese quelle di un'estensione (filtro `asem_shortcode_views`). */
	public static function views(): array {
		return (array) apply_filters( 'asem_shortcode_views', self::BASE_VIEWS );
	}

	/** La vista si può usare? Un'estensione può dire di no (filtro `asem_view_available`) quando la sua funzione non c'è. */
	public static function available( string $view ): bool {
		return (bool) apply_filters( 'asem_view_available', true, $view );
	}

	public static function register(): void {
		foreach ( array_keys( self::views() ) as $view ) {
			add_shortcode(
				'associazionesemplice_' . $view,
				function ( $atts ) use ( $view ) {
					return self::render_view( $view, (array) $atts );
				}
			);
		}
	}

	/** Disegna una vista. Usato da shortcode, blocco e widget. */
	public static function render_view( string $view, array $atts = array() ): string {
		switch ( $view ) {
			case 'area_soci':
				return Views::area( shortcode_atts( array( 'sezioni' => '' ), $atts ) );
			case 'tessera':
				return Views::card();
			case 'calendario':
				Views::no_cache();
				return Views::calendar();
			case 'avvisi':
				return Views::notices();
			case 'ingressi':
				return Views::checkin();
			case 'spese':
			case 'tesoriere':
				return Views::expenses();
			case 'segreteria':
				return Views::secretary();
			case 'regolamento':
				return Views::rules();
			case 'pagamenti':
				return Views::pay();
			case 'mie_attivita':
				return Views::my_activities();
			case 'minori':
				return Views::minors();
			case 'adesione':
				Views::no_cache();
				return Application::form_html();
			case 'ospiti':
				return Views::guests();
			case 'profilo':
				return Views::profile();
			case 'area_volontari':
				return Views::volunteer();
			case 'attivita':
				Views::no_cache();
				return Views::activities( shortcode_atts( array( 'anno' => '', 'tipo' => '', 'id' => '', 'date' => '5' ), $atts ) );
			case 'prossimi_eventi':
				Views::no_cache();
				return Views::upcoming( shortcode_atts( array( 'limite' => '5', 'prenotazione' => 'si' ), $atts ) );
			case 'bonifico':
				return Views::bank_public();
			case 'donazioni':
				return Views::donate( shortcode_atts( array( 'id' => '' ), $atts ) );
			case 'privacy':
				return Views::privacy_notice();
			case 'accesso':
				return is_user_logged_in() ? '' : Views::login_prompt();
		}
		return (string) apply_filters( 'asem_render_view', '', $view, $atts ); // le viste di un'estensione
	}
}
