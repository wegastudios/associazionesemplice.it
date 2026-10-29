<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Classificazione sportiva di un'attività: lo sport secondo l'elenco delle discipline riconosciute dal CONI (delibera n. 1730 del 23 febbraio 2023)
 * e, a parte, la disciplina o specialità come la chiama l'ente di promozione (testo libero: ogni ente ha il suo elenco).
 */
final class SportCatalog {

	/** Gli sport dell'elenco CONI (nome come nella delibera); l'ordine è alfabetico. @return string[] */
	public static function sports(): array {
		return array(
			'Aikido', 'Alpinismo', 'Armi sportive da caccia', 'Arrampicata sportiva', 'Arti marziali', 'Atletica leggera', 'Attività subacquee', 'Automobilismo', 'Badminton', 'Bandy',
			'Baseball – Softball', 'Biathlon', 'Biliardo sportivo', 'Bob', 'Bocce', 'Body building', 'Bowling', 'Bridge', 'Calcio', 'Canoa', 'Canottaggio', 'Canottaggio sedile fisso',
			'Cheerleading', 'Ciclismo', 'Cinofilia', 'Cricket', 'Cronometraggio', 'Dama', 'Danza sportiva', 'Fistball', 'Floorball', 'Football americano', 'Freccette', 'Ginnastica',
			'Giochi e sport tradizionali', 'Go', 'Golf', 'Hockey', 'Judo', 'Ju-Jitsu', 'Karate', 'Kendo', 'Kickboxing', 'Korfball', 'Lacrosse', 'Lotta', 'Medicina dello sport', 'Minigolf',
			'Motociclismo', 'Motonautica', 'Muay Thai', 'Netball', 'Orientamento', 'Palla tamburello', 'Pallacanestro', 'Pallamano', 'Pallapugno', 'Pallavolo', 'Pelota basca',
			'Pentathlon moderno', 'Pesca sportiva', 'Pesistica', 'Powerlifting', 'Pugilato', 'Rafting', 'Rugby', 'Salvataggio (lifesaving)', 'Sambo', 'Scherma', 'Sci nautico e wakeboard',
			'Sepaktakraw', 'Skateboarding', 'Slittino e skeleton', 'Sport equestri', 'Sport e giochi sferistici', 'Squash e racquetball', 'Sumo', 'Surf', 'Taekwondo', 'Tennis', 'Tennistavolo',
			'Tiro a segno', 'Tiro a volo', 'Tiro con l\'arco', 'Triathlon', 'Twirling', 'Wushu',
		);
	}

	/** Lo sport scelto, se è nell'elenco (altrimenti null). */
	public static function clean( string $sport ): ?string {
		$sport = trim( $sport );
		return in_array( $sport, self::sports(), true ) ? $sport : null;
	}
}
