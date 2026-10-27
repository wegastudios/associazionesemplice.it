<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Un fornitore di SMS. Per aggiungerne uno basta implementare questa interfaccia e registrarlo con il filtro `asem_sms_providers`
 * (id => nome della classe, che riceve la rete e le impostazioni nel costruttore).
 *
 * La chiamata di rete è iniettata ($http): function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 */
interface SmsProvider {

	/** Identificativo breve («brevo», «twilio»). */
	public function id(): string;

	/** Nome da mostrare. */
	public function label(): string;

	/** Campi di impostazione richiesti: chiave => etichetta (le chiavi segrete sono quelle elencate da {@see secret_fields()}). */
	public static function fields(): array;

	/** Chiavi di `fields()` che sono segreti (cifrati, mai mostrati). */
	public static function secret_fields(): array;

	/** Manca qualcosa per usarlo? Vuoto se la configurazione è completa. */
	public function missing(): string;

	/**
	 * Invia un SMS.
	 *
	 * @param string $to     numero in formato E.164 (+393331234567)
	 * @param string $sender mittente (può essere ignorato dal fornitore)
	 * @return array{ok:bool,ref:string,error:string,permanent:bool,parts:int} permanent = inutile riprovare (numero non valido, chiave rifiutata)
	 */
	public function send( string $to, string $text, string $sender ): array;

	/**
	 * Prova di collegamento, con i crediti (o il saldo) se il fornitore li dà. Non invia messaggi.
	 *
	 * @return array{ok:bool,message:string,credits:?string}
	 */
	public function check(): array;
}
