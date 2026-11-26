<?php
/**
 * Motivo tipizzato per cui una prenotazione di gruppo non si può fare: il chiamante decide cosa dire (e non dice mai, per un altro socio, più di una frase generica).
 *
 * @package AssociazioneSemplice
 */

namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class BookingFailure extends \InvalidArgumentException {

	const FULL     = 'full';     // posti esauriti
	const ALREADY  = 'already';  // la persona è già prenotata
	const APPROVAL = 'approval'; // evento su richiesta
	const CONFLICT = 'conflict'; // fascia occupata o altro rifiuto di un'estensione
	const LIMIT    = 'limit';    // più persone di quante l'evento ne ammette per volta
	const GONE     = 'gone';     // data o attività non più disponibili
	const SYSTEM   = 'system';   // il database non permette di farlo in sicurezza, o il blocco non è stato preso

	/** @var string uno dei motivi sopra */
	public $kind;

	/** @var int persona a cui si riferisce il motivo (0 = nessuna in particolare) */
	public $person_id;

	public function __construct( string $message, string $kind, int $person_id = 0 ) {
		parent::__construct( $message );
		$this->kind      = $kind;
		$this->person_id = $person_id;
	}
}
