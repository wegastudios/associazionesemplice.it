<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Importa gli eventi di The Events Calendar (contenuti `tribe_events`) come eventi una tantum, passati e futuri, per poterli riproporre
 * senza rifare tutto. Si legge dal database del sito: The Events Calendar può anche essere disattivato. Non si importano costi
 * e prenotazioni; immagine, categoria, descrizione, organizzatore e sito vanno nella pagina pubblica dell'evento. Ogni evento importato
 * ricorda il contenuto di origine, quindi si può rilanciare senza doppioni.
 */
final class TribeImport {

	const POST_TYPE = 'tribe_events';
	const META_DONE = '_asem_activity';

	/** @return string[] stati dei contenuti da importare (non il cestino né le bozze automatiche) */
	private static function statuses(): array {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/** Testo semplice da un contenuto HTML: senza tag né spazi doppi. */
	public static function plain( string $html ): string {
		$html = preg_replace( '#<(br|/p|/li|/h[1-6])\s*/?>#i', "\n", $html ) ?? $html;
		$text = html_entity_decode( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html ), ENT_QUOTES, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- senza WordPress (prove unitarie) manca wp_strip_all_tags
		$text = preg_replace( "/[ \t]+/u", ' ', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", trim( $text ) ) ?? $text;
		return trim( $text );
	}

	/**
	 * Un evento di The Events Calendar come dati per il plugin.
	 *
	 * @param array  $meta    _EventStartDate, _EventEndDate, _EventAllDay, _EventURL
	 * @param string $title   titolo
	 * @param string $content descrizione (HTML)
	 * @param string $venue   luogo già composto (nome, indirizzo)
	 * @return array|null name, notes, session[session_date, start_time, end_time, location]; null se manca una data valida
	 */
	public static function map_event( array $meta, string $title, string $content, string $venue ): ?array {
		$start = (string) ( $meta['_EventStartDate'] ?? '' );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))/', $start, $m ) ) {
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})/', $start, $m ) ) {
				return null;
			}
			$m[2] = null;
		}
		$date   = $m[1];
		$allday = 'yes' === (string) ( $meta['_EventAllDay'] ?? '' );
		$time   = $allday || empty( $m[2] ) ? null : $m[2];
		$end    = (string) ( $meta['_EventEndDate'] ?? '' );
		$until  = null;
		$notes  = array();
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?/', $end, $e ) ) {
			if ( $e[1] === $date ) {
				if ( ! $allday && isset( $e[2] ) && null !== $time && $e[2] > $time ) {
					$until = $e[2];
				}
			} elseif ( $e[1] > $date ) {
				$notes[] = 'Dal ' . ( new \DateTimeImmutable( $date ) )->format( 'd/m/Y' ) . ' al ' . ( new \DateTimeImmutable( $e[1] ) )->format( 'd/m/Y' ) . '.';
			}
		}
		$body = self::plain( $content );
		if ( '' !== $body ) {
			$notes[] = $body;
		}
		$url = trim( (string) ( $meta['_EventURL'] ?? '' ) );
		if ( '' !== $url ) {
			$notes[] = 'Sito: ' . $url;
		}
		$name = trim( self::plain( $title ) );
		return array(
			'name'    => mb_substr( '' !== $name ? $name : 'Evento senza titolo', 0, 160 ),
			'notes'   => $notes ? mb_substr( implode( "\n\n", $notes ), 0, 8000 ) : null,
			'session' => array( 'session_date' => $date, 'start_time' => $time, 'end_time' => $until, 'location' => '' !== trim( $venue ) ? mb_substr( trim( $venue ), 0, 190 ) : null ),
		);
	}

	/** Il luogo di un evento: nome, indirizzo e città del `tribe_venue` collegato. */
	private static function venue( int $post_id ): string {
		$vid = (int) get_post_meta( $post_id, '_EventVenueID', true );
		if ( $vid < 1 ) {
			return '';
		}
		$parts = array_filter( array( trim( (string) get_the_title( $vid ) ), trim( (string) get_post_meta( $vid, '_VenueAddress', true ) ), trim( (string) get_post_meta( $vid, '_VenueCity', true ) ) ) );
		return implode( ', ', $parts );
	}

	const TAXONOMY = 'tribe_events_cat';

	/** La tassonomia delle categorie si legge anche con The Events Calendar disattivato: la si registra solo per questa richiesta. */
	private static function ensure_taxonomy(): void {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			register_taxonomy( self::TAXONOMY, array( self::POST_TYPE ), array( 'public' => false, 'show_ui' => false, 'rewrite' => false, 'query_var' => false, 'hierarchical' => true ) );
		}
	}

	/** @return int[] categorie di un evento */
	private static function cats_of( int $post_id ): array {
		self::ensure_taxonomy();
		$ids = wp_get_object_terms( $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		return is_wp_error( $ids ) ? array() : array_map( 'intval', (array) $ids );
	}

	/**
	 * Eventi trovati, con lo stato dell'importazione.
	 *
	 * @return array[] post_id, title, date, imported (id della nuova attività o 0), cats (categorie)
	 */
	public static function found(): array {
		$posts = get_posts(
			array(
				'post_type'     => self::POST_TYPE, // vale anche con The Events Calendar disattivato: il tipo non deve essere registrato
				'post_status'   => self::statuses(),
				'numberposts'   => -1,
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$id    = (int) $post->ID;
			$out[] = array(
				'post_id'  => $id,
				'title'    => (string) $post->post_title,
				'date'     => substr( (string) get_post_meta( $id, '_EventStartDate', true ), 0, 10 ),
				'imported' => (int) get_post_meta( $id, self::META_DONE, true ),
				'cats'     => self::cats_of( $id ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		return $out;
	}

	/**
	 * Le categorie degli eventi trovati, con quanti eventi hanno: servono a indicare quali sono corsi.
	 *
	 * @return array[] id, name, count, course (probabile corso: il nome parla di corsi o lezioni)
	 */
	public static function categories(): array {
		self::ensure_taxonomy();
		$count = array();
		foreach ( self::found() as $f ) {
			foreach ( $f['cats'] as $c ) {
				$count[ $c ] = ( $count[ $c ] ?? 0 ) + 1;
			}
		}
		$out = array();
		foreach ( $count as $id => $n ) {
			$t = get_term( (int) $id, self::TAXONOMY );
			if ( $t && ! is_wp_error( $t ) ) {
				$out[] = array( 'id' => (int) $id, 'name' => (string) $t->name, 'count' => $n, 'course' => 1 === preg_match( '/cors|lezion|laborator/i', (string) $t->name ) );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Un corso a partire dalle sue lezioni, cioè gli eventi di The Events Calendar con lo stesso titolo (di solito un evento ripetuto ogni settimana).
	 * I giorni e gli orari che si ripetono almeno 3 volte diventano una lezione settimanale (dalla prima all'ultima data); gli altri restano date singole.
	 *
	 * @param array[] $items date (Y-m-d), start, end (HH:MM o null), name, notes, venue
	 * @return array|null name, notes, location, starts_on, ends_on, lessons[]; null se non ci sono lezioni
	 */
	public static function map_course( array $items ): ?array {
		if ( ! $items ) {
			return null;
		}
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $a['date'] . (string) $a['start'], $b['date'] . (string) $b['start'] );
			}
		);
		$groups = array();
		$notes  = '';
		$venue  = '';
		foreach ( $items as $i ) {
			$day                                                                      = (int) ( new \DateTimeImmutable( $i['date'] ) )->format( 'N' );
			$groups[ $day . '|' . (string) $i['start'] . '|' . (string) $i['end'] ][] = $i;
			if ( mb_strlen( (string) $i['notes'] ) > mb_strlen( $notes ) ) {
				$notes = (string) $i['notes'];
			}
			if ( '' === $venue && '' !== trim( (string) $i['venue'] ) ) {
				$venue = trim( (string) $i['venue'] );
			}
		}
		$lessons = array();
		foreach ( $groups as $g ) {
			if ( count( $g ) >= 3 ) {
				$day       = (int) ( new \DateTimeImmutable( $g[0]['date'] ) )->format( 'N' );
				$lessons[] = array( 'type' => 'weekly', 'day' => $day, 'start' => $g[0]['start'], 'end' => $g[0]['end'], 'from' => $g[0]['date'], 'until' => $g[ count( $g ) - 1 ]['date'] );
				continue;
			}
			foreach ( $g as $i ) {
				$lessons[] = array( 'type' => 'single', 'date' => $i['date'], 'start' => $i['start'], 'end' => $i['end'] );
			}
		}
		return array(
			'name'      => $items[0]['name'],
			'notes'     => '' !== $notes ? $notes : null,
			'location'  => '' !== $venue ? mb_substr( $venue, 0, 190 ) : null,
			'starts_on' => $items[0]['date'],
			'ends_on'   => $items[ count( $items ) - 1 ]['date'],
			'lessons'   => $lessons,
		);
	}

	/**
	 * Importa tutti gli eventi non ancora importati: gli eventi delle categorie indicate come corsi diventano corsi (uno per titolo e anno
	 * sociale, con tutte le sue lezioni), gli altri eventi una tantum.
	 *
	 * Per ogni evento o corso importato si compila anche la sua pagina pubblica (descrizione, estratto, immagine in evidenza,
	 * categoria, organizzatore, sito, giorni e orari): in bozza, o pubblicata se si chiede e l'evento è futuro. Le pagine che
	 * hanno già un contenuto non si toccano. Gli eventi con lo stesso titolo nello stesso anno sociale (le ripetizioni di The Events
	 * Calendar) diventano un solo evento ricorrente con la sua pagina.
	 *
	 * @param int[] $course_cats    categorie degli eventi che sono corsi
	 * @param bool  $publish_future pubblica subito le pagine degli eventi con una data futura
	 * @return array imported (eventi), courses, lessons (date dei corsi), series (eventi ricorrenti), skipped (già importati), failed, pages (compilate), published, errors[] (i primi)
	 */
	public static function run( array $course_cats = array(), bool $publish_future = false ): array {
		$course_cats = array_map( 'intval', $course_cats );
		$res         = array( 'imported' => 0, 'courses' => 0, 'lessons' => 0, 'series' => 0, 'skipped' => 0, 'failed' => 0, 'pages' => 0, 'published' => 0, 'errors' => array() );
		$groups      = array();
		$series      = array();
		foreach ( self::found() as $f ) {
			$pid = (int) $f['post_id'];
			if ( $f['imported'] && Plugin::activities()->get( (int) $f['imported'] ) ) {
				$res['skipped']++;
				self::fill_page( (int) $f['imported'], array( $pid ), $course_cats, $publish_future, $res ); // una pagina rimasta vuota si compila
				continue;
			}
			$post = get_post( $pid );
			$meta = array();
			foreach ( array( '_EventStartDate', '_EventEndDate', '_EventAllDay', '_EventURL' ) as $k ) {
				$meta[ $k ] = get_post_meta( $pid, $k, true );
			}
			$map = $post ? self::map_event( $meta, (string) $post->post_title, (string) $post->post_content, self::venue( $pid ) ) : null;
			if ( ! $map ) {
				$res['failed']++;
				$res['errors'][] = '«' . $f['title'] . '»: manca la data.';
				continue;
			}
			$year = Settings::social_year( $map['session']['session_date'] )->label();
			if ( $course_cats && array_intersect( $f['cats'], $course_cats ) ) { // una lezione di un corso: si raccoglie con le altre dello stesso titolo
				$key                       = mb_strtolower( $map['name'] ) . '|' . $year;
				$groups[ $key ]['year']    = $year;
				$groups[ $key ]['posts'][] = $pid;
				$groups[ $key ]['items'][] = array( 'date' => $map['session']['session_date'], 'start' => $map['session']['start_time'], 'end' => $map['session']['end_time'], 'name' => $map['name'], 'notes' => $map['notes'], 'venue' => $map['session']['location'] );
				continue;
			}
			$key                    = mb_strtolower( $map['name'] ) . '|' . $year; // le ripetizioni di un evento (stesso titolo, stesso anno) si raccolgono
			$series[ $key ]['year'] = $year;
			$series[ $key ]['name'] = $map['name'];
			$series[ $key ]['rows'][] = array( 'pid' => $pid, 'title' => $f['title'], 'map' => $map );
		}
		foreach ( $series as $s ) {
			try {
				$first = $s['rows'][0]['map'];
				if ( 1 === count( $s['rows'] ) ) {
					$aid = Plugin::activities()->create(
						array(
							'name'        => $first['name'],
							'social_year' => $s['year'],
							'kind'        => ActivityKind::EVENT,
							'notes'       => $first['notes'],
							'session'     => $first['session'],
						)
					);
				} else { // evento ricorrente: una sola attività con tutte le date
					$aid = Plugin::activities()->create( array( 'name' => $first['name'], 'social_year' => $s['year'], 'kind' => ActivityKind::RECURRING, 'notes' => $first['notes'] ) );
					foreach ( $s['rows'] as $r ) {
						Plugin::activities()->add_session( $aid, $r['map']['session'] );
					}
					$res['series']++;
				}
				$pids = array();
				foreach ( $s['rows'] as $r ) {
					update_post_meta( $r['pid'], self::META_DONE, $aid );
					$pids[] = (int) $r['pid'];
					$res['imported']++;
				}
				self::fill_page( $aid, $pids, $course_cats, $publish_future, $res );
			} catch ( \InvalidArgumentException $e ) {
				foreach ( $s['rows'] as $r ) {
					$res['failed']++;
					$res['errors'][] = '«' . $r['title'] . '»: ' . $e->getMessage();
				}
			}
		}
		foreach ( $groups as $g ) {
			$c = self::map_course( $g['items'] );
			try {
				$aid = Plugin::activities()->create(
					array(
						'name'         => $c['name'],
						'social_year'  => $g['year'],
						'kind'         => ActivityKind::COURSE,
						'billing'      => 'once',
						'notes'        => $c['notes'],
						'location'     => $c['location'],
						'starts_on'    => $c['starts_on'],
						'ends_on'      => $c['ends_on'],
						'lesson_slots' => wp_json_encode( $c['lessons'] ),
					)
				);
				foreach ( $g['posts'] as $pid ) {
					update_post_meta( $pid, self::META_DONE, $aid );
				}
				$res['courses']++;
				$res['lessons'] += count( $g['posts'] );
				self::fill_page( $aid, array_map( 'intval', $g['posts'] ), $course_cats, $publish_future, $res );
			} catch ( \InvalidArgumentException $e ) {
				$res['failed']++;
				$res['errors'][] = '«' . $c['name'] . '»: ' . $e->getMessage();
			}
		}
		$res['errors'] = array_slice( $res['errors'], 0, 5 );
		Audit::log( 'import.tribe', 'settings', null, array( 'imported' => $res['imported'], 'courses' => $res['courses'], 'failed' => $res['failed'], 'pages' => $res['pages'], 'published' => $res['published'] ) );
		return $res;
	}

	/** Una data scritta come giorno/mese/anno. */
	private static function dmy( string $ymd ): string {
		return ( new \DateTimeImmutable( substr( $ymd, 0, 10 ) ) )->format( 'd/m/Y' );
	}

	/** Giorni e orari che la scheda standard non dice: evento su più giorni o per tutto il giorno. */
	private static function when_html( int $pid ): string {
		$start  = substr( (string) get_post_meta( $pid, '_EventStartDate', true ), 0, 10 );
		$end    = substr( (string) get_post_meta( $pid, '_EventEndDate', true ), 0, 10 );
		$allday = 'yes' === (string) get_post_meta( $pid, '_EventAllDay', true );
		$parts  = array();
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) && $end > $start ) {
			$parts[] = 'dal ' . self::dmy( $start ) . ' al ' . self::dmy( $end );
		}
		if ( $allday ) {
			$parts[] = 'tutto il giorno';
		}
		return $parts ? '<p><strong>Quando:</strong> ' . esc_html( implode( ', ', $parts ) ) . '.</p>' : '';
	}

	/** Organizzatore (con i contatti che ha) e sito dell'evento, come righe di testo per la pagina. */
	private static function extras_html( int $pid ): string {
		$h   = '';
		$ids = (array) get_post_meta( $pid, '_EventOrganizerID', false );
		$oid = (int) ( $ids ? reset( $ids ) : 0 );
		if ( $oid > 0 ) {
			$name = trim( (string) get_the_title( $oid ) );
			if ( '' !== $name ) {
				$contacts = array_filter( array( trim( (string) get_post_meta( $oid, '_OrganizerEmail', true ) ), trim( (string) get_post_meta( $oid, '_OrganizerPhone', true ) ) ) );
				$h       .= '<p><strong>Con</strong> ' . esc_html( $name ) . ( $contacts ? ' (' . esc_html( implode( ', ', $contacts ) ) . ')' : '' ) . '</p>';
			}
		}
		$url = esc_url( trim( (string) get_post_meta( $pid, '_EventURL', true ) ), array( 'http', 'https' ) );
		if ( '' !== $url ) {
			$h .= '<p><a href="' . esc_url( $url ) . '" rel="noopener noreferrer">Maggiori informazioni</a></p>';
		}
		return $h;
	}

	/** Categoria pubblica (creata o riusata per nome) dalla prima categoria di The Events Calendar che non è dei corsi. */
	private static function public_category( array $pids, array $course_cats ): int {
		self::ensure_taxonomy();
		$pick = null;
		foreach ( $pids as $pid ) {
			$terms = wp_get_object_terms( (int) $pid, self::TAXONOMY );
			foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $t ) {
				if ( null === $pick ) {
					$pick = $t;
				}
				if ( ! in_array( (int) $t->term_id, $course_cats, true ) ) {
					$pick = $t;
					break 2;
				}
			}
		}
		if ( ! $pick || '' === trim( (string) $pick->name ) ) {
			return 0;
		}
		$ex = term_exists( (string) $pick->name, PublicEvents::TAXONOMY );
		if ( ! $ex ) {
			$ex = wp_insert_term( (string) $pick->name, PublicEvents::TAXONOMY );
		}
		return is_wp_error( $ex ) ? 0 : (int) ( is_array( $ex ) ? $ex['term_id'] : $ex );
	}

	/**
	 * Compila la pagina pubblica dell'attività importata dai contenuti di The Events Calendar: descrizione (HTML ripulito), estratto,
	 * immagine in evidenza (lo stesso allegato, nessun download), categoria, organizzatore, sito e giorni. Se la pagina ha già un contenuto
	 * non si tocca. Resta in bozza, a meno che si chieda di pubblicare e l'evento sia futuro.
	 *
	 * @param int[] $pids contenuti di origine (uno, o le ripetizioni / lezioni)
	 */
	private static function fill_page( int $aid, array $pids, array $course_cats, bool $publish_future, array &$res ): void {
		if ( ! class_exists( __NAMESPACE__ . '\PublicEvents' ) || ! $pids ) {
			return;
		}
		$page = PublicEvents::ensure( $aid );
		$post = $page ? get_post( $page ) : null;
		if ( ! $post || '' !== trim( (string) $post->post_content ) ) {
			return;
		}
		$src   = null; // il contenuto con la descrizione più lunga
		$thumb = 0;
		foreach ( $pids as $pid ) {
			$p = get_post( (int) $pid );
			if ( ! $p ) {
				continue;
			}
			if ( null === $src || mb_strlen( trim( (string) $p->post_content ) ) > mb_strlen( trim( (string) $src->post_content ) ) ) {
				$src = $p;
			}
			if ( ! $thumb ) {
				$thumb = (int) get_post_thumbnail_id( $p );
			}
		}
		if ( ! $src ) {
			return;
		}
		$body = wp_kses_post( (string) $src->post_content );
		$more = 1 === count( $pids ) ? self::when_html( (int) $src->ID ) : '';
		$more .= self::extras_html( (int) $src->ID );
		$text = trim( $body . $more );
		$data = array( 'ID' => $page );
		if ( '' !== $text ) {
			$data['post_content'] = $text;
			$excerpt              = wp_trim_words( self::plain( $body ), 30 );
			if ( '' !== $excerpt ) {
				$data['post_excerpt'] = $excerpt;
			}
		}
		$a      = Plugin::activities()->get( $aid );
		$future = false;
		if ( $a && ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
			foreach ( Plugin::activities()->sessions( $aid ) as $s ) {
				if ( empty( $s['cancelled_at'] ) && (string) $s['session_date'] >= Db::today() ) {
					$future = true;
					break;
				}
			}
		} elseif ( $a ) {
			$future = (string) ( $a['ends_on'] ?? '' ) >= Db::today();
		}
		if ( $publish_future && $future ) {
			$data['post_status'] = 'publish';
		}
		wp_update_post( $data );
		if ( $thumb && 'attachment' === get_post_type( $thumb ) && ! get_post_meta( $page, '_thumbnail_id', true ) ) {
			update_post_meta( $page, '_thumbnail_id', $thumb ); // lo stesso allegato: nessuna copia, nessun download
		}
		$term = self::public_category( $pids, $course_cats );
		if ( $term ) {
			wp_set_object_terms( $page, array( $term ), PublicEvents::TAXONOMY );
		}
		$res['pages']++;
		if ( isset( $data['post_status'] ) ) {
			$res['published']++;
		}
	}
}
