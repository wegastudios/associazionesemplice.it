<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Importa gli eventi di The Events Calendar (contenuti `tribe_events`) come eventi una tantum, passati e futuri, per poterli riproporre
 * senza rifare tutto. Si legge dal database del sito: The Events Calendar può anche essere disattivato. Non si importano costi
 * e prenotazioni; immagine, categoria, descrizione, organizzatore e sito vanno nella pagina pubblica dell'evento. Ogni evento importato
 * ricorda il contenuto di origine, quindi si può rilanciare senza doppioni. Uno a uno: ogni evento di The Events Calendar diventa una sola
 * attività (mai raggruppata con altre, nemmeno con lo stesso titolo).
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

	/** Una data di The Events Calendar (Y-m-d H:i:s) letta dal valore in UTC e dal fuso dell'evento, per quando manca quella locale. */
	public static function from_utc( string $utc, string $tz ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $utc ) ) {
			return '';
		}
		try {
			$name = '' !== trim( $tz ) ? trim( $tz ) : ( function_exists( 'wp_timezone_string' ) && '' !== wp_timezone_string() ? wp_timezone_string() : 'UTC' );
			$d    = new \DateTimeImmutable( substr( str_replace( 'T', ' ', $utc ), 0, 19 ), new \DateTimeZone( 'UTC' ) );
			return $d->setTimezone( new \DateTimeZone( $name ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/** I dati di data di un evento: _EventStartDate/_EventEndDate; se la data locale manca o non è valida si usa quella in UTC (_EventStartDateUTC) col fuso dell'evento. */
	private static function event_meta( int $pid ): array {
		$meta = array();
		foreach ( array( '_EventStartDate', '_EventEndDate', '_EventAllDay', '_EventURL', '_EventStartDateUTC', '_EventEndDateUTC', '_EventTimezone' ) as $k ) {
			$meta[ $k ] = get_post_meta( $pid, $k, true );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $meta['_EventStartDate'] ) ) {
			$alt = self::from_utc( (string) $meta['_EventStartDateUTC'], (string) $meta['_EventTimezone'] );
			if ( '' !== $alt ) {
				$meta['_EventStartDate'] = $alt;
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $meta['_EventEndDate'] ) ) {
					$meta['_EventEndDate'] = self::from_utc( (string) $meta['_EventEndDateUTC'], (string) $meta['_EventTimezone'] );
				}
			}
		}
		return $meta;
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
				'date'     => substr( (string) self::event_meta( $id )['_EventStartDate'], 0, 10 ),
				'imported' => self::live_activity( $id ),
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
	 * Ricontrollo: l'attività collegata a un evento già importato esiste ancora? Se è stata eliminata a mano il segno non vale più: si toglie
	 * e l'evento torna importabile. Elenco «già importati», riepilogo e importazione passano tutti da qui, così i numeri tornano.
	 *
	 * @return int id dell'attività ancora esistente, 0 se l'evento non è importato (o la sua attività non c'è più)
	 */
	private static function live_activity( int $pid ): int {
		$aid = (int) get_post_meta( $pid, self::META_DONE, true );
		if ( ! $aid ) {
			return 0;
		}
		if ( Plugin::activities()->get( $aid ) ) {
			return $aid;
		}
		delete_post_meta( $pid, self::META_DONE );
		return 0;
	}

	/** @return array[] gli eventi ancora da importare (mai importati, o la cui attività non c'è più) */
	private static function todo(): array {
		$out = array();
		foreach ( self::found() as $f ) {
			if ( $f['imported'] ) {
				continue;
			}
			$out[] = $f;
		}
		return $out;
	}

	/** Quanti eventi ancora da importare non hanno nessuna categoria (sono eventi). */
	public static function uncategorized(): int {
		$n = 0;
		foreach ( self::todo() as $f ) {
			if ( ! $f['cats'] ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Le categorie degli eventi ancora da importare, con quanti eventi hanno. Ogni categoria si sceglie come «Evento» o «Corso» prima di partire.
	 *
	 * @return array[] id, name, count, course (proposta: il nome parla di corsi o lezioni, parole intere)
	 */
	public static function categories(): array {
		self::ensure_taxonomy();
		$count = array();
		foreach ( self::todo() as $f ) {
			foreach ( $f['cats'] as $c ) {
				$count[ $c ] = ( $count[ $c ] ?? 0 ) + 1;
			}
		}
		$out = array();
		foreach ( $count as $id => $n ) {
			$t = get_term( (int) $id, self::TAXONOMY );
			if ( $t && ! is_wp_error( $t ) ) {
				$out[] = array( 'id' => (int) $id, 'name' => (string) $t->name, 'count' => $n, 'course' => 1 === preg_match( '/\b(?:cors[oi]|lezion[ei])\b/iu', (string) $t->name ) ); // solo una proposta, a parole intere: «Percorsi», «Concorsi» e «Laboratori» non si propongono
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
	 * Un corso a partire dalle sue date. L'import gli passa un solo evento alla volta (un evento = un corso, con la sua data come unica lezione singola);
	 * se gli si danno più date, quelle che si ripetono almeno 3 volte lo stesso giorno e ora diventano una lezione settimanale, le altre restano date singole.
	 * Descrive solo il calendario del corso: se un evento sia un corso lo decide la categoria scelta dall'utente, non questa funzione.
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
	 * Un evento è un corso solo se NESSUNA delle sue categorie è stata scelta come «Evento» e almeno una è stata scelta come «Corso».
	 * Ogni categoria è «Corso» (è tra $course_cats) o «Evento» (tutte le altre); un evento senza categoria è un evento. Decide solo la scelta
	 * dell'utente: non conta il titolo né quante volte l'evento si ripete.
	 *
	 * @param int[] $cats        categorie dell'evento
	 * @param int[] $course_cats categorie scelte come «Corso»
	 */
	public static function is_course( array $cats, array $course_cats ): bool {
		$cats = array_map( 'intval', $cats );
		return ! empty( $cats ) && empty( array_diff( $cats, array_map( 'intval', $course_cats ) ) );
	}

	/**
	 * Divide gli eventi ancora da importare in due elenchi che non si toccano mai: prima gli eventi, poi i corsi. Ogni evento sta in uno solo.
	 *
	 * @param int[] $course_cats categorie scelte come «Corso»
	 * @return array events[], courses[] (righe: pid, title, map, year, cats), done[] (pid, aid: già importati), failed[] (pid, title: senza una data valida)
	 */
	private static function partition( array $course_cats ): array {
		$out = array( 'events' => array(), 'courses' => array(), 'done' => array(), 'failed' => array() );
		foreach ( self::found() as $f ) {
			$pid = (int) $f['post_id'];
			if ( $f['imported'] ) {
				$out['done'][] = array( 'pid' => $pid, 'aid' => (int) $f['imported'] );
				continue;
			}
			$post = get_post( $pid );
			$meta = self::event_meta( $pid );
			$map = $post ? self::map_event( $meta, (string) $post->post_title, (string) $post->post_content, self::venue( $pid ) ) : null;
			if ( ! $map ) {
				$out['failed'][] = array( 'pid' => $pid, 'title' => (string) $f['title'] );
				continue;
			}
			$row = array( 'pid' => $pid, 'title' => (string) $f['title'], 'map' => $map, 'year' => Settings::social_year( $map['session']['session_date'] )->label(), 'cats' => array_map( 'intval', $f['cats'] ) );
			$out[ self::is_course( $row['cats'], $course_cats ) ? 'courses' : 'events' ][] = $row;
		}
		return $out;
	}

	/** Il nome di una categoria di The Events Calendar. */
	private static function cat_name( int $id ): string {
		$t = get_term( $id, self::TAXONOMY );
		return $t && ! is_wp_error( $t ) ? (string) $t->name : '';
	}

	/** Il link per modificare un evento in The Events Calendar, se si può aprire (il tipo di contenuto c'è solo con il plugin attivo). */
	private static function edit_link( int $pid ): string {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return '';
		}
		return (string) get_edit_post_link( $pid, 'raw' );
	}

	/** Gli eventi non importabili (senza data valida) come elenco per titolo, col link di modifica se c'è. */
	private static function failed_list( array $failed ): array {
		$list = array();
		foreach ( $failed as $f ) {
			$list[] = array( 'title' => '' !== trim( (string) $f['title'] ) ? (string) $f['title'] : '(senza titolo)', 'link' => self::edit_link( (int) $f['pid'] ) );
		}
		return $list;
	}

	/** @return array[] gli eventi ancora da importare che non si possono importare perché manca la data: title, link (modifica in The Events Calendar, vuoto se non disponibile) */
	public static function undatable(): array {
		return self::failed_list( self::partition( array() )['failed'] );
	}

	/**
	 * Che cosa succederebbe con queste scelte: quanti eventi, quanti corsi (uno per evento di The Events Calendar) e quali categorie si ricreano nei nostri portali.
	 *
	 * @param int[] $course_cats categorie scelte come «Corso»
	 * @return array events (numero di eventi), courses (numero di corsi), categories[] (nomi), failed (numero), failed_list[] (title, link)
	 */
	public static function summary( array $course_cats = array() ): array {
		self::ensure_taxonomy();
		$p     = self::partition( $course_cats );
		$names = array();
		foreach ( array_merge( $p['events'], $p['courses'] ) as $r ) {
			foreach ( $r['cats'] as $c ) {
				$n = self::cat_name( (int) $c );
				if ( '' !== trim( $n ) ) {
					$names[ mb_strtolower( trim( $n ) ) ] = trim( $n );
				}
			}
		}
		return array( 'events' => count( $p['events'] ), 'courses' => count( $p['courses'] ), 'categories' => array_values( $names ), 'failed' => count( $p['failed'] ), 'failed_list' => self::failed_list( $p['failed'] ) );
	}

	/**
	 * Importa tutti gli eventi non ancora importati, in due passaggi nell'ordine fisso: PRIMA gli eventi (quelli senza categoria e quelli con almeno
	 * una categoria scelta come «Evento»), POI i corsi (solo gli eventi rimasti, con tutte le categorie scelte come «Corso»). Un evento sta in un
	 * solo elenco e, una volta importato, è segnato: il secondo passaggio non lo riprende.
	 *
	 * Per ogni evento o corso importato si compila anche la sua pagina pubblica (descrizione, estratto, immagine in evidenza,
	 * categorie, organizzatore, sito, giorni e orari): in bozza, o pubblicata se si chiede e l'evento è futuro. Le pagine che
	 * hanno già un contenuto non si toccano. Uno a uno: ogni evento di The Events Calendar diventa una sola attività con la sua pagina,
	 * anche se un altro ha lo stesso titolo e lo stesso anno sociale (niente viene raggruppato).
	 *
	 * @param int[] $course_cats    categorie scelte come «Corso» (le altre sono «Evento»)
	 * @param bool  $publish_future pubblica subito le pagine degli eventi con una data futura
	 * @return array imported (attività create, eventi e corsi), events, courses, skipped (già importati), failed, failed_list[] (title, link: senza data), pages (compilate), published, errors[] (i primi), course_list[] (nomi), event_list[] (nomi), categories[] (nomi usati nelle pagine), categories_new[] (nomi creati ora)
	 */
	public static function run( array $course_cats = array(), bool $publish_future = false ): array {
		$res  = array( 'imported' => 0, 'events' => 0, 'courses' => 0, 'skipped' => 0, 'failed' => 0, 'failed_list' => array(), 'pages' => 0, 'published' => 0, 'errors' => array(), 'course_list' => array(), 'event_list' => array(), 'categories' => array(), 'categories_new' => array() );
		$res['track']    = array( 'kind' => 'tribe', 'activities' => array(), 'posts' => array(), 'filled' => array(), 'terms' => array() ); // quello che l'import scrive, per poterlo annullare
		$res['batch_id'] = null;
		$part  = self::partition( $course_cats );
		$batch = ImportService::open_batch( 'The Events Calendar' );
		try {
			$res['failed']     += count( $part['failed'] ); // senza data: elencati per titolo in failed_list
			$res['failed_list'] = self::failed_list( $part['failed'] );
			foreach ( $part['done'] as $d ) {
				$res['skipped']++;
				self::fill_page( (int) $d['aid'], array( (int) $d['pid'] ), $publish_future, $res ); // una pagina rimasta vuota si compila
			}
			self::import_events( $part['events'], $publish_future, $res ); // 1° passaggio: tutti gli eventi
			self::import_courses( $part['courses'], $publish_future, $res ); // 2° passaggio: poi i corsi
		} finally {
			$t               = $res['track'];
			$summary         = array( 'kind' => 'tribe', 'events' => (int) $res['events'], 'courses' => (int) $res['courses'], 'pages' => (int) $res['pages'], 'filled' => count( $t['filled'] ) );
			$res['batch_id'] = ImportService::finish_batch( $batch, $summary, $t, ! empty( $t['activities'] ) || ! empty( $t['filled'] ) ); // anche se qualcosa va storto a metà, quello che è nato si può annullare
		}
		unset( $res['track'] );
		$res['errors']     = array_slice( $res['errors'], 0, 5 );
		$res['categories']     = array_values( $res['categories'] );
		$res['categories_new'] = array_values( $res['categories_new'] );
		Audit::log( 'import.tribe', 'settings', null, array( 'imported' => $res['imported'], 'events' => $res['events'], 'courses' => $res['courses'], 'failed' => $res['failed'], 'pages' => $res['pages'], 'published' => $res['published'] ) );
		return $res;
	}

	/** Righe non ancora importate: toglie quelle già segnate (nessun evento passa due volte). */
	private static function not_yet_done( array $rows ): array {
		return array_values(
			array_filter(
				$rows,
				function ( $r ) {
					return ! self::live_activity( (int) $r['pid'] );
				}
			)
		);
	}

	/** 1° passaggio: gli eventi, uno per ogni evento di The Events Calendar (mai raggruppati, nemmeno con lo stesso titolo). */
	private static function import_events( array $rows, bool $publish_future, array &$res ): void {
		foreach ( self::not_yet_done( $rows ) as $r ) {
			try {
				$m   = $r['map'];
				$aid = Plugin::activities()->create(
					array(
						'name'        => $m['name'],
						'social_year' => $r['year'],
						'kind'        => ActivityKind::EVENT,
						'notes'       => $m['notes'],
						'session'     => $m['session'],
					)
				);
				$res['track']['activities'][]             = $aid;
				$res['track']['posts'][ (int) $r['pid'] ] = $aid;
				update_post_meta( $r['pid'], self::META_DONE, $aid );
				$res['imported']++;
				$res['events']++;
				$res['event_list'][] = $m['name'];
				self::fill_page( $aid, array( (int) $r['pid'] ), $publish_future, $res );
			} catch ( \InvalidArgumentException $e ) {
				$res['failed']++;
				$res['errors'][] = '«' . $r['title'] . '»: ' . $e->getMessage();
			}
		}
	}

	/** 2° passaggio: i corsi, uno per ogni evento di The Events Calendar, con la sua data come unica lezione (singola). */
	private static function import_courses( array $rows, bool $publish_future, array &$res ): void {
		foreach ( self::not_yet_done( $rows ) as $r ) {
			$s = $r['map']['session'];
			$c = self::map_course( array( array( 'date' => $s['session_date'], 'start' => $s['start_time'], 'end' => $s['end_time'], 'name' => $r['map']['name'], 'notes' => $r['map']['notes'], 'venue' => $s['location'] ) ) );
			try {
				$aid = Plugin::activities()->create(
					array(
						'name'         => $c['name'],
						'social_year'  => $r['year'],
						'kind'         => ActivityKind::COURSE,
						'billing'      => 'once',
						'notes'        => $c['notes'],
						'location'     => $c['location'],
						'starts_on'    => $c['starts_on'],
						'ends_on'      => $c['ends_on'],
						'lesson_slots' => wp_json_encode( $c['lessons'] ),
					)
				);
				$res['track']['activities'][]             = $aid;
				$res['track']['posts'][ (int) $r['pid'] ] = $aid;
				update_post_meta( $r['pid'], self::META_DONE, $aid );
				$res['imported']++;
				$res['courses']++;
				$res['course_list'][] = (string) $c['name'];
				self::fill_page( $aid, array( (int) $r['pid'] ), $publish_future, $res );
			} catch ( \InvalidArgumentException $e ) {
				$res['failed']++;
				$res['errors'][] = '«' . $r['title'] . '»: ' . $e->getMessage();
			}
		}
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

	/**
	 * Le categorie originali di The Events Calendar dei contenuti indicati, ricreate (o riusate per nome) come categorie pubbliche degli eventi.
	 * Tutte, anche quelle scelte come «Corso» (es. «Laboratorio»).
	 *
	 * @param int[] $pids contenuti di origine
	 * @return int[] id delle categorie pubbliche
	 */
	private static function public_categories( array $pids, array &$res ): array {
		self::ensure_taxonomy();
		$ids = array();
		foreach ( $pids as $pid ) {
			$terms = wp_get_object_terms( (int) $pid, self::TAXONOMY );
			foreach ( is_wp_error( $terms ) ? array() : (array) $terms as $t ) {
				$name = trim( (string) $t->name );
				if ( '' === $name ) {
					continue;
				}
				$ex  = term_exists( $name, PublicEvents::TAXONOMY );
				$new = false;
				if ( ! $ex ) {
					$ex  = wp_insert_term( $name, PublicEvents::TAXONOMY );
					$new = true;
				}
				if ( is_wp_error( $ex ) ) {
					continue;
				}
				$tid         = (int) ( is_array( $ex ) ? $ex['term_id'] : $ex );
				$ids[ $tid ] = true;
				if ( $new ) {
					$res['track']['terms'][] = $tid; // creata da questo import: si toglie se resta vuota
				}
				$res['categories'][ mb_strtolower( $name ) ]             = $name;
				if ( $new ) {
					$res['categories_new'][ mb_strtolower( $name ) ] = $name;
				}
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Compila la pagina pubblica dell'attività importata dai contenuti di The Events Calendar: descrizione (HTML ripulito), estratto,
	 * immagine in evidenza (lo stesso allegato, nessun download), categoria, organizzatore, sito e giorni. Se la pagina ha già un contenuto
	 * non si tocca. Resta in bozza, a meno che si chieda di pubblicare e l'evento sia futuro.
	 *
	 * @param int[] $pids contenuti di origine (uno, o le ripetizioni / lezioni)
	 */
	private static function fill_page( int $aid, array $pids, bool $publish_future, array &$res ): void {
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
		$mine = in_array( $aid, array_map( 'intval', $res['track']['activities'] ?? array() ), true ); // attività nata con questo import: la sua pagina si toglie con lei
		$prev = wp_get_object_terms( $page, PublicEvents::TAXONOMY, array( 'fields' => 'ids' ) );
		$prev = is_wp_error( $prev ) ? array() : array_map( 'intval', (array) $prev );
		wp_update_post( $data );
		$thumb_set = false;
		if ( $thumb && 'attachment' === get_post_type( $thumb ) && ! get_post_meta( $page, '_thumbnail_id', true ) ) {
			update_post_meta( $page, '_thumbnail_id', $thumb ); // lo stesso allegato: nessuna copia, nessun download
			$thumb_set = true;
		}
		$terms = self::public_categories( $pids, $res ); // tutte le categorie originali, su ogni pagina creata
		if ( $terms ) {
			wp_set_object_terms( $page, $terms, PublicEvents::TAXONOMY );
		}
		if ( ! $mine && isset( $res['track'] ) ) { // pagina di un'attività di un import precedente, rimasta vuota: si ricorda com'era per poterla svuotare
			$now                             = get_post( $page );
			$res['track']['filled'][ $page ] = array(
				'activity' => $aid, 'status' => (string) $post->post_status, 'thumb' => $thumb_set ? 1 : 0, 'terms_before' => $prev,
				'hash'     => self::page_hash( $now ),
			);
		}
		$res['pages']++;
		if ( isset( $data['post_status'] ) ) {
			$res['published']++;
		}
	}

	// ---------- Annullamento ----------

	/** Impronta del contenuto scritto dall'import in una pagina (testo ed estratto): serve a non svuotare una pagina che nel frattempo hai modificato tu. */
	private static function page_hash( $post ): string {
		return $post ? md5( (string) $post->post_content . '|' . (string) $post->post_excerpt ) : '';
	}

	/** True se l'attività è già stata usata: prenotazioni, lista d'attesa, iscrizioni o movimenti (anche annullati). */
	private static function activity_used( int $aid ): bool {
		$db   = Db::db();
		$ids  = array_map( 'intval', $db->get_col( $db->prepare( 'SELECT id FROM ' . Db::t( 'sessions' ) . ' WHERE activity_id = %d', $aid ) ) );
		$in   = $ids ? implode( ',', $ids ) : '0';
		$used = (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id IN ($in)" )
			+ (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'waitlist' ) . " WHERE session_id IN ($in)" )
			+ (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d', $aid ) )
			+ (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE activity_id = %d', $aid ) );
		return $used > 0;
	}

	/**
	 * Annulla un import da The Events Calendar: toglie le attività nate con l'import (con date, lezioni e pagina pubblica) solo se nessuno
	 * si è prenotato o iscritto e non ci sono movimenti; quelle usate restano e vengono elencate. Le pagine di attività già esistenti che l'import
	 * ha riempito tornano vuote (se non le hai modificate nel frattempo). Le categorie pubbliche create dall'import si tolgono se restano vuote;
	 * gli eventi di The Events Calendar la cui attività è stata tolta si possono importare di nuovo.
	 *
	 * @param array $b import (di ImportService::batch)
	 * @return array tribe (1), activities_removed, pages_removed, pages_restored, terms_removed, posts_released, kept[] (motivi)
	 * @throws \InvalidArgumentException
	 */
	public static function undo( array $b ): array {
		$batch_id = (int) $b['id'];
		$data     = (array) $b['data'];
		$res      = array( 'tribe' => 1, 'activities_removed' => 0, 'pages_removed' => 0, 'pages_restored' => 0, 'terms_removed' => 0, 'posts_released' => 0, 'kept' => array() );
		$gone     = array(); // attività tolte (o già sparite)
		$has_page = class_exists( __NAMESPACE__ . '\PublicEvents' );
		foreach ( array_unique( array_map( 'intval', (array) ( $data['activities'] ?? array() ) ) ) as $aid ) {
			$a = Plugin::activities()->get( $aid );
			if ( ! $a ) {
				$gone[] = $aid;
				continue;
			}
			if ( self::activity_used( $aid ) ) {
				$res['kept'][] = '«' . $a['name'] . '» (ha prenotazioni, iscritti o movimenti)';
				continue;
			}
			$page = $has_page ? PublicEvents::post_of( $aid ) : 0;
			try {
				ActivityReset::delete( $aid, ActivityReset::VOID );
			} catch ( \InvalidArgumentException $e ) {
				$res['kept'][] = '«' . $a['name'] . '» (' . $e->getMessage() . ')';
				continue;
			}
			$gone[] = $aid;
			$res['activities_removed']++;
			if ( $page && get_post( $page ) ) {
				wp_delete_post( $page, true ); // la pagina nata con l'attività se ne va con lei, non nel cestino
				$res['pages_removed']++;
			}
		}
		// Pagine di attività già esistenti riempite dall'import: tornano come erano, solo se non toccate da te dopo.
		foreach ( (array) ( $data['filled'] ?? array() ) as $page => $f ) {
			$page = (int) $page;
			$post = $page ? get_post( $page ) : null;
			$aid  = (int) ( $f['activity'] ?? 0 );
			if ( ! $post || in_array( $aid, $gone, true ) ) {
				continue;
			}
			if ( self::page_hash( $post ) !== (string) ( $f['hash'] ?? '' ) ) {
				$res['kept'][] = 'pagina «' . $post->post_title . '» (modificata dopo l\'import: non la tocco)';
				continue;
			}
			wp_update_post( array( 'ID' => $page, 'post_content' => '', 'post_excerpt' => '', 'post_status' => in_array( (string) ( $f['status'] ?? '' ), array( 'draft', 'pending', 'private', 'publish', 'future' ), true ) ? (string) $f['status'] : 'draft' ) );
			if ( ! empty( $f['thumb'] ) ) {
				delete_post_meta( $page, '_thumbnail_id' );
			}
			wp_set_object_terms( $page, array_map( 'intval', (array) ( $f['terms_before'] ?? array() ) ), PublicEvents::TAXONOMY );
			$res['pages_restored']++;
		}
		// Eventi di The Events Calendar: tornano importabili solo quelli la cui attività non c'è più.
		foreach ( (array) ( $data['posts'] ?? array() ) as $pid => $aid ) {
			if ( in_array( (int) $aid, $gone, true ) ) {
				delete_post_meta( (int) $pid, self::META_DONE );
				$res['posts_released']++;
			}
		}
		// Categorie pubbliche create dall'import: via se nessuna pagina le usa più.
		if ( $has_page ) {
			foreach ( array_unique( array_map( 'intval', (array) ( $data['terms'] ?? array() ) ) ) as $tid ) {
				$objs = get_objects_in_term( $tid, PublicEvents::TAXONOMY );
				if ( ! is_wp_error( $objs ) && ! $objs ) {
					$r = wp_delete_term( $tid, PublicEvents::TAXONOMY );
					if ( true === $r ) {
						$res['terms_removed']++;
					}
				}
			}
		}
		ImportService::mark_undone( $batch_id, $res, array( 'tribe' => 1, 'activities_removed' => $res['activities_removed'], 'pages_removed' => $res['pages_removed'], 'kept' => count( $res['kept'] ) ) );
		return $res;
	}
}
