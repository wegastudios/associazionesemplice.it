<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Aggiornamento diretto da WordPress (Bacheca → Aggiornamenti) per i pacchetti scaricati dal portale di Associazione Semplice.
 *
 * Questo file NON fa parte del pacchetto per WordPress.org (è nell'elenco `tests/portal-files.txt`): lì gli aggiornamenti li dà WordPress.org.
 * Ogni 12 ore o più (limite `update_check_hours`) chiede al portale l'ultima versione di ciò che è installato (`POST /api/plugin/update`) e
 * la mette nell'elenco degli aggiornamenti di WordPress. Il pacchetto firmato dal portale vale pochi minuti: per questo si chiede di nuovo
 * proprio quando si preme «Aggiorna», non si conserva. Il Pro manda la chiave di licenza e il dominio; il gratuito non manda nulla di suo.
 * Un errore del portale non cambia niente: restano le ultime versioni note.
 */
final class Updater {

	const API    = 'https://associazionesemplice.it/api/plugin/update';
	const OPT    = 'asem_portal_updates';
	const PREFIX = 'asem-portal://';

	public static function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
	}

	/** Scelta dell'amministratore (Limiti e soglie) e sito non locale. */
	public static function enabled(): bool {
		return 1 === Limits::get( 'portal_updates' ) && ! LicenseClient::is_local();
	}

	public static function api(): string {
		return (string) apply_filters( 'asem_portal_update_api', self::API );
	}

	/** I plugin installati che si aggiornano da qui. @return array<string,array{basename:string,slug:string,name:string,version:string}> */
	public static function products(): array {
		$out = array();
		if ( defined( 'ASEM_VERSION' ) && defined( 'ASEM_FILE' ) ) {
			$out['free'] = array( 'basename' => plugin_basename( ASEM_FILE ), 'slug' => 'associazionesemplice', 'name' => 'Associazione Semplice', 'version' => (string) ASEM_VERSION );
		}
		if ( defined( 'ASEM_PRO_VERSION' ) && defined( 'ASEM_PRO_DIR' ) ) {
			$out['pro'] = array( 'basename' => plugin_basename( ASEM_PRO_DIR . 'associazionesemplice-pro.php' ), 'slug' => 'associazionesemplice-pro', 'name' => 'Associazione Semplice Pro', 'version' => (string) ASEM_PRO_VERSION );
		}
		return $out;
	}

	/**
	 * Risposta del portale → dati validi o null. La versione deve essere numerica; gli indirizzi devono essere https.
	 *
	 * @param mixed $body testo JSON
	 * @return array{version:string,update:bool,package:string,url:string}|null
	 */
	public static function parse( $body ): ?array {
		$j = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $j ) || ! isset( $j['version'] ) || ! is_string( $j['version'] ) || ! UpdateCheck::valid_version( trim( $j['version'] ) ) ) {
			return null;
		}
		$https = function ( $v ) {
			return is_string( $v ) && 0 === strpos( trim( $v ), 'https://' ) ? trim( $v ) : '';
		};
		return array(
			'version' => trim( $j['version'] ),
			'update'  => ! empty( $j['update'] ),
			'package' => $https( $j['package'] ?? '' ),
			'url'     => $https( $j['url'] ?? '' ),
		);
	}

	/** Il pacchetto viene davvero dal portale (stesso sito dell'indirizzo di verifica)? */
	public static function package_is_ours( string $package ): bool {
		$a = wp_parse_url( self::api() );
		$p = wp_parse_url( $package );
		return ! empty( $a['host'] ) && ! empty( $p['host'] ) && 'https' === ( $p['scheme'] ?? '' ) && strtolower( $a['host'] ) === strtolower( $p['host'] );
	}

	/**
	 * Chiede al portale lo stato di un prodotto. Il Pro senza chiave non chiede niente.
	 *
	 * @return array|null dati validi (vedi parse), null se non si può o il portale non risponde bene
	 */
	public static function ask( string $product ): ?array {
		$all = self::products();
		if ( ! isset( $all[ $product ] ) ) {
			return null;
		}
		$body = array( 'product' => $product, 'version' => $all[ $product ]['version'], 'domain' => LicenseClient::normalize_domain( home_url() ) );
		if ( 'pro' === $product ) {
			$key = License::key();
			if ( '' === $key || '' === $body['domain'] ) {
				return null;
			}
			$body['license_key'] = $key;
		}
		$res = wp_remote_post(
			self::api(),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null; // 403 (licenza), 429 (troppe richieste), rete: si tiene l'ultimo stato
		}
		return self::parse( wp_remote_retrieve_body( $res ) );
	}

	/** Ultimo stato salvato. @return array{checked_at:?string,free?:array,pro?:array} */
	public static function state(): array {
		$s = get_option( self::OPT, array() );
		return is_array( $s ) ? $s + array( 'checked_at' => null ) : array( 'checked_at' => null );
	}

	private static function due(): bool {
		$last = self::state()['checked_at'];
		return ! $last || strtotime( (string) $last . ' UTC' ) + max( 12, Limits::get( 'update_check_hours' ) ) * HOUR_IN_SECONDS <= time();
	}

	/** Aggiorna lo stato chiedendo al portale (un prodotto che non risponde conserva l'ultimo dato). */
	public static function refresh(): void {
		$state = self::state();
		foreach ( array_keys( self::products() ) as $p ) {
			$r = self::ask( $p );
			if ( $r ) {
				$state[ $p ] = $r;
			}
		}
		$state['checked_at'] = Db::now();
		update_option( self::OPT, $state, false );
	}

	/** Filtro di WordPress: mette gli aggiornamenti nell'elenco (Bacheca → Aggiornamenti e pagina Plugin). */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! self::enabled() ) {
			return $transient;
		}
		if ( self::due() ) {
			self::refresh();
		}
		$state = self::state();
		foreach ( self::products() as $p => $info ) {
			if ( empty( $state[ $p ]['version'] ) || ! UpdateCheck::is_newer( $info['version'], (string) $state[ $p ]['version'] ) || empty( $state[ $p ]['update'] ) ) {
				continue;
			}
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $info['basename'] ] = (object) array(
				'id'          => 'associazionesemplice.it/' . $info['slug'],
				'slug'        => $info['slug'],
				'plugin'      => $info['basename'],
				'new_version' => (string) $state[ $p ]['version'],
				'url'         => '' !== (string) ( $state[ $p ]['url'] ?? '' ) ? (string) $state[ $p ]['url'] : 'https://associazionesemplice.it',
				'package'     => self::PREFIX . $p, // l'indirizzo vero si chiede al momento dello scarico: vale pochi minuti
			);
		}
		return $transient;
	}

	/** Scheda «Visualizza i dettagli» dell'aggiornamento. */
	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || ! self::enabled() ) {
			return $res;
		}
		foreach ( self::products() as $p => $info ) {
			if ( $args->slug === $info['slug'] && ! empty( self::state()[ $p ]['version'] ) ) {
				$st = self::state()[ $p ];
				return (object) array(
					'name'          => $info['name'],
					'slug'          => $info['slug'],
					'version'       => (string) $st['version'],
					'author'        => '<a href="https://www.wegastudios.com">Wega Studios</a>',
					'homepage'      => 'https://associazionesemplice.it',
					'requires'      => '6.0',
					'requires_php'  => '7.4',
					'sections'      => array( 'description' => '<p>' . esc_html( $info['name'] ) . ': gestione di soci, attività e prima nota per associazioni. L\'aggiornamento si scarica dal portale di Associazione Semplice.</p>' ),
					'download_link' => self::PREFIX . $p,
				);
			}
		}
		return $res;
	}

	/** Scarico del pacchetto: chiede al portale un indirizzo fresco e scarica lo zip. */
	public static function download( $reply, $package, $upgrader = null ) {
		if ( ! is_string( $package ) || 0 !== strpos( $package, self::PREFIX ) ) {
			return $reply;
		}
		$p = substr( $package, strlen( self::PREFIX ) );
		$r = in_array( $p, array( 'free', 'pro' ), true ) ? self::ask( $p ) : null;
		if ( ! $r || empty( $r['update'] ) || '' === $r['package'] || ! self::package_is_ours( $r['package'] ) ) {
			return new \WP_Error( 'asem_update_unavailable', 'L\'aggiornamento non è disponibile adesso dal portale di Associazione Semplice (licenza non in regola, troppe richieste o portale non raggiungibile). Riprova più tardi o scaricalo dall\'area clienti.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $r['package'] );
	}
}
