<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Un servizio di newsletter. Per aggiungerne uno basta implementare questa interfaccia e registrarlo con il filtro `asem_newsletter_providers`
 * (id => nome della classe, che riceve la rete e le impostazioni nel costruttore). Gli errori si segnalano con \RuntimeException.
 *
 * La chiamata di rete è iniettata ($http): function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 */
interface NewsletterProvider {

	public function id(): string;

	public function label(): string;

	/** Campi di impostazione: chiave => etichetta. */
	public static function fields(): array;

	/** Chiavi di `fields()` che sono segreti (cifrati, mai mostrati). */
	public static function secret_fields(): array;

	/** Manca qualcosa per usarlo? Vuoto se la configurazione è completa. */
	public function missing(): string;

	/** Liste o pubblici tra cui scegliere: id => nome. @return array<string,string> @throws \RuntimeException */
	public function lists(): array;

	/**
	 * Iscrive (o aggiorna) un contatto alla lista. Chi si è disiscritto dal servizio non va reiscritto.
	 *
	 * @param array $contact email, first_name, last_name, type, card
	 * @param bool  $extra   inviare anche tipo di socio e stato della tessera
	 * @throws \RuntimeException
	 */
	public function subscribe( array $contact, string $list, bool $extra ): void;

	/** Toglie (disiscrive) un indirizzo dalla lista; se non c'è non è un errore. @throws \RuntimeException */
	public function remove( string $email, string $list ): void;

	/** Prova di collegamento. @return array{ok:bool,message:string} */
	public function check(): array;
}
