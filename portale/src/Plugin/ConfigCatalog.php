<?php
namespace Portale\Plugin;

use Portale\Config;
use Portale\Site;

/**
 * Catalogo costruito da `plans` di Config (config.php più le modifiche salvate dal pannello). È la sorgente di oggi:
 * quando l'editor con bozza e «Pubblica» avrà tabelle proprie, cambia solo questa classe.
 */
final class ConfigCatalog implements Catalog {

	/** Livelli di assistenza (decisione 10/11/2026; tempi di risposta in ARCHITETTURA_DOMINI §7). Di base: gratuito = free, a pagamento = paid. */
	const SUPPORT_LEVELS = array( 'free', 'paid', 'top' );

	/** @var int */
	private $rev;

	/** @param int $rev numero crescente: cambia a ogni pubblicazione (per ora fisso, finché non c'è «Pubblica») */
	public function __construct( int $rev = 1 ) {
		$this->rev = max( 1, $rev );
	}

	public function document(): array {
		$plans  = array();
		$prices = array();
		$order  = 0;
		foreach ( (array) Config::get( 'plans', array() ) as $key => $p ) {
			$code    = Site::slug( (string) $key );
			$plans[] = array(
				'code'          => $code,
				'name'          => Site::planName( (string) $key ),
				'plan_version'  => 1,
				'audience'      => (string) ( $p['description'] ?? '' ),
				'order'         => ++$order,
				'visible'       => false !== ( $p['visible'] ?? true ),
				'support_level' => in_array( $p['support_level'] ?? '', self::SUPPORT_LEVELS, true ) ? (string) $p['support_level'] : ( 'free' === $key ? 'free' : 'paid' ),
				'limits'        => (object) array(),
			);
			foreach ( (array) ( $p['prices'] ?? array() ) as $period => $pr ) {
				if ( ! is_array( $pr ) || ! isset( $pr['cents'] ) ) {
					continue;
				}
				$prices[] = array( 'plan' => $code, 'period' => (string) $period, 'cents' => (int) $pr['cents'], 'currency' => 'EUR' );
			}
		}
		return array(
			'schema'   => self::SCHEMA,
			'product'  => 'plugin',
			'name'     => 'Associazione Semplice',
			'rev'      => $this->rev,
			'plans'    => $plans,
			'features' => array(),
			'prices'   => $prices,
			'promos'   => array(),
		);
	}

	public function plan( string $code ): ?array {
		foreach ( $this->document()['plans'] as $p ) {
			if ( $p['code'] === $code && $p['visible'] ) {
				return $p;
			}
		}
		return null;
	}

	public function price( string $code, string $period ): ?array {
		if ( null === $this->plan( $code ) ) {
			return null;
		}
		foreach ( $this->document()['prices'] as $pr ) {
			if ( $pr['plan'] === $code && $pr['period'] === $period ) {
				return array( 'cents' => $pr['cents'], 'currency' => $pr['currency'] );
			}
		}
		return null;
	}
}
