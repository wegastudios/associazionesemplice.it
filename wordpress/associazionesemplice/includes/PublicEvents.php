<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Pagina pubblica di eventi e corsi: un contenuto WordPress («Evento sul sito») per ogni evento o corso creato in Associazione Semplice.
 *
 * - Il collegamento è **obbligatorio e univoco**: il contenuto nasce da solo quando si crea l'attività (e per quelle già esistenti la prima volta
 *   che si apre l'amministrazione), non si può creare a mano né eliminare finché l'attività esiste, e non si può collegare ad altro.
 * - Date, orari, luogo, contributo e posti **non si copiano**: la pagina li legge dall'attività a ogni visualizzazione.
 * - Si scrivono nel contenuto solo ciò che non sta nell'attività: descrizione, locandina (immagine in evidenza), riassunto e categorie.
 * - La prenotazione usa le stesse regole e lo stesso modulo dell'area soci.
 */
final class PublicEvents {

	const POST_TYPE = 'asem_evento';
	const TAXONOMY  = 'asem_evento_cat';
	const META      = '_asem_activity_id';
	const OPT_DONE  = 'asem_public_events_backfill';
	const OPT_FLUSH = 'asem_public_events_rewrite';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_action( 'asem_activity_created', array( __CLASS__, 'ensure' ) );
		add_action( 'asem_activity_updated', array( __CLASS__, 'activity_updated' ), 10, 2 );
		add_action( 'asem_activity_deleted', array( __CLASS__, 'activity_deleted' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect' ), 10, 4 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 4 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'hide_theme_poster' ), 10, 2 );
		add_filter( 'render_block', array( __CLASS__, 'hide_theme_poster_block' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'structured_data' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_ics' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'backfill' ) );
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'meta_box' ) );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
			add_filter( 'enter_title_here', array( __CLASS__, 'title_hint' ), 10, 2 );
		}
	}

	/** Base degli indirizzi (modificabile con il filtro). */
	public static function slug(): string {
		$s = sanitize_title( (string) apply_filters( 'asem_public_event_slug', 'eventi' ) );
		return '' !== $s ? $s : 'eventi';
	}

	public static function register_types(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => 'Eventi sul sito',
					'singular_name'      => 'Evento sul sito',
					'menu_name'          => 'Eventi sul sito',
					'edit_item'          => 'Modifica la pagina dell\'evento',
					'view_item'          => 'Vedi la pagina',
					'search_items'       => 'Cerca eventi',
					'not_found'          => 'Nessuna pagina: nascono da sole quando crei un evento o un corso in Associazione Semplice.',
					'all_items'          => 'Tutti gli eventi sul sito',
					'featured_image'     => 'Locandina',
					'set_featured_image' => 'Scegli la locandina',
					'remove_featured_image' => 'Togli la locandina',
					'use_featured_image' => 'Usa come locandina',
				),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 31,
				'menu_icon'           => 'dashicons-calendar-alt',
				'show_in_rest'        => true,
				'has_archive'         => false,
				'exclude_from_search' => false,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'             => array( 'slug' => self::slug(), 'with_front' => false ),
				'capability_type'     => array( 'asem_evento', 'asem_eventi' ), // permessi propri: non toccano gli articoli
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ), // si creano solo dall'attività
			)
		);
		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels'       => array( 'name' => 'Categorie degli eventi', 'singular_name' => 'Categoria', 'menu_name' => 'Categorie' ),
				'public'       => true,
				'hierarchical' => true,
				'capabilities' => array( 'manage_terms' => Plugin::CAP_OPS, 'edit_terms' => Plugin::CAP_OPS, 'delete_terms' => Plugin::CAP_OPS, 'assign_terms' => Plugin::CAP_OPS ),
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => self::slug() . '/categoria', 'with_front' => false ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META,
			array( 'type' => 'integer', 'single' => true, 'show_in_rest' => false, 'auth_callback' => '__return_false' )
		);
		if ( '1' !== (string) get_option( self::OPT_FLUSH, '' ) ) { // indirizzi nuovi: si ricaricano una volta
			flush_rewrite_rules( false );
			update_option( self::OPT_FLUSH, '1', false );
		}
	}

	// ---------- Collegamento con l'attività ----------

	/** Id dell'attività collegata a un contenuto (0 se non c'è). */
	public static function activity_of( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::META, true );
	}

	/** Contenuto collegato a un'attività, anche nel cestino (0 se non c'è). */
	public static function post_of( int $activity_id ): int {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $activity_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'numberposts'      => 5,
				'fields'           => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/** Crea (o riprende) il contenuto dell'attività: uno solo, in bozza finché non lo pubblichi. */
	public static function ensure( $activity_id ): int {
		$activity_id = (int) $activity_id;
		$a           = $activity_id > 0 ? Plugin::activities()->get( $activity_id ) : null;
		if ( ! $a || ! ActivityKind::is_valid( (string) $a['kind'] ) ) {
			return 0;
		}
		$lock = 'asem_pe_lock_' . $activity_id;
		if ( ! add_option( $lock, (string) time(), '', false ) ) { // blocco atomico: due richieste insieme non fanno due pagine
			if ( (int) get_option( $lock, 0 ) < time() - 60 ) {
				delete_option( $lock );
			}
			return self::post_of( $activity_id );
		}
		try {
			$id = self::post_of( $activity_id );
			if ( $id ) {
				if ( 'trash' === get_post_status( $id ) ) {
					wp_untrash_post( $id );
				}
				return $id;
			}
			$new = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'draft',
					'post_title'  => (string) $a['name'],
					'post_name'   => sanitize_title( (string) $a['name'] ),
					'meta_input'  => array( self::META => $activity_id ),
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				return 0;
			}
			self::dedupe( $activity_id ); // se un'altra richiesta è arrivata prima resta la più vecchia
			return self::post_of( $activity_id );
		} finally {
			delete_option( $lock );
		}
	}

	private static function dedupe( int $activity_id ): void {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $activity_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'numberposts'      => 20,
				'fields'           => 'ids',
			)
		);
		foreach ( array_slice( $ids, 1 ) as $extra ) {
			wp_delete_post( (int) $extra, true );
		}
	}

	/** Se l'attività cambia nome e il titolo della pagina era ancora quello, il titolo segue. */
	public static function activity_updated( $activity_id, $old_name = '' ): void {
		$id = self::ensure( (int) $activity_id );
		$a  = $id ? Plugin::activities()->get( (int) $activity_id ) : null;
		if ( $id && $a && '' !== (string) $old_name && (string) $old_name !== (string) $a['name'] && get_the_title( $id ) === (string) $old_name ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => (string) $a['name'] ) );
		}
	}

	/** Attività eliminata: la pagina va nel cestino (si può ancora recuperare per un po'). */
	public static function activity_deleted( $activity_id ): void {
		$id = self::post_of( (int) $activity_id );
		if ( $id && 'trash' !== get_post_status( $id ) ) {
			wp_trash_post( $id );
		}
	}

	/** Attività già esistenti: la prima volta (e a piccoli gruppi) si crea la loro pagina in bozza. */
	public static function backfill(): void {
		if ( 'done' === get_option( self::OPT_DONE, '' ) || ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$db  = Db::db();
		$ids = $db->get_col( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 400' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$todo = 0;
		foreach ( (array) $ids as $aid ) {
			if ( ! self::post_of( (int) $aid ) ) {
				if ( ++$todo > 40 ) {
					return; // il resto alla prossima pagina dell'amministrazione
				}
				self::ensure( (int) $aid );
			}
		}
		update_option( self::OPT_DONE, 'done', false );
	}

	/** Chi lavora sulla gestione (segreteria, tesoriere, amministratore) può scrivere le pagine degli eventi e caricare le locandine; sugli altri contenuti del sito non guadagna nulla. */
	public static function grant_caps( $allcaps, $caps, $args, $user ) {
		if ( empty( $allcaps[ Plugin::CAP_OPS ] ) ) {
			return $allcaps;
		}
		foreach ( array( 'edit_asem_eventi', 'edit_others_asem_eventi', 'edit_published_asem_eventi', 'edit_private_asem_eventi', 'publish_asem_eventi', 'read_private_asem_eventi', 'delete_asem_eventi', 'delete_others_asem_eventi', 'delete_published_asem_eventi', 'delete_private_asem_eventi', 'upload_files' ) as $c ) {
			$allcaps[ $c ] = true;
		}
		return $allcaps;
	}

	/** Nessuno crea a mano, e finché l'attività esiste la pagina non si elimina né si collega ad altro. */
	public static function protect( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'delete_post', 'edit_post' ), true ) && ! empty( $args[0] ) && self::POST_TYPE === get_post_type( (int) $args[0] ) ) {
			$aid = self::activity_of( (int) $args[0] );
			if ( 'delete_post' === $cap && $aid && Plugin::activities()->get( $aid ) && ! doing_action( 'asem_activity_deleted' ) && ! self::deleting_for_cleanup() ) {
				return array( 'do_not_allow' );
			}
		}
		return $caps;
	}

	private static function deleting_for_cleanup(): bool {
		return defined( 'WP_UNINSTALL_PLUGIN' );
	}

	// ---------- Modifica nell'amministrazione ----------

	public static function title_hint( $text, $post ) {
		return $post && self::POST_TYPE === $post->post_type ? 'Titolo dell\'evento (parte da quello dell\'attività)' : $text;
	}

	public static function columns( $cols ): array {
		$out = array();
		foreach ( (array) $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['asem_activity'] = 'Evento o corso';
				$out['asem_when']     = 'Quando';
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ): void {
		$aid = self::activity_of( (int) $post_id );
		$a   = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( 'asem_activity' === $col ) {
			echo $a ? '<a href="' . esc_url( admin_url( 'admin.php?page=asem-activity&id=' . $aid ) ) . '">' . esc_html( $a['name'] ) . '</a> <span class="description">(' . esc_html( ActivityKind::short_label( (string) $a['kind'] ) ) . ')</span>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'asem_when' === $col ) {
			echo esc_html( $a ? self::when_text( $a ) : '—' );
		}
	}

	public static function meta_box(): void {
		add_meta_box( 'asem_event_data', 'Dati presi dall\'attività', array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'side', 'high' );
	}

	public static function render_meta_box( $post ): void {
		$aid = self::activity_of( (int) $post->ID );
		$a   = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( ! $a ) {
			echo '<p>Questa pagina non è più collegata a un evento o corso.</p>';
			return;
		}
		echo '<p><strong>' . esc_html( $a['name'] ) . '</strong> · ' . esc_html( ActivityKind::short_label( (string) $a['kind'] ) ) . '</p>';
		echo '<p>' . esc_html( self::when_text( $a ) ) . '</p>';
		$where = self::where_text( $a );
		echo '<p>' . ( '' !== $where ? esc_html( $where ) : '<em>Luogo non indicato</em>' ) . '</p>';
		echo '<p>' . esc_html( self::price_text( $a ) ) . '</p>';
		echo '<p class="description">Date, luogo, contributo e posti si cambiano nella scheda dell\'attività: la pagina li mostra sempre aggiornati. Qui scrivi la descrizione, scegli la locandina e il riassunto.</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=asem-activity&id=' . $aid ) ) . '">Apri l\'attività</a></p>';
	}

	/** Riquadro per la scheda dell'attività: collegamenti alla pagina pubblica. */
	public static function activity_box( int $activity_id ): string {
		$id = self::ensure( $activity_id );
		if ( ! $id ) {
			return '';
		}
		$status = get_post_status( $id );
		$live   = 'publish' === $status;
		$html   = '<div class="asem-card"><h2>Pagina sul sito</h2><p>' . ( $live ? '<span class="asem-badge asem-badge-ok">Pubblicata</span>' : '<span class="asem-badge">In bozza: non si vede ancora</span>' ) . '</p>'
			. '<p><a class="button button-primary" href="' . esc_url( (string) get_edit_post_link( $id, 'raw' ) ) . '">Modifica la pagina (descrizione, locandina)</a> ';
		if ( $live ) {
			$html .= '<a class="button" href="' . esc_url( (string) get_permalink( $id ) ) . '" target="_blank" rel="noopener">Vedi la pagina</a>';
		}
		return $html . '</p><p class="description">La pagina è collegata a questa attività e prende da qui date, luogo, contributo e posti.</p></div>';
	}

	// ---------- Testi dai dati dell'attività ----------

	/** @param array $a attività */
	public static function when_text( array $a ): string {
		if ( ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
			$rows = array_values( array_filter( Plugin::activities()->sessions( (int) $a['id'] ), function ( $s ) {
				return empty( $s['cancelled_at'] ) && (string) $s['session_date'] >= Db::today();
			} ) );
			if ( ! $rows ) {
				return 'Nessuna data in programma';
			}
			$first = $rows[0];
			$t     = Frontend\Views::date_long( (string) $first['session_date'] ) . ( ! empty( $first['start_time'] ) ? ' · ore ' . $first['start_time'] : '' );
			return count( $rows ) > 1 ? $t . ' (e altre ' . ( count( $rows ) - 1 ) . ' date)' : $t;
		}
		$days  = array( 1 => 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica' );
		$parts = array();
		foreach ( ActivityService::slots( $a ) as $s ) {
			$parts[] = ( $days[ (int) $s['day'] ] ?? '' ) . ( ! empty( $s['start'] ) ? ' ' . $s['start'] . ( ! empty( $s['end'] ) ? '–' . $s['end'] : '' ) : '' );
		}
		foreach ( ActivityService::lesson_dates( $a ) as $s ) {
			if ( (string) $s['date'] >= Db::today() ) {
				$parts[] = Frontend\Views::date_long( (string) $s['date'] ) . ( ! empty( $s['start'] ) ? ' ' . $s['start'] : '' );
			}
		}
		return $parts ? implode( ', ', $parts ) : 'Date da definire';
	}

	/** @param array $a attività */
	public static function where_text( array $a ): string {
		if ( ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
			foreach ( Plugin::activities()->sessions( (int) $a['id'] ) as $s ) {
				if ( empty( $s['cancelled_at'] ) && (string) $s['session_date'] >= Db::today() && ! empty( $s['location'] ) ) {
					return (string) $s['location'];
				}
			}
			return '';
		}
		return (string) ( $a['location'] ?? '' );
	}

	/** @param array $a attività */
	public static function price_text( array $a ): string {
		$fee  = (int) $a['fee_cents'];
		$unit = ActivityKind::fee_unit( (string) $a['kind'], (string) ( $a['billing'] ?? 'monthly' ) );
		if ( 0 === $fee && null === $a['guest_fee_cents'] ) {
			return 'Gratuito';
		}
		$t = 'Soci: ' . ( 0 === $fee ? 'gratuito' : Money::format( $fee ) . ( '' !== $unit ? ' ' . $unit : '' ) );
		if ( null !== $a['guest_fee_cents'] && (int) $a['guest_fee_cents'] !== $fee ) {
			$g  = (int) $a['guest_fee_cents'];
			$t .= ' · Ospiti: ' . ( 0 === $g ? 'gratuito' : Money::format( $g ) );
		}
		return $t;
	}

	// ---------- La pagina sul sito ----------

	private static function is_event_page(): bool {
		return is_singular( self::POST_TYPE ) && in_the_loop() && is_main_query();
	}

	public static function assets(): void {
		if ( is_singular( self::POST_TYPE ) ) {
			Frontend\Assets::enqueue();
		}
	}

	/** Locandina: la mostra la pagina stessa, il tema non la ripete. */
	public static function hide_theme_poster( $html, $post_id ) {
		return ( is_singular( self::POST_TYPE ) && in_the_loop() && is_main_query() && self::POST_TYPE === get_post_type( (int) $post_id ) ) ? '' : $html;
	}

	public static function hide_theme_poster_block( $block_content, $block ) {
		// Il tema mostra locandina, autore, data e categorie degli articoli: per un evento non hanno senso (la pagina ha già i suoi dati).
		return ( is_array( $block ) && in_array( $block['blockName'] ?? '', array( 'core/post-featured-image', 'core/post-author', 'core/post-author-name', 'core/post-author-biography', 'core/post-date', 'core/post-terms' ), true ) && is_singular( self::POST_TYPE ) ) ? '' : $block_content;
	}

	/** Contenuto della pagina: locandina, dati dell'attività, descrizione, prenotazione. */
	public static function content( $content ) {
		if ( ! self::is_event_page() ) {
			return $content;
		}
		$post = get_post();
		$aid  = $post ? self::activity_of( (int) $post->ID ) : 0;
		$a    = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( ! $a ) {
			return $content;
		}
		Frontend\Views::no_cache();
		$html = '<div class="asemf asemf-event">';
		if ( has_post_thumbnail( $post ) ) {
			$html .= '<figure class="asemf-poster">' . wp_get_attachment_image( (int) get_post_thumbnail_id( $post ), 'large' ) . '</figure>'; // direttamente: il filtro del tema che toglie la locandina duplicata non deve toccare questa
		}
		$html .= '<dl class="asemf-facts">'
			. '<div><dt>' . esc_html( ActivityKind::COURSE === $a['kind'] ? 'Quando' : 'Data e ora' ) . '</dt><dd>' . esc_html( self::when_text( $a ) ) . '</dd></div>';
		$where = self::where_text( $a );
		if ( '' !== $where ) {
			$html .= '<div><dt>Dove</dt><dd>' . esc_html( $where ) . '</dd></div>';
		}
		$html .= '<div><dt>Contributo</dt><dd>' . esc_html( self::price_text( $a ) ) . '</dd></div>';
		$org = (string) Settings::get( 'association_name' );
		if ( '' !== $org ) {
			$html .= '<div><dt>Organizza</dt><dd>' . esc_html( $org ) . '</dd></div>';
		}
		$html .= '</dl>';
		$html .= '<div class="asemf-event-text">' . $content . '</div>';
		if ( ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
			$html .= Frontend\Views::event_booking( $a );
			$html .= '<p class="asemf-ics"><a class="asemf-btn asemf-btn-ghost wp-element-button" href="' . esc_url( add_query_arg( 'asem_ics', '1', (string) get_permalink( $post ) ) ) . '">Salva nel tuo calendario</a></p>';
		} else {
			$html .= '<p class="asemf-small asemf-muted">Per iscriverti a questo corso rivolgiti alla segreteria dell\'associazione.</p>';
		}
		return $html . '</div>';
	}

	// ---------- Elenco pubblico a card, per mese ----------

	/**
	 * Vista pubblica degli eventi sul sito: elenco a card per mese (predefinita) o calendario mensile.
	 *
	 * @param array $atts modo (elenco|mese), categoria (indirizzo), limite (date, 1–100), mesi (quanti mesi, 1–24), filtri (si|no).
	 */
	public static function list_html( array $atts = array() ): string {
		Frontend\Views::no_cache();
		$view = 'mese' === strtolower( (string) ( $atts['modo'] ?? '' ) ) ? 'mese' : 'elenco';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtri di sola lettura sull'indirizzo della pagina
		$cat = isset( $_GET['asem_cat'] ) ? sanitize_title( wp_unslash( (string) $_GET['asem_cat'] ) ) : sanitize_title( (string) ( $atts['categoria'] ?? '' ) );
		$ym  = isset( $_GET['asem_mese'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['asem_mese'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$filters = 'no' !== strtolower( (string) ( $atts['filtri'] ?? 'si' ) );
		$bar     = $filters ? self::filter_bar( $view, $cat, $ym ) : '';
		if ( 'mese' === $view ) {
			return Frontend\Views::shell( $bar . self::calendar( $cat, $ym ), 'asemf-events' );
		}
		$limit = max( 1, min( 100, (int) ( $atts['limite'] ?? 30 ) ) );
		$until = '9999-12-31';
		if ( ! empty( $atts['mesi'] ) ) {
			$until = gmdate( 'Y-m-d', strtotime( Db::today() . ' +' . max( 1, min( 24, (int) $atts['mesi'] ) ) . ' months' ) );
		}
		$posts = self::posts_by_activity( $cat );
		$rows  = array_slice( self::rows( $posts, Db::today(), $until ), 0, $limit );
		if ( ! $rows ) {
			return Frontend\Views::shell( $bar . '<p class="asemf-muted">Nessun evento in programma.</p>', 'asemf-events' );
		}
		$html  = '';
		$month = '';
		$acts  = array();
		foreach ( $rows as $s ) {
			$aid = (int) $s['activity_id'];
			if ( ! isset( $acts[ $aid ] ) ) {
				$acts[ $aid ] = Plugin::activities()->get( $aid );
			}
			$a = $acts[ $aid ];
			if ( ! $a ) {
				continue;
			}
			$ts = (int) strtotime( (string) $s['session_date'] . ' 12:00:00' );
			$m  = wp_date( 'Y-m', $ts );
			if ( $m !== $month ) {
				$html .= ( '' === $month ? '' : '</ul>' ) . '<h3 class="asemf-month">' . esc_html( ucfirst( wp_date( 'F Y', $ts ) ) ) . '</h3><ul class="asemf-cards">';
				$month = $m;
			}
			$html .= self::card( $posts[ $aid ], $a, $s, $ts );
		}
		return Frontend\Views::shell( $bar . $html . '</ul>', 'asemf-events' );
	}

	/** Contenuti pubblicati, per id dell'attività (facoltativo: solo di una categoria). @return \WP_Post[] */
	private static function posts_by_activity( string $cat ): array {
		$query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		if ( '' !== $cat ) {
			$query['tax_query'] = array( array( 'taxonomy' => self::TAXONOMY, 'field' => 'slug', 'terms' => $cat ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$posts = array();
		foreach ( get_posts( $query ) as $p ) {
			$aid = self::activity_of( (int) $p->ID );
			if ( $aid && ! isset( $posts[ $aid ] ) ) {
				$posts[ $aid ] = $p;
			}
		}
		return $posts;
	}

	/** Date non annullate degli eventi pubblicati, tra due giorni (inclusi). */
	private static function rows( array $posts, string $from, string $to ): array {
		if ( ! $posts ) {
			return array();
		}
		return array_values(
			array_filter(
				Plugin::activities()->sessions_between( $from, $to, 500 ),
				function ( $s ) use ( $posts ) {
					return isset( $posts[ (int) $s['activity_id'] ] );
				}
			)
		);
	}

	/** Indirizzo della pagina corrente con i filtri indicati. */
	private static function view_url( array $args ): string {
		$base = (string) get_permalink();
		return add_query_arg( array_filter( $args, 'strlen' ), '' !== $base ? $base : home_url( '/' ) );
	}

	/** Barra dei filtri: scelta della vista e categorie. */
	private static function filter_bar( string $view, string $cat, string $ym ): string {
		$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => true ) );
		$month = 'mese' === $view ? ( '' !== $ym ? $ym : wp_date( 'Y-m' ) ) : '';
		$h     = '<nav class="asemf-filters" aria-label="Filtri degli eventi">';
		$h    .= '<span class="asemf-filter-group">'
			. '<a class="asemf-chip' . ( 'elenco' === $view ? ' is-on' : '' ) . '" href="' . esc_url( self::view_url( array( 'asem_cat' => $cat ) ) ) . '">Elenco</a>'
			. '<a class="asemf-chip' . ( 'mese' === $view ? ' is-on' : '' ) . '" href="' . esc_url( self::view_url( array( 'asem_cat' => $cat, 'asem_mese' => '' !== $ym ? $ym : wp_date( 'Y-m' ) ) ) ) . '">Calendario</a></span>';
		if ( $terms && ! is_wp_error( $terms ) ) {
			$h .= '<span class="asemf-filter-group"><a class="asemf-chip' . ( '' === $cat ? ' is-on' : '' ) . '" href="' . esc_url( self::view_url( array( 'asem_mese' => $month ) ) ) . '">Tutte le categorie</a>';
			foreach ( $terms as $t ) {
				$h .= '<a class="asemf-chip' . ( $cat === $t->slug ? ' is-on' : '' ) . '" href="' . esc_url( self::view_url( array( 'asem_cat' => $t->slug, 'asem_mese' => $month ) ) ) . '">' . esc_html( $t->name ) . '</a>';
			}
			$h .= '</span>';
		}
		return $h . '</nav>';
	}

	/** Calendario di un mese (Y-m; vuoto = mese in corso): gli eventi nei giorni, su telefono solo i giorni con eventi. */
	private static function calendar( string $cat, string $ym ): string {
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $ym ) ) {
			$ym = wp_date( 'Y-m' );
		}
		$first = (int) strtotime( $ym . '-01 12:00:00' );
		$days  = (int) gmdate( 't', $first );
		$last  = $ym . '-' . sprintf( '%02d', $days );
		$posts = self::posts_by_activity( $cat );
		$by    = array(); // giorno => date
		foreach ( self::rows( $posts, $ym . '-01', $last ) as $s ) {
			$by[ (string) $s['session_date'] ][] = $s;
		}
		$prev = gmdate( 'Y-m', (int) strtotime( $ym . '-01 -1 month' ) );
		$next = gmdate( 'Y-m', (int) strtotime( $ym . '-01 +1 month' ) );
		$h    = '<div class="asemf-cal-nav"><a class="asemf-chip" href="' . esc_url( self::view_url( array( 'asem_cat' => $cat, 'asem_mese' => $prev ) ) ) . '">← ' . esc_html( ucfirst( wp_date( 'F', (int) strtotime( $prev . '-01 12:00:00' ) ) ) ) . '</a>'
			. '<h3 class="asemf-month">' . esc_html( ucfirst( wp_date( 'F Y', $first ) ) ) . '</h3>'
			. '<a class="asemf-chip" href="' . esc_url( self::view_url( array( 'asem_cat' => $cat, 'asem_mese' => $next ) ) ) . '">' . esc_html( ucfirst( wp_date( 'F', (int) strtotime( $next . '-01 12:00:00' ) ) ) ) . ' →</a></div>';
		$h   .= '<table class="asemf-cal"><caption class="screen-reader-text">Eventi di ' . esc_html( wp_date( 'F Y', $first ) ) . '</caption><thead><tr>';
		foreach ( array( 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom' ) as $d ) {
			$h .= '<th scope="col">' . $d . '</th>';
		}
		$h     .= '</tr></thead><tbody>';
		$offset = (int) gmdate( 'N', $first ) - 1;
		$cells  = (int) ceil( ( $offset + $days ) / 7 ) * 7;
		$today  = Db::today();
		for ( $i = 0; $i < $cells; $i++ ) {
			if ( 0 === $i % 7 ) {
				$h .= '<tr>';
			}
			$day = $i - $offset + 1;
			if ( $day < 1 || $day > $days ) {
				$h .= '<td class="asemf-cal-out"></td>';
			} else {
				$date = $ym . '-' . sprintf( '%02d', $day );
				$list = $by[ $date ] ?? array();
				$cls  = 'asemf-cal-day' . ( $list ? ' has-events' : ' asemf-cal-empty' ) . ( $date === $today ? ' is-today' : '' );
				$h   .= '<td class="' . $cls . '"><span class="asemf-cal-num">' . (int) $day . '<span class="asemf-cal-wd"> ' . esc_html( wp_date( 'D', (int) strtotime( $date . ' 12:00:00' ) ) ) . '</span></span>';
				foreach ( $list as $s ) {
					$p  = $posts[ (int) $s['activity_id'] ];
					$h .= '<a class="asemf-cal-ev" href="' . esc_url( (string) get_permalink( $p ) ) . '">' . ( ! empty( $s['start_time'] ) ? '<span class="asemf-cal-time">' . esc_html( (string) $s['start_time'] ) . '</span> ' : '' ) . esc_html( get_the_title( $p ) ) . '</a>';
				}
				$h .= '</td>';
			}
			if ( 6 === $i % 7 ) {
				$h .= '</tr>';
			}
		}
		$h .= '</tbody></table>';
		if ( ! $by ) {
			$h .= '<p class="asemf-muted">Nessun evento in questo mese.</p>';
		}
		return $h;
	}

	/** Una card: badge della data, locandina, categoria, titolo, orario, luogo, contributo. */
	private static function card( \WP_Post $post, array $a, array $s, int $ts ): string {
		$url   = (string) get_permalink( $post );
		$cats  = get_the_terms( $post, self::TAXONOMY );
		$cat   = ( $cats && ! is_wp_error( $cats ) ) ? (string) $cats[0]->name : '';
		$where = ! empty( $s['location'] ) ? (string) $s['location'] : (string) ( $a['location'] ?? '' );
		$fee   = (int) $a['fee_cents'] > 0 ? Money::format( (int) $a['fee_cents'] ) : ( null !== $a['guest_fee_cents'] && (int) $a['guest_fee_cents'] > 0 ? 'Ospiti: ' . Money::format( (int) $a['guest_fee_cents'] ) : 'Gratuito' );
		$time  = ! empty( $s['start_time'] ) ? ( ! empty( $s['end_time'] ) ? $s['start_time'] . '–' . $s['end_time'] : $s['start_time'] ) : '';
		$h     = '<li class="asemf-card' . ( has_post_thumbnail( $post ) ? ' has-poster' : '' ) . '">';
		$h    .= '<a class="asemf-card-date" href="' . esc_url( $url ) . '" aria-hidden="true" tabindex="-1"><span class="asemf-card-day">' . esc_html( wp_date( 'j', $ts ) ) . '</span><span class="asemf-card-mon">' . esc_html( wp_date( 'M', $ts ) ) . '</span></a>';
		if ( has_post_thumbnail( $post ) ) {
			$h .= '<a class="asemf-card-poster" href="' . esc_url( $url ) . '" aria-hidden="true" tabindex="-1">' . get_the_post_thumbnail( $post, 'medium' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$h .= '<div class="asemf-card-body">';
		if ( '' !== $cat ) {
			$h .= '<div class="asemf-card-cat">' . esc_html( $cat ) . '</div>';
		}
		$h .= '<h4 class="asemf-card-title"><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h4>';
		$h .= '<div class="asemf-small">' . esc_html( ucfirst( wp_date( 'l j F', $ts ) ) . ( '' !== $time ? ' · ore ' . $time : '' ) ) . '</div>';
		if ( '' !== $where ) {
			$h .= '<div class="asemf-small">' . esc_html( $where ) . '</div>';
		}
		$ex = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
		if ( '' !== $ex ) {
			$h .= '<p class="asemf-card-ex">' . esc_html( wp_trim_words( $ex, 28 ) ) . '</p>';
		}
		$h .= '<div class="asemf-card-foot"><span class="asemf-card-price">' . esc_html( $fee ) . '</span><a class="asemf-btn wp-element-button" href="' . esc_url( $url ) . '">Dettagli e prenotazione</a></div>';
		return $h . '</div></li>';
	}

	// ---------- Calendario (.ics) e dati strutturati ----------

	/** Date prenotabili o in programma dell'evento. @return array[] */
	private static function upcoming_sessions( int $activity_id ): array {
		return array_values( array_filter( Plugin::activities()->sessions( $activity_id ), function ( $s ) {
			return empty( $s['cancelled_at'] ) && (string) $s['session_date'] >= Db::today();
		} ) );
	}

	public static function maybe_ics(): void {
		if ( ! is_singular( self::POST_TYPE ) || empty( $_GET['asem_ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post = get_queried_object();
		$aid  = $post ? self::activity_of( (int) $post->ID ) : 0;
		$a    = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( ! $a || ! ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
			return;
		}
		$events = array();
		foreach ( self::upcoming_sessions( $aid ) as $s ) {
			$events[] = array(
				'uid'         => 'asem-evento-' . (int) $s['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'summary'     => (string) $post->post_title,
				'date'        => (string) $s['session_date'],
				'start'       => ! empty( $s['start_time'] ) ? (string) $s['start_time'] : null,
				'end'         => ! empty( $s['end_time'] ) ? (string) $s['end_time'] : null,
				'location'    => (string) ( $s['location'] ?? '' ),
				'description' => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
				'url'         => (string) get_permalink( $post ),
			);
		}
		if ( ! $events ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name ?: 'evento' ) . '.ics"' );
		echo Ics::calendar( (string) $post->post_title, $events, wp_timezone_string(), gmdate( 'Ymd\THis\Z' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Dati strutturati schema.org/Event per i motori di ricerca. */
	public static function structured_data(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}
		$post = get_queried_object();
		$aid  = $post ? self::activity_of( (int) $post->ID ) : 0;
		$a    = $aid ? Plugin::activities()->get( $aid ) : null;
		if ( ! $a || ! ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
			return;
		}
		$rows = self::upcoming_sessions( $aid );
		if ( ! $rows ) {
			return;
		}
		$s   = $rows[0];
		$tz  = wp_timezone();
		$iso = function ( string $date, ?string $time ) use ( $tz ): string {
			try {
				return ( new \DateTimeImmutable( $date . ' ' . ( $time ?: '00:00' ), $tz ) )->format( \DateTimeInterface::ATOM );
			} catch ( \Exception $e ) {
				return $date;
			}
		};
		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => (string) $post->post_title,
			'startDate'           => $iso( (string) $s['session_date'], $s['start_time'] ?? null ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'url'                 => (string) get_permalink( $post ),
		);
		if ( ! empty( $s['end_time'] ) ) {
			$data['endDate'] = $iso( (string) $s['session_date'], (string) $s['end_time'] );
		}
		if ( ! empty( $s['location'] ) ) {
			$data['location'] = array( '@type' => 'Place', 'name' => (string) $s['location'], 'address' => (string) $s['location'] );
		}
		$img = get_the_post_thumbnail_url( $post, 'large' );
		if ( $img ) {
			$data['image'] = $img;
		}
		$desc = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		if ( '' !== $desc ) {
			$data['description'] = $desc;
		}
		$org = (string) Settings::get( 'association_name' );
		if ( '' !== $org ) {
			$data['organizer'] = array( '@type' => 'Organization', 'name' => $org, 'url' => home_url( '/' ) );
		}
		$cap   = null === $s['capacity'] ? null : (int) $s['capacity'];
		$full  = null !== $cap && (int) ( $s['booked_count'] ?? 0 ) >= $cap;
		$data['offers'] = array(
			'@type'         => 'Offer',
			'price'         => number_format( ( (int) $a['fee_cents'] ) / 100, 2, '.', '' ),
			'priceCurrency' => 'EUR',
			'availability'  => $full ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
			'url'           => (string) get_permalink( $post ),
		);
		wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ), array( 'type' => 'application/ld+json' ) );
	}
}
