<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Importa gli eventi di The Events Calendar (contenuti `tribe_events`) come eventi una tantum, passati e futuri, per poterli riproporre
 * senza rifare tutto. Si legge dal database del sito: The Events Calendar può anche essere disattivato. Non si importano costi,
 * prenotazioni, immagini né categorie. Ogni evento importato ricorda il contenuto di origine, quindi si può rilanciare senza doppioni.
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
	 * @param int[] $course_cats categorie degli eventi che sono corsi
	 * @return array imported (eventi), courses, lessons (date dei corsi), skipped (già importati), failed, errors[] (i primi)
	 */
	public static function run( array $course_cats = array() ): array {
		$course_cats = array_map( 'intval', $course_cats );
		$res         = array( 'imported' => 0, 'courses' => 0, 'lessons' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array() );
		$groups      = array();
		foreach ( self::found() as $f ) {
			$pid = (int) $f['post_id'];
			if ( $f['imported'] && Plugin::activities()->get( (int) $f['imported'] ) ) {
				$res['skipped']++;
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
			try {
				$aid = Plugin::activities()->create(
					array(
						'name'        => $map['name'],
						'social_year' => $year,
						'kind'        => ActivityKind::EVENT,
						'notes'       => $map['notes'],
						'session'     => $map['session'],
					)
				);
				update_post_meta( $pid, self::META_DONE, $aid );
				$res['imported']++;
			} catch ( \InvalidArgumentException $e ) {
				$res['failed']++;
				$res['errors'][] = '«' . $f['title'] . '»: ' . $e->getMessage();
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
			} catch ( \InvalidArgumentException $e ) {
				$res['failed']++;
				$res['errors'][] = '«' . $c['name'] . '»: ' . $e->getMessage();
			}
		}
		$res['errors'] = array_slice( $res['errors'], 0, 5 );
		Audit::log( 'import.tribe', 'settings', null, array( 'imported' => $res['imported'], 'courses' => $res['courses'], 'failed' => $res['failed'] ) );
		return $res;
	}
}
