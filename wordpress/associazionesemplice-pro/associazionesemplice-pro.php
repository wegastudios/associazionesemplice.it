<?php
/**
 * Plugin Name:       Associazione Semplice Pro
 * Description:       Funzioni avanzate per Associazione Semplice: pagamenti online, conti e fondi, contabilità e report, registri, comunicazioni, app e Wallet. Richiede Associazione Semplice.
 * Plugin URI:        https://associazionesemplice.it
 * Update URI:        https://associazionesemplice.it/
 * Version:           1.3.26
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  associazionesemplice
 * Author:            Wega Studios
 * Author URI:        https://www.wegastudios.com
 * License:           GPL-2.0-or-later
 * Text Domain:       associazionesemplice-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'ASEM_PRO_VERSION', '1.3.26' );
define( 'ASEM_PRO_API', 18 ); // livello di compatibilità richiesto: deve coincidere con ASEM_API di Associazione Semplice
define( 'ASEM_PRO_MIN_FREE', '1.3.40' ); // la prima versione di Associazione Semplice che ha tutto ciò che questo Pro usa (censimento delle chiamate): sotto, il Pro si sospende con un avviso, senza errori
define( 'ASEM_PRO_DIR', plugin_dir_path( __FILE__ ) );

/** Il gratuito installato è più vecchio di quello che questo Pro richiede? */
function asem_pro_free_too_old(): bool {
	return defined( 'ASEM_VERSION' ) && version_compare( (string) ASEM_VERSION, ASEM_PRO_MIN_FREE, '<' );
}

/**
 * Installare e aggiornare Associazione Semplice e Associazione Semplice Pro si può fare in qualunque ordine e anche uno solo dei due: questo file non dà mai
 * errori. Le versioni sono indipendenti; contano solo la presenza di Associazione Semplice e il livello di compatibilità (`ASEM_API`), che cambia
 * di rado. Se non ci sono o non corrispondono il Pro non carica le sue funzioni e dice cosa fare (i dati non si toccano).
 *
 * @return string '' se si può caricare, altrimenti il messaggio da mostrare
 */
function asem_pro_problem(): string {
	if ( ! class_exists( 'AssociazioneSemplice\Edition' ) || ! defined( 'ASEM_VERSION' ) ) {
		return '<strong>Associazione Semplice Pro</strong> ha bisogno del plugin <strong>Associazione Semplice</strong>: installalo e attivalo.';
	}
	if ( ! defined( 'ASEM_API' ) || ASEM_API !== ASEM_PRO_API ) {
		return '<strong>Associazione Semplice Pro</strong> non è compatibile con questa versione di Associazione Semplice: le funzioni avanzate sono sospese. Aggiorna entrambi all\'ultima versione; il resto funziona e i dati sono al sicuro.';
	}
	if ( asem_pro_free_too_old() ) {
		return '<strong>Associazione Semplice Pro</strong> ha bisogno di <strong>Associazione Semplice</strong> ' . ASEM_PRO_MIN_FREE . ' o successiva: le funzioni avanzate sono sospese. Aggiorna Associazione Semplice; il resto funziona e i dati sono al sicuro.';
	}
	return '';
}

/**
 * Gli aggiornamenti del Pro dal portale. Se il gratuito è quello del portale, la classe è sua; se viene da WordPress.org (dove aggiorna WordPress.org)
 * non la ha, e allora il Pro usa la sua copia (la stessa, messa nel pacchetto dalla costruzione). Si carica anche quando il Pro non è compatibile
 * con il gratuito, perché è proprio lì che serve poterlo aggiornare.
 */
function asem_pro_updater(): void {
	if ( ! defined( 'ASEM_VERSION' ) || ! defined( 'ASEM_DIR' ) ) {
		return;
	}
	if ( ! class_exists( 'AssociazioneSemplice\Updater' ) ) {
		$own = ASEM_PRO_DIR . 'updater/Updater.php';
		if ( ! is_readable( $own ) ) {
			return;
		}
		require_once $own;
	}
	\AssociazioneSemplice\Updater::register();
}

/**
 * Con un gratuito incompatibile le classi del Pro non si caricano, quindi mancano i suoi collegamenti con l'aggiornatore (chiave di licenza e dominio):
 * qui ne bastano due minimi, che leggono la chiave salvata, perché proprio in quel caso il Pro deve poter chiedere il suo aggiornamento.
 */
function asem_pro_updater_basics(): void {
	add_filter(
		'asem_portal_license_key',
		function ( $key ) {
			if ( '' !== (string) $key || ! class_exists( 'AssociazioneSemplice\Settings' ) || ! class_exists( 'AssociazioneSemplice\Secrets' ) ) {
				return $key;
			}
			$opt = get_option( \AssociazioneSemplice\Settings::OPTION, array() );
			$raw = is_array( $opt ) && isset( $opt['license_key'] ) ? (string) $opt['license_key'] : '';
			if ( '' !== $raw && \AssociazioneSemplice\Secrets::is_encrypted( $raw ) ) {
				$plain = \AssociazioneSemplice\Secrets::decrypt( $raw, wp_salt( 'auth' ) );
				return null === $plain ? '' : $plain;
			}
			return $raw;
		}
	);
	add_filter(
		'asem_portal_domain',
		function ( $domain ) {
			if ( '' !== (string) $domain ) {
				return $domain;
			}
			$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		}
	);
}

/** Pulsante dell'avviso di incompatibilità: «Aggiorna il Pro» se il portale ne ha uno nuovo, altrimenti «Controlla aggiornamenti». */
function asem_pro_notice_button(): string {
	if ( asem_pro_free_too_old() ) { // aggiornare il Pro non serve: va aggiornato il gratuito (se WordPress lo ha già trovato) oppure si controlla se c'è
		if ( ! current_user_can( 'update_plugins' ) ) {
			return '';
		}
		$free_file = 'associazionesemplice/associazionesemplice.php';
		$updates   = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) && isset( $updates->response[ $free_file ] ) ) {
			$url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $free_file ) ), 'upgrade-plugin_' . $free_file );
			return ' <a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( 'Aggiorna Associazione Semplice' ) . '</a>';
		}
		return ' <a class="button" href="' . esc_url( self_admin_url( 'update-core.php?force-check=1' ) ) . '">' . esc_html( 'Controlla aggiornamenti' ) . '</a>';
	}
	if ( ! current_user_can( 'update_plugins' ) || ! class_exists( 'AssociazioneSemplice\Updater' ) || ! method_exists( '\AssociazioneSemplice\Updater', 'pro_update_available' ) || ! method_exists( '\AssociazioneSemplice\Updater', 'check_url' ) || ! \AssociazioneSemplice\Updater::enabled() ) {
		return '';
	}
	if ( null !== \AssociazioneSemplice\Updater::pro_update_available() ) {
		$file = plugin_basename( __FILE__ );
		$url  = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
		return ' <a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( 'Aggiorna il Pro' ) . '</a>';
	}
	return ' <a class="button" href="' . esc_url( \AssociazioneSemplice\Updater::check_url() ) . '">' . esc_html( 'Controlla aggiornamenti' ) . '</a>';
}

// All'attivazione di Pro mancano ancora i dati di partenza delle funzioni avanzate (ad esempio il conto corrente accanto alla cassa).
register_activation_hook(
	__FILE__,
	function () {
		if ( '' === asem_pro_problem() ) {
			\AssociazioneSemplice\Edition::add_dir( plugin_dir_path( __FILE__ ) . 'includes' );
			\AssociazioneSemplice\ProBoot::init(); // le funzioni devono essere dichiarate prima di creare i loro dati di partenza
			\AssociazioneSemplice\ProSchema::create(); // le tabelle delle funzioni aggiuntive
			\AssociazioneSemplice\Install::seed();
		}
	}
);

/**
 * Le funzioni avanzate sono le stesse classi di Associazione Semplice (stesso spazio dei nomi) messe in una cartella a parte: il plugin gratuito
 * le trova da solo appena questa cartella gli viene indicata.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			asem_pro_updater();
		} else {
			// Sul sito pubblico l'aggiornatore non si legge nemmeno: si carica solo se WordPress lavora sugli aggiornamenti dei plugin (ad esempio chiesti da remoto).
			$load = static function ( $value = null ) {
				asem_pro_updater(); // si registra una volta sola; i suoi hook si aggiungono mentre WordPress esegue quello in corso
				return $value;
			};
			foreach ( array( 'pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_pre_download', 'upgrader_process_complete' ) as $hook ) {
				add_filter( $hook, $load, 1 );
			}
		}
		$problem = asem_pro_problem();
		if ( '' !== $problem && ( ( defined( 'ASEM_API' ) && ASEM_API !== ASEM_PRO_API ) || asem_pro_free_too_old() ) ) {
			asem_pro_updater_basics(); // livello di compatibilità diverso: le classi del Pro non si caricano, ma l'aggiornamento deve funzionare
		}
		if ( '' !== $problem ) {
			add_action(
				'admin_notices',
				function () use ( $problem ) {
					if ( current_user_can( 'activate_plugins' ) ) {
						$button = ( defined( 'ASEM_API' ) && ASEM_API !== ASEM_PRO_API ) || asem_pro_free_too_old() ? asem_pro_notice_button() : ''; // il pulsante solo se il problema è la versione, non l'assenza del gratuito
						echo '<div class="notice notice-error"><p>' . wp_kses( $problem, array( 'strong' => array() ) ) . wp_kses( $button, array( 'a' => array( 'class' => array(), 'href' => array() ) ) ) . '</p></div>';
					}
				}
			);
			return;
		}
		\AssociazioneSemplice\Edition::add_dir( ASEM_PRO_DIR . 'includes' );
		if ( '' === \AssociazioneSemplice\Edition::mismatch() ) {
			\AssociazioneSemplice\ProBoot::init(); // dichiara le funzioni e si aggancia ai punti di estensione
		}
	},
	5 // prima che Associazione Semplice si avvii (priorità 10)
);

// Alla disattivazione si tolgono i lavori periodici della licenza e degli aggiornamenti.
register_deactivation_hook(
	__FILE__,
	function () {
		foreach ( array( 'asem_license_check', 'asem_update_check', 'asem_license_resync', 'asem_watch_month_end', 'asem_sms_hourly', 'asem_sms_queue', 'asem_newsletter_sync' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
);
