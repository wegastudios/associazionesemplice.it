<?php
/**
 * Prenotazione per più persone dall'area soci: tutte o nessuna, chi prenota risponde del contributo (booked_by), posti sull'ultimo posto, nessun oracolo sugli altri
 * soci (stessa frase per ogni motivo, anche a evento pieno), limiti dei tentativi, ospiti nuovi dentro la transazione. Si carica da smoke.php.
 */

use AssociazioneSemplice\BookingFailure;
use AssociazioneSemplice\Counters;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Frontend\Actions;

global $wpdb;
wp_set_current_user( 1 );

$gb_acts  = Plugin::activities();
$gb_peo   = Plugin::people();
$gb_sy    = Settings::social_year()->label();
$gb_today = current_time( 'Y-m-d' );
$gb_day   = gmdate( 'Y-m-d', strtotime( $gb_today . ' +10 days' ) );
Settings::update( array( 'limits' => array( 'member_book_per_10min' => 500, 'member_other_fail_per_10min' => 5, 'ip_other_fail_per_hour' => 500 ) ) );

/** Un evento con una data e la capienza indicata. @return int[] attività, data */
$gb_event = function ( string $name, ?int $cap, int $fee = 500 ) use ( $gb_acts, $gb_sy, $gb_day ): array {
	$aid = (int) $gb_acts->create( array( 'name' => $name, 'social_year' => $gb_sy, 'kind' => 'event', 'fee_cents' => $fee, 'session' => array( 'session_date' => $gb_day, 'capacity' => $cap ) ) );
	return array( $aid, (int) $gb_acts->sessions( $aid )[0]['id'] );
};
$gb_member = function ( string $first, string $last, string $card, array $extra = array() ) use ( $gb_peo, $gb_sy, $wpdb ): int {
	$id = (int) $gb_peo->create( array_merge( array( 'type' => 'ordinary', 'card_number' => $card, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first ) . '.gruppo@example.com', 'birth_date' => '1980-05-05' ), $extra ) );
	$gb_peo->set_membership( $id, $gb_sy, true );
	return $id;
};

// Chi prenota: socio con utente WordPress, profilo completo e tessera valida
$gb_booker = $gb_member( 'Paola', 'Prenota', '7001' );
$gb_uid    = wp_insert_user( array( 'user_login' => 'gruppo_prenota', 'user_pass' => wp_generate_password(), 'user_email' => 'paola.gruppo@example.com' ) );
$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => $gb_uid, 'address' => 'Via Prova 1', 'city' => 'Roma', 'zip' => '00100', 'province' => 'RM', 'tax_code' => 'RSSMRA80A01H501U', 'phone' => '333 1110000' ), array( 'id' => $gb_booker ) );
wp_cache_flush();
$gb_gino  = $gb_member( 'Gino', 'Socio', '7002' );
$gb_rina  = $gb_member( 'Rina', 'Socia', '7003' );
$gb_book  = function ( array $post ): string {
	return Actions::do_book_group( $post );
};
$gb_fail  = function ( array $post ) use ( $gb_book ): string {
	return (string) asem_throws( function () use ( $gb_book, $post ) { $gb_book( $post ); } );
};
$gb_count = function ( int $sid ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND status = 'booked'", $sid ) );
};
$gb_row   = function ( int $sid, int $pid ) use ( $wpdb ): ?array {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $sid, $pid ), ARRAY_A );
};
wp_set_current_user( $gb_uid );
asem_ok( $gb_booker === (int) \AssociazioneSemplice\Access::current_person()['id'], 'prenota gruppo: chi prenota è il socio collegato' );

// ---------- Servizio: tutte o nessuna, posti, booked_by ----------
list( $gb_a1, $gb_s1 ) = $gb_event( 'Gruppo uno', 2 );
$gb_e = null;
try {
	$gb_acts->book_many( $gb_s1, array( $gb_booker, $gb_gino, $gb_rina ), $gb_booker, array( 'price_lists' => array() ) );
} catch ( BookingFailure $e ) {
	$gb_e = $e;
}
asem_ok( $gb_e && BookingFailure::FULL === $gb_e->kind && 0 === $gb_count( $gb_s1 ), 'prenota gruppo: tre persone per due posti = errore tipizzato «posti» e nessuna prenotazione' );
$gb_acts->book_many( $gb_s1, array( $gb_booker, $gb_gino ), $gb_booker, array() );
$gb_r1 = $gb_row( $gb_s1, $gb_booker );
$gb_r2 = $gb_row( $gb_s1, $gb_gino );
asem_ok( 2 === $gb_count( $gb_s1 ) && null === $gb_r1['booked_by'] && (int) $gb_r2['booked_by'] === $gb_booker, 'prenota gruppo: due posti, booked_by vuoto per sé e pari a chi prenota per l\'altro' );
asem_ok( 500 === (int) $gb_r1['fee_due_cents'] && 500 === (int) $gb_r2['fee_due_cents'], 'prenota gruppo: il contributo di ciascuno è fissato alla prenotazione' );
$gb_e = null;
try {
	$gb_acts->book_many( $gb_s1, array( $gb_rina ), $gb_booker, array() );
} catch ( BookingFailure $e ) {
	$gb_e = $e;
}
asem_ok( $gb_e && BookingFailure::FULL === $gb_e->kind, 'prenota gruppo: sull\'ultimo posto già preso il secondo invio trova «posti esauriti»' );
$gb_e = null;
try {
	$gb_acts->book_many( $gb_s1, array( $gb_gino ), $gb_booker, array() );
} catch ( BookingFailure $e ) {
	$gb_e = $e;
}
asem_ok( $gb_e && in_array( $gb_e->kind, array( BookingFailure::FULL, BookingFailure::ALREADY ), true ), 'prenota gruppo: una persona già prenotata viene rifiutata con un motivo tipizzato' );
$gb_acts->cancel_booking( $gb_s1, $gb_gino );
$gb_acts->book( $gb_s1, $gb_gino ); // la segreteria riprenota Gino da sé: non resta a carico di chi aveva prenotato per lui
asem_ok( null === $gb_row( $gb_s1, $gb_gino )['booked_by'], 'prenota gruppo: una nuova prenotazione fatta da sé azzera booked_by' );

// Il blocco è obbligatorio: senza non si procede
list( $gb_a2, $gb_s2 ) = $gb_event( 'Gruppo due', 5 );
\AssociazioneSemplice\Db::$lock_fake = '0';
$gb_e = null;
try {
	$gb_acts->book_many( $gb_s2, array( $gb_gino ), $gb_booker, array() );
} catch ( BookingFailure $e ) {
	$gb_e = $e;
}
\AssociazioneSemplice\Db::$lock_fake = 'x';
asem_ok( $gb_e && BookingFailure::SYSTEM === $gb_e->kind && 0 === $gb_count( $gb_s2 ), 'prenota gruppo: senza il blocco dei posti non si prenota nulla' );

// Un ospite nuovo nasce dentro la transazione: se la prenotazione fallisce non ne resta traccia
$gb_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) );
$gb_e      = null;
try {
	$gb_acts->book_many(
		$gb_s2,
		array( $gb_gino ),
		$gb_booker,
		array(
			'extra'      => 1,
			'new_people' => function () use ( $gb_peo, $gb_booker ) {
				$gid = (int) $gb_peo->create( array( 'type' => 'guest', 'host_person_id' => $gb_booker, 'first_name' => 'Ospite', 'last_name' => 'Annullato', 'phone' => '333 9990000' ) );
				return array( $gid => 'listino-che-non-esiste' ); // il listino non esiste: la prenotazione fallisce dopo la creazione
			},
		)
	);
} catch ( \InvalidArgumentException $e ) {
	$gb_e = $e;
}
asem_ok( $gb_e && 0 === $gb_count( $gb_s2 ) && $gb_before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) ), 'prenota gruppo: se la prenotazione fallisce, l\'ospite creato nella transazione non resta in anagrafica' );

// ---------- Area soci: un solo messaggio per ogni motivo che riguarda l'altro socio ----------
list( $gb_a3, $gb_s3 ) = $gb_event( 'Gruppo tre', 6 );
$gen = Actions::OTHER_MEMBER_FAIL;
asem_ok( $gen === $gb_fail( array( 'session_id' => $gb_s3, 'me' => 1, 'other_last' => array( 'Socio' ), 'other_card' => array( '9999' ) ) ), 'prenota gruppo: tessera inesistente = frase generica' );
asem_ok( $gen === $gb_fail( array( 'session_id' => $gb_s3, 'me' => 1, 'other_last' => array( 'Sbagliato' ), 'other_card' => array( '7002' ) ) ), 'prenota gruppo: cognome sbagliato = la stessa frase' );
asem_ok( 0 === $gb_count( $gb_s3 ), 'prenota gruppo: con un altro socio non valido non si prenota nessuno, nemmeno chi prenota' );
$wpdb->update( Db::t( 'people' ), array( 'no_booking_by_others' => 1 ), array( 'id' => $gb_gino ) );
wp_cache_flush();
asem_ok( $gen === $gb_fail( array( 'session_id' => $gb_s3, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) ), 'prenota gruppo: socio che non vuole essere prenotato da altri = la stessa frase' );
$wpdb->update( Db::t( 'people' ), array( 'no_booking_by_others' => 0, 'email' => '' ), array( 'id' => $gb_gino ) );
wp_cache_flush();
asem_ok( $gen === $gb_fail( array( 'session_id' => $gb_s3, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) ), 'prenota gruppo: socio senza email = la stessa frase' );
$wpdb->update( Db::t( 'people' ), array( 'email' => 'gino.gruppo@example.com', 'birth_date' => ( (int) gmdate( 'Y' ) - 10 ) . '-03-03' ), array( 'id' => $gb_gino ) );
wp_cache_flush();
asem_ok( $gen === $gb_fail( array( 'session_id' => $gb_s3, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) ), 'prenota gruppo: minorenne = la stessa frase' );
$wpdb->update( Db::t( 'people' ), array( 'birth_date' => '1980-05-05' ), array( 'id' => $gb_gino ) );
wp_cache_flush();

// Un invio valido: sé stessi e un altro socio, contributo a carico di chi prenota
Counters::set( 'asem_mt_ofm_' . $gb_booker, array( 0, time() ), 1 );
$gb_msg = $gb_book( array( 'session_id' => $gb_s3, 'me' => 1, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) );
asem_ok( false !== strpos( $gb_msg, '2 persone' ) && 2 === $gb_count( $gb_s3 ), 'prenota gruppo: sé stessi e un altro socio, in un solo invio' );
asem_ok( (int) $gb_row( $gb_s3, $gb_gino )['booked_by'] === $gb_booker && null === $gb_row( $gb_s3, $gb_booker )['booked_by'], 'prenota gruppo: chi prenota per l\'altro socio è registrato' );
asem_ok( $gen === $gb_fail( array( 'session_id' => $gb_s3, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) ), 'prenota gruppo: l\'altro socio già prenotato = la stessa frase, non «già prenotato»' );

// Evento pieno: la risposta non cambia con la tessera giusta o sbagliata (nessun oracolo)
list( $gb_a4, $gb_s4 ) = $gb_event( 'Gruppo pieno', 1 );
$gb_acts->book( $gb_s4, $gb_rina );
$gb_full_ok  = $gb_fail( array( 'session_id' => $gb_s4, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) );
$gb_full_bad = $gb_fail( array( 'session_id' => $gb_s4, 'other_last' => array( 'Nessuno' ), 'other_card' => array( '9999' ) ) );
asem_ok( '' !== $gb_full_ok && $gb_full_ok === $gb_full_bad && $gen !== $gb_full_ok, 'prenota gruppo: a evento pieno la risposta è identica con tessera giusta e sbagliata' );

// Massimo di persone per volta e peso dell'invio nel limite
$gb_many = $gb_fail( array( 'session_id' => $gb_s3, 'guest_ids' => range( 1, Actions::GROUP_MAX + 1 ) ) );
asem_ok( false !== strpos( $gb_many, 'al massimo' ), 'prenota gruppo: oltre il massimo di persone per invio si rifiuta' );
Settings::update( array( 'limits' => array( 'member_book_per_10min' => 3 ) ) );
Counters::set( 'asem_mt_book_' . $gb_booker, array( 0, time() ), 1 );
list( , $gb_s5 ) = $gb_event( 'Gruppo peso', 10 );
$gb_w = $gb_fail( array( 'session_id' => $gb_s5, 'me' => 1, 'guest_ids' => array( 1, 2, 3 ) ) ); // quattro persone con un limite di tre
asem_ok( false !== strpos( $gb_w, 'troppe richieste' ), 'prenota gruppo: un invio conta quante sono le persone nel limite' );
Settings::update( array( 'limits' => array( 'member_book_per_10min' => 500 ) ) );

// Tentativi falliti sugli altri soci: dopo il limite si aspetta, anche con i dati giusti
Counters::set( 'asem_mt_ofm_' . $gb_booker, array( 0, time() ), 600 );
list( , $gb_s6 ) = $gb_event( 'Gruppo limite', 10 );
for ( $gb_i = 0; $gb_i < 5; $gb_i++ ) {
	$gb_fail( array( 'session_id' => $gb_s6, 'other_last' => array( 'Nessuno' ), 'other_card' => array( '9' . $gb_i ) ) );
}
$gb_blocked = $gb_fail( array( 'session_id' => $gb_s6, 'other_last' => array( 'Socio' ), 'other_card' => array( '7002' ) ) );
asem_ok( false !== strpos( $gb_blocked, 'troppe richieste' ) && 0 === $gb_count( $gb_s6 ), 'prenota gruppo: dopo cinque tentativi falliti non si prova più, neanche con i dati giusti' );
Counters::set( 'asem_mt_ofm_' . $gb_booker, array( 0, time() ), 1 );
asem_ok( false === strpos( implode( ' ', array_map( 'wp_json_encode', array_column( \AssociazioneSemplice\Audit::recent( 50 ), 'details' ) ) ), '"Nessuno"' ), 'prenota gruppo: il registro dei tentativi non porta cognomi né tessere' );

wp_set_current_user( 1 );
