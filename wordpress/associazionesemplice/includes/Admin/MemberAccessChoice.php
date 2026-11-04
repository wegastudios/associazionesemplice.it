<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * La scelta dell'amministratore su come entrano i soci: con un link o un codice via email (nessun utente WordPress) oppure anche con un
 * utente WordPress per ogni socio. Il testo è uno solo, usato dalla configurazione guidata e dalle Impostazioni.
 */
final class MemberAccessChoice {

	/** WooCommerce è installato e attivo su questo sito. */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/** Campi e spiegazioni (HTML da passare a wp_kses con Html::allowed). */
	public static function html(): string {
		$wp   = Settings::member_wp_users();
		$html = '<input type="hidden" name="wpusers_present" value="1">'
			. '<p class="asem-wiz-q"><strong>Come entrano i soci nell\'area riservata?</strong></p>'
			. '<p><label><input type="radio" name="member_wp_users" value="0"' . checked( ! $wp, true, false ) . '> <strong>Con un accesso proprio del plugin, senza utente WordPress</strong> (consigliato)</label></p>'
			. '<ul style="margin:0 0 12px 22px;list-style:disc">'
			. '<li><strong>Vantaggi.</strong> I soci entrano con email e password proprie, che scelgono alla prima entrata e restano nel plugin: non sono utenti del tuo sito, quindi non hanno una pagina di accesso di WordPress da attaccare, non compaiono nell\'elenco utenti e non possono toccare niente fuori dall\'area soci. Le password del sito restano solo di chi lo gestisce. Chi dimentica la password riceve per email un link che gli fa scegliere una nuova password, e chi preferisce può entrare con un link o un codice senza password.</li>'
			. '<li><strong>Svantaggi.</strong> I soci non hanno un account WordPress, quindi non possono usare altre funzioni del sito che lo richiedono (commentare da registrati, aree riservate di altri plugin). La posta del sito deve funzionare, perché il recupero della password e il link arrivano per email: provala prima.</li>'
			. '</ul>'
			. '<p><label><input type="radio" name="member_wp_users" value="1"' . checked( $wp, true, false ) . '> <strong>Anche con un utente WordPress per ogni socio</strong></label></p>'
			. '<ul style="margin:0 0 12px 22px;list-style:disc">'
			. '<li><strong>Vantaggi.</strong> I soci hanno un account del sito con una password e possono usarlo anche con altri plugin che lo richiedono.</li>'
			. '<li><strong>Svantaggi.</strong> Ogni socio è un utente del sito: la pagina di accesso di WordPress è esposta a tentativi di indovinare le password di tutti, le password dei soci vivono sul sito principale, e i plugin di sicurezza o di cache possono interferire con l\'accesso. Hai due archivi di persone da tenere allineati.</li>'
			. '</ul>';
		if ( self::woocommerce_active() ) {
			$html .= '<p class="description" style="border-left:4px solid #dba617;padding:6px 10px"><strong>Ho rilevato WooCommerce.</strong> Se vuoi che i soci acquistino nel negozio con il proprio account (storico degli ordini, indirizzi salvati, prezzi o sconti riservati agli iscritti), serve un utente WordPress: in quel caso scegli la seconda voce. Se invece il negozio serve solo per pagare le quote e i soci acquistano come ospiti, la prima basta e non mescola i soci con i tuoi clienti.</p>';
		}
		return $html
			. '<p class="description">Chi ha un potere nell\'amministrazione (segreteria, presidente e vicepresidente, tesoriere, staff) ha sempre un utente WordPress, in entrambi i casi: l\'utente nasce quando gli dai il ruolo.</p>'
			. '<p class="description"><strong>Cosa passa a WordPress e a WooCommerce.</strong> In ogni caso solo ciò che serve per creare un utente: nome, cognome ed email. Telefono, indirizzo, codice fiscale, data di nascita, quote e pagamenti restano nell\'archivio del plugin e non vengono copiati nei dati di WordPress o di WooCommerce.</p>'
			. '<p class="description">La scelta è degli amministratori e si cambia quando vuoi. Cambiarla non tocca i soci già registrati: vale per quelli nuovi.</p>';
	}
}
