<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Errore della cifratura dei file: `reason` dice perché (nessun testo per l'utente: lo compone chi mostra l'errore). */
final class FileCryptError extends \RuntimeException {

	const NO_SODIUM  = 'nosodium';  // la libreria di cifratura non c'è
	const NO_KEY     = 'nokey';     // la chiave con cui il file è stato cifrato non è disponibile su questo sito
	const BAD_KEY    = 'badkey';    // la chiave non ha la forma giusta
	const AUTH       = 'auth';      // il contenuto non si apre: chiave diversa o file modificato
	const FORMAT     = 'format';    // l'intestazione non è quella attesa
	const TRUNCATED  = 'truncated'; // il file è stato tagliato
	const HASH       = 'hash';      // l'impronta del contenuto non coincide con quella registrata
	const IO         = 'io';        // il file non si legge o non si scrive

	/** @var string */
	public $reason;

	public function __construct( string $reason ) {
		parent::__construct( 'FileCrypt: ' . $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio tecnico interno, mai mostrato così
		$this->reason = $reason;
	}
}
