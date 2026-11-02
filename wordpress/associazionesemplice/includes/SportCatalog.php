<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Classificazione sportiva di un'attività: lo sport (dall'elenco CONI, delibera n. 1730 del 23 febbraio 2023, modificabile dall'associazione)
 * e, a parte, la disciplina o specialità come la chiama l'ente di promozione (testo libero: ogni ente ha il suo elenco).
 *
 * L'elenco di base è quello del CONI; l'amministratore può aggiungere, togliere o importare sport e tornare all'elenco di base.
 * Togliere uno sport dal menu non lo cancella dalle attività che già lo usano.
 */
final class SportCatalog {

	const OPTION = 'asem_sport_list';
	const MAX    = 300;

	/** Gli sport dell'elenco CONI di base (nome come nella delibera); l'ordine è alfabetico. @return string[] */
	public static function defaults(): array {
		return array(
			'Aikido', 'Alpinismo', 'Armi sportive da caccia', 'Arrampicata sportiva', 'Arti marziali', 'Atletica leggera', 'Attività subacquee', 'Automobilismo', 'Badminton', 'Bandy',
			'Baseball – Softball', 'Biathlon', 'Biliardo sportivo', 'Bob', 'Bocce', 'Body building', 'Bowling', 'Bridge', 'Calcio', 'Canoa', 'Canottaggio', 'Canottaggio sedile fisso',
			'Cheerleading', 'Ciclismo', 'Cinofilia', 'Cricket', 'Cronometraggio', 'Dama', 'Danza sportiva', 'Fistball', 'Floorball', 'Football americano', 'Freccette', 'Ginnastica',
			'Giochi e sport tradizionali', 'Go', 'Golf', 'Hockey', 'Judo', 'Ju-Jitsu', 'Karate', 'Kendo', 'Kickboxing', 'Korfball', 'Lacrosse', 'Lotta', 'Medicina dello sport', 'Minigolf',
			'Motociclismo', 'Motonautica', 'Muay Thai', 'Netball', 'Orientamento', 'Palla tamburello', 'Pallacanestro', 'Pallamano', 'Pallapugno', 'Pallavolo', 'Pelota basca',
			'Pentathlon moderno', 'Pesca sportiva', 'Pesistica', 'Powerlifting', 'Pugilato', 'Rafting', 'Rugby', 'Salvataggio (lifesaving)', 'Sambo', 'Scacchi', 'Scherma', 'Sci nautico e wakeboard',
			'Sepaktakraw', 'Skateboarding', 'Slittino e skeleton', 'Sport equestri', 'Sport e giochi sferistici', 'Squash e racquetball', 'Sumo', 'Surf', 'Taekwondo', 'Tennis', 'Tennistavolo',
			'Tiro a segno', 'Tiro a volo', 'Tiro con l\'arco', 'Tiro dinamico sportivo', 'Triathlon', 'Turismo equestre', 'Twirling', 'Wushu',
		);
	}

	/** Gli sport del menu: quelli scelti dall'associazione oppure, se non li ha modificati, l'elenco di base. @return string[] */
	public static function sports(): array {
		$saved = get_option( self::OPTION, null );
		return is_array( $saved ) ? self::normalize( $saved ) : self::defaults();
	}

	/** L'associazione non ha modificato l'elenco? */
	public static function is_default(): bool {
		return ! is_array( get_option( self::OPTION, null ) );
	}

	/** Uno sport scritto in modo sensato (senza tag, al massimo 60 caratteri) oppure null se vuoto. */
	public static function clean( string $sport ): ?string {
		$sport = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $sport ) ) ?? '' );
		$sport = mb_substr( $sport, 0, 60 );
		return '' === $sport ? null : $sport;
	}

	/** Elenco pulito: senza vuoti né doppioni (maiuscole comprese), in ordine alfabetico. @param mixed[] $list @return string[] */
	public static function normalize( array $list ): array {
		$out = array();
		foreach ( $list as $s ) {
			$s = is_string( $s ) ? self::clean( $s ) : null;
			if ( null !== $s ) {
				$out[ mb_strtolower( $s, 'UTF-8' ) ] = $s;
			}
		}
		$out = array_values( $out );
		sort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return array_slice( $out, 0, self::MAX );
	}

	/** Gli sport scritti in un testo (uno per riga, oppure separati da punto e virgola). @return string[] */
	public static function parse( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n;]+/', $text ) ?: array() as $line ) {
			$s = self::clean( $line );
			if ( null !== $s ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	private static function save( array $list ): void {
		update_option( self::OPTION, self::normalize( $list ), false );
	}

	/** Aggiunge uno sport (o più, uno per riga). @return int quanti sono nuovi */
	public static function add( string $text ): int {
		$before = self::sports();
		$new    = self::normalize( array_merge( $before, self::parse( $text ) ) );
		self::save( $new );
		return count( $new ) - count( $before );
	}

	/** Toglie uno sport dal menu. Le attività che lo usano lo tengono. */
	public static function remove( string $sport ): void {
		$key  = mb_strtolower( (string) self::clean( $sport ), 'UTF-8' );
		$keep = array_filter(
			self::sports(),
			function ( $s ) use ( $key ) {
				return mb_strtolower( $s, 'UTF-8' ) !== $key;
			}
		);
		self::save( array_values( $keep ) );
	}

	/** Torna all'elenco CONI di base. */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
