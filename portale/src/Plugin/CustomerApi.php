<?php
namespace Portale\Plugin;

use Portale\Billing;
use Portale\Db;
use Portale\Domain;
use Portale\Releases;
use Portale\Site;
use Portale\UserError;

/**
 * Operazioni del plugin per la nuova area clienti (`/account`, sessione di portale-vendita). Il negozio chiama SOLO questa classe:
 * niente query dirette sulle licenze del plugin. Ogni metodo riceve l'id del cliente già autenticato e controlla che la licenza sia sua.
 *   license.sites → sites()   domain.check → domainCheck()   domain.set → domainSet()   download.link → downloadLink()
 */
final class CustomerApi {

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;

	public function __construct( Db $db, Billing $billing ) {
		$this->db      = $db;
		$this->billing = $billing;
	}

	/** Siti (licenze) del cliente. Mai la chiave di licenza. */
	public function sites( string $customerId ): array {
		$out = array();
		foreach ( $this->billing->forCustomer( $customerId ) as $s ) {
			$st    = sub_status( $s );
			$out[] = array(
				'id'                   => (string) $s['id'],
				'domain'               => (string) $s['domain'],
				'plan'                 => Site::slug( (string) $s['plan_code'] ),
				'status'               => $st[0],
				'active'               => 'Attiva' === $st[0],
				'expires_at'           => $s['expires_at'] ? (string) $s['expires_at'] : null,
				'can_change_domain_at' => Billing::domainUnlockAt( $s ),
				'downloads'            => DownloadLink::products( $s ),
			);
		}
		return $out;
	}

	/** Il dominio si può cambiare adesso? Non modifica nulla. @return array{ok:bool,domain:string,message:string,unlock_at:?string} */
	public function domainCheck( string $customerId, string $subId, string $input ): array {
		$sub = $this->own( $customerId, $subId );
		$dom = Domain::forLicense( $input );
		$res = array( 'ok' => false, 'domain' => $dom, 'message' => '', 'unlock_at' => Billing::domainUnlockAt( $sub ) );
		if ( '' === $dom ) {
			$res['message'] = 'Dominio non valido.';
		} elseif ( $dom === $sub['domain'] ) {
			$res['message'] = 'È già il dominio di questa licenza.';
		} elseif ( null !== $res['unlock_at'] ) {
			$res['message'] = 'Hai già cambiato dominio da poco: potrai farlo di nuovo dal ' . date_it( $res['unlock_at'] ) . '.';
		} else {
			try {
				$this->billing->assertDomainFree( $dom, $customerId, $subId, ! in_array( $sub['provider'], array( 'free', 'trial' ), true ) );
				$res['ok'] = true;
			} catch ( UserError $e ) {
				$res['message'] = $e->getMessage();
			}
		}
		return $res;
	}

	/** Cambia il dominio (con tutti i controlli di Billing::changeDomain, blocco compreso). @throws UserError */
	public function domainSet( string $customerId, string $subId, string $input ): void {
		$this->own( $customerId, $subId );
		$this->billing->changeDomain( $subId, $customerId, $input );
	}

	/**
	 * Collegamento di scarico firmato di 5 minuti. Con $version vuota: l'ultima disponibile con questa licenza.
	 * @throws UserError
	 */
	public function downloadLink( string $customerId, string $subId, string $product, string $version = '' ): string {
		$sub = $this->own( $customerId, $subId );
		if ( '' === $version && isset( Releases::PRODUCTS[ $product ] ) ) {
			foreach ( Releases::all( $this->db, $product ) as $r ) {
				if ( null !== DownloadLink::allowed( $this->db, $sub, $product, $r['version'] ) ) {
					$version = $r['version'];
					break;
				}
			}
		}
		return DownloadLink::create( $this->db, $sub, $product, $version );
	}

	/** @throws UserError */
	private function own( string $customerId, string $subId ): array {
		$sub = $this->billing->subscription( $subId );
		if ( ! $sub || $sub['customer_id'] !== $customerId || in_array( $sub['status'], array( 'pending', 'merged' ), true ) ) {
			throw new UserError( 'Licenza non trovata.' );
		}
		return $sub;
	}
}
