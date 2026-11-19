<?php
namespace AssociazioneSemplice\Frontend;

use AssociazioneSemplice\Access;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * Contenuti riservati ai soci: pagine e articoli interi (riquadro "Accesso" nell'editor) e singole parti di pagina
 * (shortcode / blocco / widget "Contenuto riservato"). Le regole sono in {@see Visibility}.
 *
 * Esempio: la pagina dell'evento del corso è pubblica; il "programma della prima lezione" è visibile solo
 * ai soci iscritti a quell'attività.
 */
final class Restrict {

	const META_RULE       = '_asem_access';
	const META_ACTIVITIES = '_asem_access_activities';

	public static function register(): void {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		add_filter( 'the_excerpt', array( __CLASS__, 'filter_excerpt' ), 999 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_get_excerpt' ), 999, 2 );
		add_filter( 'the_content_feed', array( __CLASS__, 'filter_content' ), 999 );
		add_action( 'template_redirect', array( __CLASS__, 'early_no_cache' ), 0 ); // prima di disegnare: le intestazioni si possono ancora mandare
		// Gli altri canali da cui il testo riservato potrebbe uscire: ricerca (anche REST), commenti, allegati, descrizioni dei plugin SEO.
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_search' ) );
		add_filter( 'comments_open', array( __CLASS__, 'filter_comments_open' ), 10, 2 );
		add_filter( 'comments_array', array( __CLASS__, 'filter_comments_array' ), 10, 2 );
		add_filter( 'get_comments_number', array( __CLASS__, 'filter_comments_number' ), 10, 2 );
		add_filter( 'the_comments', array( __CLASS__, 'filter_comment_list' ), 10, 2 );
		add_filter( 'rest_prepare_comment', array( __CLASS__, 'filter_rest_comment' ), 10, 2 );
		add_filter( 'rest_prepare_attachment', array( __CLASS__, 'filter_rest_attachment' ), 10, 2 );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'filter_rest_attachment_query' ) );
		foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description', 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_description', 'seopress_titles_desc', 'seopress_social_og_desc', 'aioseo_description' ) as $seo_filter ) {
			add_filter( $seo_filter, array( __CLASS__, 'filter_seo_description' ) );
		}
		add_action( 'init', array( __CLASS__, 'hook_post_types' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_shortcode( 'associazionesemplice_riservato', array( __CLASS__, 'shortcode' ) );
	}

	/** Tipi di contenuto che si possono riservare: tutti quelli pubblici (pagine, articoli, tipi personalizzati). */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	public static function hook_post_types(): void {
		foreach ( self::post_types() as $type ) {
			add_filter( "rest_prepare_$type", array( __CLASS__, 'filter_rest' ), 10, 2 );
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'print_column' ), 10, 2 );
		}
	}

	// ---------- Regole e permessi ----------

	/**
	 * Le pagine che cambiano con chi le guarda non vanno nella cache di pagina: quelle riservate (per chiunque le apra: il riquadro «accedi» e il contenuto
	 * non devono scambiarsi) e ogni pagina vista con una sessione socio valida (chi entra con l'accesso proprio non ha il cookie di WordPress che le cache riconoscono).
	 */
	public static function early_no_cache(): void {
		$rule = Visibility::PUBLIC_;
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
				list( $rule ) = self::rule_of( $id );
			}
		}
		if ( \AssociazioneSemplice\CachePolicy::skip_page_cache( $rule, null !== \AssociazioneSemplice\MemberSession::session_person() ) ) {
			Views::no_cache();
		}
	}

	/** Un contenuto o una parte con regola diversa da «pubblico» cambia con chi lo guarda: mai nella cache di pagina. */
	private static function keep_out_of_cache( string $rule ): void {
		if ( \AssociazioneSemplice\CachePolicy::skip_page_cache( $rule, false ) ) {
			Views::no_cache();
		}
	}

	/** @return array [regola, id attività] di un contenuto */
	public static function rule_of( int $post_id ): array {
		$post = get_post( $post_id );
		if ( $post && 'attachment' === $post->post_type && (int) $post->post_parent > 0 ) {
			$post_id = (int) $post->post_parent; // un allegato segue la regola del contenuto a cui è allegato
		}
		$rule = (string) get_post_meta( $post_id, self::META_RULE, true );
		if ( ! Visibility::is_valid( $rule ) ) {
			$rule = Visibility::PUBLIC_;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $post_id, self::META_ACTIVITIES, true ) ) ) );
		return array( $rule, $ids );
	}

	/** Dati dell'utente per {@see Visibility::decide()}. */
	public static function context( ?int $user_id = null, array $required_activity_ids = array() ): array {
		$uid    = $user_id ?? get_current_user_id();
		$person = $uid > 0 ? Access::person_for_user( $uid ) : null;
		if ( null === $user_id && $uid <= 0 ) {
			$person = \AssociazioneSemplice\MemberSession::session_person(); // chi è entrato con l'accesso proprio (senza utente WordPress)
		}
		$ctx    = array(
			'is_admin'              => Access::is_admin_user( $uid ),
			'logged_in'             => $uid > 0 || null !== $person,
			'member_area_allowed'   => Edition::allows( 'member_area' ),
			'active_member'         => false,
			'person_type'           => $person ? $person['type'] : null,
			'required_activity_ids' => $required_activity_ids,
			'my_activity_ids'       => array(),
			'taught_activity_ids'   => array(),
		);
		if ( $person ) {
			$ctx['active_member']       = MemberType::is_member( $person['type'] ) && Plugin::people()->is_active_member( (int) $person['id'] );
			$ctx['my_activity_ids']     = Plugin::activities()->person_activity_ids( (int) $person['id'] );
			$ctx['taught_activity_ids'] = Plugin::activities()->taught_activity_ids( (int) $person['id'] );
		}
		return $ctx;
	}

	public static function allowed( string $rule, array $activity_ids = array(), ?int $user_id = null ): bool {
		if ( Visibility::PUBLIC_ === $rule ) {
			return true;
		}
		return Visibility::decide( $rule, self::context( $user_id, $activity_ids ) );
	}

	public static function can_view_post( int $post_id, ?int $user_id = null ): bool {
		list( $rule, $ids ) = self::rule_of( $post_id );
		return self::allowed( $rule, $ids, $user_id );
	}


	/** Indirizzo della pagina corrente (per tornarci dopo il login). */
	public static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$uri = '' !== $uri && '/' === $uri[0] && 0 !== strpos( $uri, '//' ) ? esc_url_raw( $uri ) : ''; // solo un percorso del sito
		$p   = wp_parse_url( home_url( '/' ) );
		if ( '' === $uri || empty( $p['host'] ) ) {
			return home_url( '/' );
		}
		// L'indirizzo del sito, non l'intestazione Host della richiesta (la può scrivere chi fa la richiesta e finire in link e cache).
		return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $uri;
	}
	// ---------- Messaggio al posto del contenuto ----------

	private static function activity_names( array $ids ): array {
		$names = array();
		foreach ( $ids as $id ) {
			$a = Plugin::activities()->get( (int) $id );
			if ( $a ) {
				$names[] = $a['name'];
			}
		}
		return $names;
	}

	/** Riquadro mostrato a chi non può vedere il contenuto. */
	public static function gate_html( string $rule, array $activity_ids, string $return_url = '', string $custom_message = '' ): string {
		Assets::enqueue();
		$ctx    = self::context( null, $activity_ids );
		$reason = Visibility::denial_reason( $ctx );
		if ( '' !== $custom_message ) {
			$text = $custom_message;
		} elseif ( '' !== (string) Settings::get( 'gate_message' ) && 'suspended' !== $reason ) {
			$text = (string) Settings::get( 'gate_message' );
		} elseif ( 'suspended' === $reason ) {
			$text = 'Questo contenuto non è al momento disponibile.';
		} elseif ( Visibility::ACTIVITY === $rule && $activity_ids ) {
			$text = 'Contenuto riservato ai soci iscritti a: ' . implode( ', ', self::activity_names( $activity_ids ) ) . '.';
		} elseif ( Visibility::VOLUNTEERS === $rule ) {
			$text = 'Contenuto riservato ai soci volontari.';
		} else {
			$text = 'Contenuto riservato ai soci.';
		}
		$html = '<div class="asemf asemf-gate"><p class="asemf-gate-text">🔒 ' . esc_html( $text ) . '</p>';
		if ( 'login' === $reason ) {
			$url   = wp_login_url( '' !== $return_url ? $return_url : self::current_url() );
			$html .= '<p><a class="asemf-btn wp-element-button" href="' . esc_url( $url ) . '">Entra</a></p>';
		}
		$html .= '</div>';
		return wp_kses( (string) apply_filters( 'asem_gate_html', \AssociazioneSemplice\Texts::html( $html ), $rule, $activity_ids ), \AssociazioneSemplice\Html::allowed() ); // esce dal filtro the_content, dallo shortcode e dal blocco: sempre filtrato
	}

	// ---------- Filtri sul contenuto ----------

	public static function filter_content( $content ) {
		$id = get_the_ID();
		if ( ! $id ) {
			return $content;
		}
		list( $rule, $ids ) = self::rule_of( (int) $id );
		self::keep_out_of_cache( $rule );
		if ( Visibility::PUBLIC_ === $rule || self::allowed( $rule, $ids ) ) {
			return $content;
		}
		return self::gate_html( $rule, $ids, (string) get_permalink( $id ) );
	}

	public static function filter_excerpt( $excerpt ) {
		$id = get_the_ID();
		if ( $id ) {
			self::keep_out_of_cache( self::rule_of( (int) $id )[0] );
		}
		return $id && ! self::can_view_post( (int) $id ) ? '' : $excerpt;
	}

	public static function filter_get_excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( $post ) {
			self::keep_out_of_cache( self::rule_of( (int) $post->ID )[0] );
		}
		return $post && ! self::can_view_post( (int) $post->ID ) ? '' : $excerpt;
	}

	/** Anche l'API REST non deve rivelare il contenuto riservato. */
	public static function filter_rest( $response, $post ) {
		if ( self::can_view_post( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) ) {
			$data['content']['rendered']  = '';
			$data['content']['protected'] = true;
			unset( $data['content']['raw'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$data['excerpt']['rendered']  = '';
			$data['excerpt']['protected'] = true;
		}
		$data['asem_restricted'] = true;
		$response->set_data( $data );
		return $response;
	}

	// ---------- Ricerca, commenti, allegati, anteprime ----------

	private static bool $listing = false;

	/**
	 * Contenuti riservati che chi guarda non può leggere, con gli allegati che ne dipendono.
	 * Servono a escluderli dalla ricerca: altrimenti un visitatore scopre quali contenuti contengono una parola anche senza leggerli.
	 *
	 * @return int[]
	 */
	public static function hidden_post_ids(): array {
		if ( self::$listing ) {
			return array();
		}
		self::$listing = true;
		$ids           = get_posts(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'orderby'                => 'none',
				'update_post_term_cache' => false,
				'meta_query'             => array( array( 'key' => self::META_RULE, 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$hidden        = array();
		foreach ( $ids as $id ) {
			if ( ! self::can_view_post( (int) $id ) ) {
				$hidden[] = (int) $id;
			}
		}
		if ( $hidden ) {
			$children = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'any',
					'post_parent__in'        => $hidden,
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'orderby'                => 'none',
					'update_post_term_cache' => false,
				)
			);
			$hidden   = array_merge( $hidden, array_map( 'intval', $children ) );
		}
		self::$listing = false;
		return $hidden;
	}

	/** Chi non può leggere un contenuto riservato non lo trova nemmeno cercando una parola del suo testo (ricerca del sito, REST, ricerche in ajax). */
	public static function exclude_from_search( $query ): void {
		if ( ! $query instanceof \WP_Query || '' === trim( (string) $query->get( 's' ) ) ) {
			return;
		}
		if ( is_admin() && current_user_can( 'edit_others_posts' ) ) {
			return; // chi gestisce i contenuti li cerca tutti nell'area di amministrazione
		}
		$hidden = self::hidden_post_ids();
		if ( $hidden ) {
			$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) ) ) );
		}
	}

	public static function filter_comments_open( $open, $post_id = 0 ) {
		return $open && ( (int) $post_id <= 0 || self::can_view_post( (int) $post_id ) );
	}

	public static function filter_comments_array( $comments, $post_id = 0 ) {
		return (int) $post_id > 0 && ! self::can_view_post( (int) $post_id ) ? array() : $comments;
	}

	public static function filter_comments_number( $count, $post_id = 0 ) {
		return (int) $post_id > 0 && ! self::can_view_post( (int) $post_id ) ? 0 : $count;
	}

	/** Elenchi di commenti (feed, widget, REST): spariscono quelli dei contenuti riservati che chi guarda non può leggere. */
	public static function filter_comment_list( $comments, $query = null ) {
		if ( ! is_array( $comments ) || ( is_admin() && current_user_can( 'moderate_comments' ) ) ) {
			return $comments;
		}
		$seen = array();
		return array_values(
			array_filter(
				$comments,
				function ( $c ) use ( &$seen ) {
					$c   = is_object( $c ) ? $c : get_comment( (int) $c );
					$pid = $c ? (int) $c->comment_post_ID : 0;
					if ( $pid <= 0 ) {
						return true;
					}
					if ( ! isset( $seen[ $pid ] ) ) {
						$seen[ $pid ] = self::can_view_post( $pid );
					}
					return $seen[ $pid ];
				}
			)
		);
	}

	public static function filter_rest_comment( $response, $comment ) {
		if ( ! $comment instanceof \WP_Comment || self::can_view_post( (int) $comment->comment_post_ID ) || current_user_can( 'moderate_comments' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) ) {
			$data['content'] = array( 'rendered' => '', 'protected' => true );
		}
		$data['asem_restricted'] = true;
		$response->set_data( $data );
		return $response;
	}

	/** Gli allegati di un contenuto riservato non descrivono né indicano il file a chi non può leggerlo. */
	public static function filter_rest_attachment( $response, $post ) {
		if ( ! $post instanceof \WP_Post || self::can_view_post( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'description', 'caption', 'title' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$data[ $k ] = array( 'rendered' => '' );
			}
		}
		$data['alt_text']        = '';
		$data['source_url']      = '';
		$data['media_details']   = array();
		$data['asem_restricted'] = true;
		$response->set_data( $data );
		return $response;
	}

	public static function filter_rest_attachment_query( $args ) {
		$hidden = self::hidden_post_ids();
		if ( $hidden && is_array( $args ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- pochi elementi: i contenuti riservati che chi guarda non può leggere
			$args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), $hidden ) ) );
		}
		return $args;
	}

	/** Descrizione (meta description, anteprime social) scritta da un plugin SEO: vuota per un contenuto riservato che chi guarda non può leggere. */
	public static function filter_seo_description( $desc ) {
		return is_singular() ? self::description_for( (string) $desc, (int) get_queried_object_id() ) : $desc;
	}

	public static function description_for( string $desc, int $post_id ): string {
		return $post_id > 0 && ! self::can_view_post( $post_id ) ? '' : $desc;
	}

	// ---------- Riquadro nell'editor ----------

	public static function add_meta_box(): void {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'asem_access', 'Accesso (Associazione Semplice)', array( __CLASS__, 'render_meta_box' ), $type, 'side', 'default' );
		}
	}

	public static function render_meta_box( \WP_Post $post ): void {
		list( $rule, $ids ) = self::rule_of( (int) $post->ID );
		wp_nonce_field( 'asem_access_save', 'asem_access_nonce' );
		echo '<p class="description">Chi può leggere questo contenuto?</p>';
		foreach ( Visibility::labels() as $value => $label ) {
			echo '<p style="margin:4px 0"><label><input type="radio" name="asem_access" value="' . esc_attr( $value ) . '"' . checked( $rule, $value, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '<div id="asem-access-activities" style="margin-top:8px"><label for="asem-access-activities-select">Attività:</label><br>';
		echo '<select multiple size="6" style="width:100%" id="asem-access-activities-select" name="asem_access_activities[]">';
		foreach ( Plugin::activities()->all_for_select() as $a ) {
			echo '<option value="' . (int) $a['id'] . '"' . selected( in_array( (int) $a['id'], $ids, true ), true, false ) . '>' . esc_html( $a['name'] . ' (' . $a['social_year'] . ')' ) . '</option>';
		}
		echo '</select><p class="description">Lo vede chi è iscritto o prenotato ad almeno una delle attività scelte, e chi ne è referente.</p></div>';
		echo '<p class="description">Gli amministratori vedono sempre tutto.</p>';
		\AssociazioneSemplice\Admin\Ui::inline_js( '(function(){var box=document.getElementById("asem-access-activities");if(!box){return;}var radios=document.querySelectorAll("input[name=asem_access]");function u(){var v="";radios.forEach(function(r){if(r.checked){v=r.value;}});box.style.display=v==="activity"?"":"none";}radios.forEach(function(r){r.addEventListener("change",u);});u();})();' );
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['asem_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asem_access_nonce'] ) ), 'asem_access_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$rule = isset( $_POST['asem_access'] ) ? sanitize_key( wp_unslash( $_POST['asem_access'] ) ) : Visibility::PUBLIC_;
		if ( ! Visibility::is_valid( $rule ) ) {
			$rule = Visibility::PUBLIC_;
		}
		$ids = isset( $_POST['asem_access_activities'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['asem_access_activities'] ) ) ) ) : array();
		if ( Visibility::PUBLIC_ === $rule ) {
			delete_post_meta( $post_id, self::META_RULE );
			delete_post_meta( $post_id, self::META_ACTIVITIES );
			return;
		}
		update_post_meta( $post_id, self::META_RULE, $rule );
		update_post_meta( $post_id, self::META_ACTIVITIES, Visibility::ACTIVITY === $rule ? $ids : array() );
	}

	// ---------- Colonna "Accesso" negli elenchi ----------

	public static function add_column( $columns ) {
		$columns['asem_access'] = 'Accesso';
		return $columns;
	}

	public static function print_column( $column, $post_id ): void {
		if ( 'asem_access' !== $column ) {
			return;
		}
		list( $rule, $ids ) = self::rule_of( (int) $post_id );
		echo Visibility::PUBLIC_ === $rule ? '—' : '🔒 ' . esc_html( Visibility::short_label( $rule, self::activity_names( $ids ) ) );
	}

	// ---------- Parti di pagina: shortcode, blocco, widget ----------

	private static function rule_from_text( string $t ): string {
		$map = array(
			'soci' => Visibility::MEMBERS, 'members' => Visibility::MEMBERS,
			'volontari' => Visibility::VOLUNTEERS, 'volunteers' => Visibility::VOLUNTEERS,
			'attivita' => Visibility::ACTIVITY, 'attività' => Visibility::ACTIVITY, 'activity' => Visibility::ACTIVITY,
		);
		return $map[ strtolower( trim( $t ) ) ] ?? Visibility::MEMBERS;
	}

	/** [associazionesemplice_riservato accesso="soci|volontari|attivita" attivita="12,13" messaggio="..."]contenuto[/associazionesemplice_riservato] */
	public static function shortcode( $atts, $content = '' ): string {
		$a = shortcode_atts( array( 'accesso' => 'soci', 'attivita' => '', 'messaggio' => '' ), (array) $atts, 'associazionesemplice_riservato' );
		$ids = array_values( array_filter( array_map( 'intval', preg_split( '/[\s,;]+/', (string) $a['attivita'] ) ?: array() ) ) );
		$rule = self::rule_from_text( (string) $a['accesso'] );
		self::keep_out_of_cache( $rule );
		if ( ! self::allowed( $rule, $ids ) ) {
			return self::gate_html( $rule, $ids, '', (string) $a['messaggio'] ); // gli shortcode dentro non vengono nemmeno eseguiti
		}
		return do_shortcode( (string) $content );
	}

	/** Mostra $content a chi può vederlo, altrimenti il riquadro "riservato". Usato da shortcode, blocco e widget. */
	public static function render_reserved( string $rule, array $activity_ids, string $content, string $message = '' ): string {
		self::keep_out_of_cache( $rule );
		if ( self::allowed( $rule, $activity_ids ) ) {
			return $content;
		}
		return self::gate_html( $rule, $activity_ids, '', $message );
	}
}
