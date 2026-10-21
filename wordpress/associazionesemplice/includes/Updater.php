<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Aggiornamento diretto da WordPress (Bacheca → Aggiornamenti) di Associazione Semplice Pro, che non sta su WordPress.org.
 *
 * Questo file fa parte del Pro (`tests/pro-files.txt`) e NON del gratuito, che sta su WordPress.org e si aggiorna da lì.
 * Ogni 12 ore o più (limite `update_check_hours`) chiede al portale l'ultima versione di ciò che è installato (`POST /api/plugin/update`) e
 * la mette nell'elenco degli aggiornamenti di WordPress. Il pacchetto firmato dal portale vale pochi minuti: per questo si chiede di nuovo
 * proprio quando si preme «Aggiorna», non si conserva. Manda la chiave di licenza e il dominio del sito.
 * Un errore del portale non cambia niente: restano le ultime versioni note.
 */
final class Updater {

	const API    = 'https://associazionesemplice.it/api/plugin/update';
	const OPT    = 'asem_portal_updates';
	const PREFIX = 'asem-portal://';

	/**
	 * Chiavi pubbliche con cui si firmano i pacchetti (kid => Ed25519, base64url). La chiave privata non sta sul server del portale: chi lo
	 * compromette non può far installare codice sui siti. Un kid sconosciuto o una firma sbagliata bloccano l'installazione.
	 */
	const PACKAGE_KEYS = array(
		'pkg-2026-10-85dce7' => 'ld_4NilmHT-TtaH8vUw_YGq7eEt4a0e4L4YAxSzP6oI',
	);

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
			'sha256'  => isset( $j['sha256'] ) && is_string( $j['sha256'] ) ? strtolower( trim( $j['sha256'] ) ) : '',
			'signature' => isset( $j['signature'] ) && is_string( $j['signature'] ) ? trim( $j['signature'] ) : '',
			'kid'     => isset( $j['kid'] ) && is_string( $j['kid'] ) ? trim( $j['kid'] ) : '',
		);
	}

	/** base64url senza padding → byte ('' se non valido). */
	private static function b64d( string $s ): string {
		$r = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $r ? '' : $r;
	}

	/**
	 * La firma del portale per «prodotto|versione|sha256» torna con la chiave del suo kid?
	 *
	 * @param array<string,string> $keys kid => chiave pubblica (base64url)
	 */
	public static function verify_signature( string $product, string $version, string $sha256, string $kid, string $signature, array $keys ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || ! isset( $keys[ $kid ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return false;
		}
		$pub = self::b64d( $keys[ $kid ] );
		$sig = self::b64d( $signature );
		return 32 === strlen( $pub ) && 64 === strlen( $sig ) && sodium_crypto_sign_verify_detached( $sig, $product . '|' . $version . '|' . $sha256, $pub );
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

	/** Icone del plugin nell'elenco degli aggiornamenti (immagini pubblicate dal portale). */
	private static function icons(): array {
		$base = 'https://associazionesemplice.it/assets/plugin/';
		return array( '1x' => $base . 'icon-128x128.png', '2x' => $base . 'icon-256x256.png', 'default' => $base . 'icon-256x256.png' );
	}

	/** Banner nella scheda dei dettagli. */
	private static function banners(): array {
		$base = 'https://associazionesemplice.it/assets/plugin/';
		return array( 'low' => $base . 'banner-772x250.png', 'high' => $base . 'banner-1544x500.png' );
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
					'icons'         => self::icons(),
					'banners'       => self::banners(),
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
		$r = 'pro' === $p ? self::ask( $p ) : null;
		if ( ! $r || empty( $r['update'] ) || '' === $r['package'] || ! self::package_is_ours( $r['package'] ) ) {
			return new \WP_Error( 'asem_update_unavailable', 'L\'aggiornamento non è disponibile adesso dal portale di Associazione Semplice (licenza non in regola, troppe richieste o portale non raggiungibile). Riprova più tardi o scaricalo dall\'area clienti.' );
		}
		$installed = self::products()[ $p ]['version'] ?? '';
		if ( '' === $installed || ! UpdateCheck::is_newer( $installed, $r['version'] ) ) { // niente passi indietro: una versione vecchia firmata non si reinstalla
			return new \WP_Error( 'asem_update_not_newer', 'La versione offerta dal portale non è più recente di quella installata.' );
		}
		if ( ! self::verify_signature( $p, $r['version'], $r['sha256'], $r['kid'], $r['signature'], self::PACKAGE_KEYS ) ) {
			return new \WP_Error( 'asem_update_unsigned', 'Il pacchetto offerto dal portale non ha una firma valida: non è stato installato. Scaricalo dall\'area clienti e scrivici.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $r['package'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( $r['sha256'], (string) hash_file( 'sha256', $file ) ) ) { // il file scaricato è proprio quello firmato
			wp_delete_file( $file );
			return new \WP_Error( 'asem_update_hash', 'Il file scaricato non corrisponde alla firma del portale: non è stato installato.' );
		}
		return $file;
	}
}
