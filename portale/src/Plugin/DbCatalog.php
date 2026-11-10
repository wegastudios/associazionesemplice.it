<?php
namespace Portale\Plugin;

use Portale\Db;

/**
 * Catalogo pubblicato dal pannello (`/wordpress/admin/catalogo`). Finché non è mai stato pubblicato niente, e per i piani nati dopo
 * l'ultima pubblicazione, vale {@see ConfigCatalog}: nessun cambiamento visibile prima del primo «Pubblica».
 * Nomi e prezzi sono sempre quelli vivi della configurazione (si modificano in «Piani e prezzi»), il resto viene dall'ultima revisione.
 */
final class DbCatalog implements Catalog {

	/** @var Db */
	private $db;

	public function __construct( Db $db ) {
		$this->db = $db;
	}

	public function document(): array {
		$pub = CatalogStore::published( $this->db );
		$rev = (int) ( $pub['rev'] ?? 1 );
		$doc = ( new ConfigCatalog( $rev ) )->document();
		if ( ! $pub ) {
			return $doc;
		}
		$byCode = array();
		foreach ( (array) $pub['plans'] as $p ) {
			$byCode[ $p['code'] ] = $p;
		}
		foreach ( $doc['plans'] as $i => $p ) {
			if ( isset( $byCode[ $p['code'] ] ) ) {
				$q                  = $byCode[ $p['code'] ];
				$doc['plans'][ $i ] = array(
					'code'          => $p['code'],
					'name'          => $p['name'], // vivo dalla configurazione
					'plan_version'  => (int) $q['plan_version'],
					'audience'      => (string) $q['audience'],
					'order'         => (int) $q['order'],
					'visible'       => (bool) $q['visible'],
					'support_level' => (string) $q['support_level'],
					'limits'        => (object) (array) ( $q['limits'] ?? array() ),
				);
			}
		}
		$doc['features'] = (array) ( $pub['features'] ?? array() );
		return $doc;
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
