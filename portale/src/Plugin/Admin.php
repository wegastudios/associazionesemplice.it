<?php
namespace Portale\Plugin;

use Portale\Audit;
use Portale\Billing;
use Portale\Config;
use Portale\Connections;
use Portale\Db;
use Portale\Domain;
use Portale\Emails;
use Portale\Legal;
use Portale\Releases;
use Portale\Secrets;
use Portale\Settings;
use Portale\Staff;
use Portale\SupportSso;
use Portale\Tracking;
use Portale\UserError;
use Portale\View;
use Portale\WegaClient;
use Portale\WegaException;
use Portale\WegaSync;

/**
 * Pannello di gestione: stesso accesso dei clienti (link per email), ma solo per gli indirizzi elencati in `admins` di config.php.
 * Ogni richiesta ricontrolla l'indirizzo; ogni modifica finisce nel registro.
 */
final class Admin {

	/** Radice dell'amministrazione del plugin (confine logico: tabelle, ruoli, menu e rotte propri). Prima era «/admin». */
	const BASE = '/wordpress/admin';

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;
	/** @var string */
	private $email;
	/** @var string admin o support */
	private $role = '';

	/** Amministratore = indirizzo in `admins` di config.php oppure con ruolo admin nello staff (il ruolo «solo supporto» non basta). */
	public static function isAdminEmail( string $email, ?Db $db = null ): bool {
		return Staff::ADMIN === Staff::role( $db, $email );
	}

	/** Ruolo «vendita» dell'accesso staff unico: catalogo, prezzi, promo, licenze e clienti; non impostazioni, versioni né utenti. */
	const SALES = 'sales';

	/** @var bool sessione dell'accesso staff unico (utenti e ruoli decisi dal SaaS), non l'accesso clienti */
	private $sso = false;

	/** @param array|null $staffSession sessione dell'accesso staff unico ({@see StaffSso::current}); null = accesso di sempre */
	public function __construct( Db $db, Billing $billing, string $customerId, ?array $staffSession = null ) {
		$this->db      = $db;
		$this->billing = $billing;
		if ( null !== $staffSession ) {
			$this->sso   = true;
			$this->email = (string) $staffSession['email'];
			$map         = array( 'admin' => Staff::ADMIN, 'vendita' => self::SALES, 'supporto' => Staff::SUPPORT );
			$this->role  = $map[ (string) ( $staffSession['level'] ?? '' ) ] ?? '';
		} else {
			$row         = $db->one( 'SELECT email FROM customers WHERE id = ?', array( $customerId ) );
			$this->email = $row ? (string) $row['email'] : '';
			$this->role  = Staff::role( $db, $this->email ) ?? '';
		}
		if ( '' === $this->role ) {
			$_SESSION['admin'] = false;
			$_SESSION['staff'] = '';
			http_response_code( 403 );
			throw new UserError( 'Questa area è riservata alla gestione.' );
		}
		$_SESSION['admin'] = Staff::ADMIN === $this->role;
		$_SESSION['staff'] = $this->role;
	}

	/**
	 * «Solo supporto»: vede e segue licenze e clienti (rinnovo, dominio, disdetta, sospensione, note) ma non tocca prezzi, piani,
	 * versioni, pagamenti, email, pagine, impostazioni, registro, Wega e staff, e non emette né elimina licenze o clienti.
	 */
	private function supportMayDo( bool $get, string $a, string $b, string $c ): bool {
		if ( 'licenze' === $a ) {
			return 'nuova' !== $b && 'elimina' !== $c;
		}
		if ( 'sicurezza' === $a ) {
			return true; // ognuno gestisce la propria verifica in due passaggi
		}
		return 'clienti' === $a && $get;
	}

	/** «Vendita»: catalogo, prezzi, promo, licenze e clienti. Il resto (impostazioni, versioni, pagamenti, email, pagine, registro, utenti) no. */
	private function salesMayDo( string $a ): bool {
		return in_array( $a, array( '', 'licenze', 'clienti', 'prezzi', 'catalogo', 'codici-promo' ), true );
	}

	public function handle( string $method, string $path ): void {
		$get = 'GET' === $method;
		$p   = explode( '/', trim( substr( $path, strlen( self::BASE ) ), '/' ) ); // dopo «/wordpress/admin»
		$a   = $p[0] ?? '';
		$b   = $p[1] ?? '';
		$c   = $p[2] ?? '';

		// Verifica in due passaggi: opzionale, solo per chi l'ha attivata per sé. Senza codice recente si vede solo la pagina «verifica».
		if ( ! $this->sso && Mfa::enabled( $this->db, $this->email ) && ! Mfa::sessionOk( $this->email ) ) {
			if ( 'verifica' !== $a ) {
				header( 'Location: ' . self::BASE . '/verifica', true, 303 );
				return;
			}
			$this->mfaVerify( $get );
			return;
		}

		if ( $this->sso && ! $get && 'esci' === $a ) {
			StaffSso::close();
			header( 'Location: ' . StaffSso::loginUrl(), true, 303 );
			return;
		}
		if ( $this->sso && ! $get && 'vai' === $a ) {
			$this->goTo( $b );
			return;
		}
		if ( $this->sso && in_array( $a, array( 'sicurezza', 'verifica' ), true ) ) {
			$this->notFound(); // con l'accesso unico il secondo fattore (facoltativo) si gestisce nel SaaS
			return;
		}
		if ( self::SALES === $this->role && ! $this->salesMayDo( $a ) ) {
			http_response_code( 403 );
			throw new UserError( 'Con il ruolo «vendita» questa funzione non è disponibile.' );
		}
		if ( Staff::SUPPORT === $this->role ) {
			if ( '' === $a ) {
				header( 'Location: /admin/licenze', true, 303 );
				return;
			}
			if ( ! $this->supportMayDo( $get, $a, $b, $c ) ) {
				http_response_code( 403 );
				throw new UserError( 'Con il ruolo «solo supporto» questa funzione non è disponibile.' );
			}
		}

		if ( '' === $a && $get ) {
			$this->dashboard();
		} elseif ( 'licenze' === $a ) {
			if ( $get && '' === $b ) {
				$this->licenses();
			} elseif ( 'nuova' === $b ) {
				$get ? $this->show( 'new_license', 'licenze', array( 'plans' => Config::get( 'plans', array() ) ) ) : $this->issue();
			} elseif ( $get && '' !== $b ) {
				$this->licenseDetail( $b );
			} elseif ( ! $get && '' !== $c ) {
				$this->licenseAction( $b, $c );
			} else {
				$this->notFound();
			}
		} elseif ( 'wega' === $a ) {
			$this->wegaSync( $get );
		} elseif ( 'utenti' === $a && $this->sso ) {
			$this->users( $get, $b );
		} elseif ( 'staff' === $a && $this->sso ) {
			header( 'Location: ' . self::BASE . '/utenti', true, 303 );
		} elseif ( 'staff' === $a ) {
			$this->staff( $get, $b, $c );
		} elseif ( 'clienti' === $a && $get ) {
			$this->customers();
		} elseif ( 'clienti' === $a && ! $get && '' !== $b && 'elimina' === $c ) {
			$this->deleteCustomer( $b );
		} elseif ( 'prezzi' === $a ) {
			if ( $get ) {
				$this->show( 'prices', 'prezzi', array( 'plans' => Config::get( 'plans', array() ) ) );
			} elseif ( 'promo' === $b ) {
				Settings::savePromo( $this->db, $_POST );
				$this->done( 'promo', 'Promozione salvata.', '/wordpress/admin/prezzi' );
			} elseif ( '' !== $b ) {
				$this->savePlan( $b );
			} else {
				$this->notFound();
			}
		} elseif ( 'email' === $a ) {
			if ( $get && '' === $b ) {
				$this->emails();
			} elseif ( '' !== $b ) {
				$get ? $this->emailEditor( $b ) : $this->emailAction( $b );
			} else {
				$this->notFound();
			}
		} elseif ( 'impostazioni' === $a ) {
			if ( $get ) {
				$this->show( 'settings', 'impostazioni', array( 'g' => Settings::general(), 'limits' => Settings::LIMITS, 'plans' => Config::get( 'plans', array() ), 'cfg' => $this->configStatus() ) );
			} else {
				Settings::saveGeneral( $this->db, $_POST );
				$this->done( 'settings', 'Impostazioni salvate.', '/wordpress/admin/impostazioni' );
			}
		} elseif ( 'pagamenti' === $a ) {
			if ( $get ) {
				$this->show( 'payments', 'pagamenti', array( 's' => Settings::secretsStatus( $this->db ), 'base' => rtrim( (string) Config::get( 'base_url' ), '/' ) ) );
			} elseif ( 'prova' === $b ) {
				list( $ok, $msg ) = Connections::test( $c );
				$this->done( 'test_' . $c, ( $ok ? '✓ ' : '✗ ' ) . $msg, '/wordpress/admin/pagamenti' );
			} else {
				$changed = Settings::saveSecrets( $this->db, $_POST );
				$this->done( 'secrets', $changed ? 'Chiavi salvate (' . implode( ', ', $changed ) . ').' : 'Impostazioni di pagamento salvate.', '/wordpress/admin/pagamenti' );
			}
		} elseif ( 'cookie' === $a ) {
			if ( $get ) {
				$this->show( 'cookies', 'cookie', array( 't' => Tracking::conf(), 'cats' => Tracking::CATEGORIES ) );
			} else {
				Settings::saveTracking( $this->db, $_POST );
				$this->done( 'tracking', 'Cookie e script salvati.', '/wordpress/admin/cookie' );
			}
		} elseif ( 'legale' === $a ) {
			if ( $get ) {
				$this->show( 'legal_edit', 'legale', array( 'vars' => Legal::vars(), 'legal' => (array) Config::get( 'legal', array() ) ) );
			} else {
				Settings::saveLegal( $this->db, $_POST );
				$this->done( 'legal', 'Pagine legali salvate.', '/wordpress/admin/legale' );
			}
		} elseif ( 'versioni' === $a ) {
			if ( $get ) {
				$rows = array();
				foreach ( array_keys( Releases::PRODUCTS ) as $p ) {
					$rows[ $p ] = Releases::all( $this->db, $p );
				}
				$this->show( 'releases', 'versioni', array( 'rows' => $rows, 'keep' => Settings::limit( 'releases_keep' ) ) );
			} elseif ( 'elimina' === $b ) {
				Releases::remove( $this->db, (string) ( $_POST['product'] ?? '' ), (string) ( $_POST['version'] ?? '' ), $this->email );
				$this->done( 'release_remove', 'Versione eliminata.', '/wordpress/admin/versioni' );
			} else {
				$f = $_FILES['package'] ?? null;
				if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
					throw new UserError( 'Caricamento non riuscito: scegli lo zip del plugin (controlla anche il limite di upload_max_filesize del server).' );
				}
				$row = Releases::add( $this->db, (string) ( $_POST['product'] ?? '' ), (string) $f['tmp_name'], (string) ( $_POST['note'] ?? '' ), $this->email );
				$this->done( 'release_add', 'Versione ' . $row['version'] . ' pubblicata.', '/wordpress/admin/versioni' );
			}
		} elseif ( 'catalogo' === $a ) {
			$this->catalog( $get, $b );
		} elseif ( 'codici-promo' === $a ) {
			$this->promos( $get, $b );
		} elseif ( 'sicurezza' === $a ) {
			$this->security( $get, $b );
		} elseif ( 'verifica' === $a ) {
			header( 'Location: ' . self::BASE, true, 303 ); // già verificato (o non serve)
		} elseif ( 'registro' === $a && $get ) {
			$this->show( 'audit', 'registro', array( 'rows' => Audit::recent( $this->db, 200 ) ) );
		} else {
			$this->notFound();
		}
	}

	// --- Pagine ----------------------------------------------------------------------------------------------------------

	private function dashboard(): void {
		$now   = Db::now();
		$count = function ( string $where, array $args = array() ) {
			$r = $this->db->one( 'SELECT COUNT(*) AS n FROM subscriptions WHERE license_key_enc IS NOT NULL AND ' . $where, $args );
			return (int) ( $r['n'] ?? 0 );
		};
		$paid = $this->db->all( "SELECT * FROM subscriptions WHERE license_key_enc IS NOT NULL AND provider IN ('stripe','paypal') AND status IN ('active','past_due')" );
		$mrr  = 0;
		$rec  = 0;
		foreach ( $paid as $s ) {
			if ( ! $this->billing->isRecurring( $s ) || $s['cancel_at_period_end'] ) {
				continue;
			}
			$price = Config::get( 'plans.' . $s['plan_code'] . '.prices.' . $s['period'] . '.cents' );
			if ( $price ) {
				$mrr += 'year' === $s['period'] ? $price / 12 : $price;
				++$rec;
			}
		}
		$byPlan = $this->db->all( "SELECT plan_code, provider, COUNT(*) AS n FROM subscriptions WHERE license_key_enc IS NOT NULL AND status = 'active' AND expires_at > ? GROUP BY plan_code, provider", array( $now ) );
		$this->show( 'dashboard', 'cruscotto', array(
			'active'   => $count( "status = 'active' AND expires_at > ?", array( $now ) ),
			'paying'   => $rec,
			'mrr'      => (int) round( $mrr ),
			'trials'   => $count( "provider = 'trial' AND status = 'active' AND expires_at > ?", array( $now ) ),
			'expiring' => $count( "status = 'active' AND provider <> 'free' AND expires_at > ? AND expires_at <= ?", array( $now, gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ) ) ),
			'past_due' => $count( "status = 'past_due'" ),
			'new30'    => (int) ( $this->db->one( 'SELECT COUNT(*) AS n FROM customers WHERE created_at >= ?', array( gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ) )['n'] ?? 0 ),
			'by_plan'  => $byPlan,
			'recent'   => $this->db->all( "SELECT s.*, c.email FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.license_key_enc IS NOT NULL ORDER BY s.created_at DESC LIMIT 8" ),
			'cfg'      => $this->configStatus(),
		) );
	}

	/** Cosa manca per vendere davvero (mostrato nel cruscotto e nelle impostazioni). */
	private function configStatus(): array {
		return array(
			'wega'   => '' !== (string) Config::get( 'wega.token', '' ),
			'stripe' => '' !== (string) Config::get( 'stripe.secret_key', '' ) && '' !== (string) Config::get( 'stripe.webhook_secret', '' ),
			'paypal' => '' !== (string) Config::get( 'paypal.client_id', '' ) && '' !== (string) Config::get( 'paypal.webhook_id', '' ),
			'key'    => '' !== (string) Config::get( 'app_key', '' ),
		);
	}

	private function licenses(): void {
		$q      = trim( (string) ( $_GET['q'] ?? '' ) );
		$status = (string) ( $_GET['stato'] ?? '' );
		$page   = max( 1, (int) ( $_GET['p'] ?? 1 ) );
		$where  = array( '1=1' );
		$args   = array();
		if ( '' !== $q ) {
			$where[] = '(c.email LIKE ? OR s.domain LIKE ?)';
			$like    = '%' . str_replace( array( '%', '_' ), array( '', '' ), $q ) . '%';
			array_push( $args, $like, $like );
		}
		if ( in_array( $status, array( 'active', 'pending', 'past_due', 'canceled', 'merged' ), true ) ) {
			$where[] = 's.status = ?';
			$args[]  = $status;
		}
		$per  = 40;
		$rows = $this->db->all(
			'SELECT s.*, c.email FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.created_at DESC LIMIT ' . ( $per + 1 ) . ' OFFSET ' . ( ( $page - 1 ) * $per ),
			$args
		);
		$more = count( $rows ) > $per;
		$this->show( 'licenses', 'licenze', array( 'rows' => array_slice( $rows, 0, $per ), 'q' => $q, 'status' => $status, 'page' => $page, 'more' => $more, 'billing' => $this->billing ) );
	}

	private function licenseDetail( string $id ): void {
		$sub = $this->sub( $id );
		$key = '';
		try {
			$key = ! empty( $sub['license_key_enc'] ) ? Secrets::open( $sub['license_key_enc'] ) : '';
		} catch ( \Throwable $e ) {
			$key = '';
		}
		$remote = null;
		if ( isset( $_GET['verifica'] ) && '' !== $key ) {
			$wega = new WegaClient( (string) Config::get( 'wega.base_url' ), (string) Config::get( 'wega.token' ) );
			try {
				$remote = array( 'info' => $wega->show( (string) $sub['license_id'] ), 'check' => $wega->validate( $key, $sub['domain'] ) );
			} catch ( WegaException $e ) {
				$remote = array( 'error' => $e->errorCode . ' — ' . $e->getMessage() );
			}
		}
		$cust = $this->db->one( 'SELECT * FROM customers WHERE id = ?', array( $sub['customer_id'] ) );
		$this->show( 'license', 'licenze', array( 'sub' => $sub, 'key' => $key, 'customer' => $cust, 'remote' => $remote, 'billing' => $this->billing ) );
	}

	private function licenseAction( string $id, string $action ): void {
		$sub = $this->sub( $id );
		$to  = '/wordpress/admin/licenze/' . $id;
		switch ( $action ) {
			case 'rinnova':
				if ( ! $this->billing->renew( $id, null, null, ! empty( $_POST['notify'] ) ) ) {
					throw new UserError( 'Questa licenza non si può rinnovare (manca la chiave o è annullata).' );
				}
				$this->done( 'renew', 'Licenza ' . $sub['domain'] . ' rinnovata di un periodo.', $to, $id );
				break;
			case 'dominio':
				$this->billing->changeDomain( $id, $sub['customer_id'], (string) ( $_POST['domain'] ?? '' ), true );
				$this->done( 'domain', $sub['domain'] . ' → ' . Domain::normalize( (string) ( $_POST['domain'] ?? '' ) ), $to, $id );
				break;
			case 'disdici':
				$this->billing->cancel( $id, $sub['customer_id'] );
				$this->done( 'cancel', 'Rinnovo disdetto per ' . $sub['domain'] . '.', $to, $id );
				break;
			case 'stato':
				$st = (string) ( $_POST['status'] ?? '' );
				if ( ! in_array( $st, array( 'active', 'canceled' ), true ) ) {
					throw new UserError( 'Stato non valido.' );
				}
				$warn = $this->billing->mirror( $sub, 'canceled' === $st ? 'suspend' : 'restore' );
				$this->billing->setStatus( $id, $st );
				$this->done( 'status', 'Stato di ' . $sub['domain'] . ': ' . $st . ( $warn ? ' — ' . $warn : '' ), $to, $id );
				break;
			case 'elimina':
				$deleted = $this->billing->deleteSubscription( $id );
				$this->done( 'sub_delete', 'Licenza di ' . $sub['domain'] . ' eliminata dal portale.' . ( ! empty( $deleted['_warning'] ) ? ' ' . $deleted['_warning'] : '' ), '/wordpress/admin/licenze', $id );
				break;
			case 'chiave':
				if ( ! $this->billing->recoverKey( $id ) ) {
					throw new UserError( 'Non risulta nessuna chiave da recuperare per questa licenza.' );
				}
				$this->done( 'key_recover', 'Chiave di ' . $sub['domain'] . ' recuperata da Wega.', $to, $id );
				break;
			case 'nota':
				$this->db->run( 'UPDATE subscriptions SET note = ? WHERE id = ?', array( mb_substr( trim( (string) ( $_POST['note'] ?? '' ) ), 0, 255 ), $id ) );
				$this->done( 'note', 'Nota aggiornata.', $to, $id );
				break;
			default:
				$this->notFound();
		}
	}

	private function issue(): void {
		$days = max( 1, min( 3650, (int) ( $_POST['days'] ?? 365 ) ) );
		$sub  = $this->billing->issueManual(
			(string) ( $_POST['email'] ?? '' ), (string) ( $_POST['domain'] ?? '' ), (string) ( $_POST['plan'] ?? '' ), $days,
			(string) ( $_POST['note'] ?? '' ), ! empty( $_POST['notify'] )
		);
		$this->done( 'issue', $sub['domain'] . ' · ' . $sub['plan_code'] . ' · ' . $days . ' giorni', '/wordpress/admin/licenze/' . $sub['id'], $sub['id'] );
	}

	private function customers(): void {
		$rows = $this->db->all(
			'SELECT c.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.customer_id = c.id) AS licenses, (SELECT GROUP_CONCAT(s.domain) FROM subscriptions s WHERE s.customer_id = c.id) AS domains
			 FROM customers c ORDER BY c.created_at DESC LIMIT 300'
		);
		$this->show( 'customers', 'clienti', array( 'rows' => $rows ) );
	}

	/** Confronto con il server Wega (elenco delle licenze del portale) e importazione di quelle mancanti. */
	private function wegaSync( bool $get ): void {
		try {
			$rows = WegaSync::fetchAll( $this->billing->wega() );
		} catch ( WegaException $e ) {
			if ( in_array( $e->httpStatus, array( 401, 403 ), true ) ) {
				$msg = 'Il token del portale non ha il permesso licenses:list: abilitalo dal pannello Wega (Portali).';
			} elseif ( in_array( $e->httpStatus, array( 404, 405 ), true ) ) {
				$msg = 'Il server Wega non offre ancora l\'elenco delle licenze (GET /licenses): vedi docs/WEGA-richiesta-elenco-licenze.md.';
			} else {
				$msg = 'Il server Wega ha risposto: ' . $e->errorCode . ' (HTTP ' . $e->httpStatus . ') ' . $e->getMessage();
			}
			$this->show( 'wega', 'wega', array( 'error' => $msg, 'rows' => array(), 'orphans' => array() ) );
			return;
		}
		$cmp = WegaSync::compare( $this->db, $rows );
		if ( $get ) {
			$this->show( 'wega', 'wega', array( 'error' => '', 'rows' => $cmp, 'orphans' => WegaSync::orphans( $this->db, $rows ) ) );
			return;
		}
		$only = (string) ( $_POST['id'] ?? '' );
		$done = 0;
		$skip = 0;
		foreach ( $cmp as $r ) {
			if ( 'missing' !== $r['_state'] || ( '' !== $only && (string) $r['id'] !== $only ) ) {
				continue;
			}
			try {
				WegaSync::import( $this->db, $this->billing, $r );
				++$done;
			} catch ( UserError $e ) {
				++$skip;
			}
		}
		$this->done( 'wega_import', $done . ' licenze importate da Wega' . ( $skip ? ', ' . $skip . ' saltate' : '' ) . '.', '/wordpress/admin/wega' );
	}

	/** Staff: elenco, aggiunta/cambio permessi (POST /admin/staff) e rimozione (POST /admin/staff/rimuovi). */
	/** «Vai a»: il SaaS dà il token per l'altra area (solo se ci si ha un ruolo) e il browser lo manda in POST all'indirizzo fisso dell'area. */
	private function goTo( string $area ): void {
		$s     = StaffSso::current();
		$token = $s && isset( StaffSso::DEST[ $area ] ) ? StaffSso::tokenFor( $s, $area ) : null;
		if ( null === $token ) {
			throw new UserError( 'Non riesco ad aprire quell\'area ora: riprova tra poco.' );
		}
		Audit::log( $this->db, $this->email, 'staff_vai', 'Passaggio a: ' . $area );
		View::render( 'wordpress-admin/staff_vai', array( 'action' => StaffSso::DEST[ $area ], 'token' => $token, 'name' => StaffSso::AREA_NAMES[ $area ], 'title' => 'Apertura di ' . StaffSso::AREA_NAMES[ $area ] ) );
	}

	/** Pagina «Utenti» con l'accesso staff unico: l'elenco e i ruoli stanno nel SaaS, che decide chi può cosa; qui si fanno solo le richieste. */
	private function users( bool $get, string $b ): void {
		if ( Staff::ADMIN !== $this->role ) {
			http_response_code( 403 );
			throw new UserError( 'La gestione degli utenti è riservata agli amministratori.' );
		}
		$errors = array(
			'non_autorizzato'        => 'Non hai il permesso per questa operazione.',
			'livello_troppo_alto'    => 'Non puoi assegnare un livello più alto del tuo.',
			'ultimo_admin'           => 'Deve restare almeno un amministratore.',
			'super_non_modificabile' => 'Questo account non si può modificare.',
			'riconferma_richiesta'   => 'Per questa operazione riconferma prima la tua password nel SaaS e poi riprova.',
			'dati_non_validi'        => 'Dati non validi.',
		);
		if ( $get && '' === $b ) {
			list( $code, $out ) = StaffSso::people( 'list', $this->email );
			$this->show( 'utenti', 'staff', array( 'people' => 200 === $code ? (array) ( $out['people'] ?? array() ) : array(), 'error' => 200 === $code ? '' : 'L\'elenco non è disponibile ora: riprova tra poco.' ) );
			return;
		}
		$ops = array( 'invita' => 'invite', 'assegna' => 'grant', 'revoca' => 'revoke', 'disattiva' => 'deactivate' );
		if ( $get || ! isset( $ops[ $b ] ) ) {
			$this->notFound();
			return;
		}
		$data  = array( 'email' => strtolower( trim( (string) ( $_POST['email'] ?? '' ) ) ) );
		$level = (string) ( $_POST['level'] ?? '' );
		if ( in_array( $b, array( 'invita', 'assegna' ), true ) ) {
			$data['level'] = $level;
		}
		list( $code, $out ) = StaffSso::people( $ops[ $b ], $this->email, $data );
		if ( 200 !== $code ) {
			throw new UserError( $errors[ (string) ( $out['error'] ?? '' ) ] ?? 'Il SaaS non ha eseguito l\'operazione: riprova tra poco.' );
		}
		$this->done( 'utenti_' . $ops[ $b ], 'Fatto: ' . $b . ' per ' . $data['email'] . '.', self::BASE . '/utenti' );
	}

	private function staff( bool $get, string $b, string $c ): void {
		if ( $get && '' === $b ) {
			$this->show( 'staff', 'staff', array( 'rows' => Staff::all( $this->db ), 'labels' => Staff::LABELS, 'saas_labels' => Staff::SAAS_LABELS, 'sso' => SupportSso::enabled(), 'support_url' => SupportSso::supportUrl() ) );
		} elseif ( ! $get && '' === $b ) {
			$email = Staff::set( $this->db, $this->billing, (string) ( $_POST['email'] ?? '' ), (string) ( $_POST['role'] ?? '' ), (string) ( $_POST['saas'] ?? '' ) );
			$this->done( 'staff_set', $email . ' · plugin: ' . ( Staff::LABELS[ (string) $_POST['role'] ] ?? 'nessun accesso' ) . ' · SaaS: ' . ( Staff::SAAS_LABELS[ (string) $_POST['saas'] ] ?? 'nessun accesso' ), '/wordpress/admin/staff' );
		} elseif ( ! $get && 'rimuovi' === $b ) {
			$email = strtolower( trim( (string) ( $_POST['email'] ?? '' ) ) );
			if ( $email === strtolower( $this->email ) ) {
				throw new UserError( 'Non puoi togliere te stesso dallo staff.' );
			}
			Staff::remove( $this->db, $email );
			$this->done( 'staff_remove', $email . ' tolto dallo staff.', '/wordpress/admin/staff' );
		} else {
			$this->notFound();
		}
	}

	private function deleteCustomer( string $id ): void {
		$c = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $id ) );
		if ( ! $c ) {
			$this->notFound();
			return;
		}
		if ( 0 === strcasecmp( $c['email'], $this->email ) ) {
			throw new UserError( 'Non puoi eliminare il tuo stesso account mentre sei dentro.' );
		}
		$n = $this->billing->deleteCustomer( $id );
		$this->done( 'customer_delete', 'Cliente ' . $c['email'] . ' eliminato (' . $n . ' licenze).' . ( $this->billing->warning ? ' ' . $this->billing->warning : '' ), '/wordpress/admin/clienti' );
	}

	private function savePlan( string $code ): void {
		Settings::savePlan( $this->db, $code, $_POST );
		$this->done( 'plan', 'Piano «' . $code . '» salvato.', '/wordpress/admin/prezzi#' . $code );
	}

	private function emails(): void {
		$rows = array();
		foreach ( Emails::events() as $k => $def ) {
			$rows[ $k ] = $def + array( 'tpl' => Emails::template( $this->db, $k ), 'always' => in_array( $k, Emails::ALWAYS_ON, true ) );
		}
		$this->show( 'emails', 'email', array( 'rows' => $rows ) );
	}

	private function emailEditor( string $event ): void {
		$def = Emails::events()[ $event ] ?? null;
		if ( ! $def ) {
			$this->notFound();
			return;
		}
		$this->show( 'email_edit', 'email', array( 'event' => $event, 'def' => $def, 'tpl' => Emails::template( $this->db, $event ), 'always' => in_array( $event, Emails::ALWAYS_ON, true ), 'sample' => $this->sampleVars() ) );
	}

	private function emailAction( string $event ): void {
		if ( ! isset( Emails::events()[ $event ] ) ) {
			$this->notFound();
			return;
		}
		$to = '/wordpress/admin/email/' . $event;
		if ( isset( $_POST['reset'] ) ) {
			Emails::reset( $this->db, $event );
			$this->done( 'email_reset', 'Modello «' . $event . '» ripristinato.', $to );
			return;
		}
		$subject = (string) ( $_POST['subject'] ?? '' );
		$body    = (string) ( $_POST['body'] ?? '' );
		$enabled = in_array( $event, Emails::ALWAYS_ON, true ) || ! empty( $_POST['enabled'] );
		Emails::save( $this->db, $event, $subject, $body, $enabled );
		$unknown = array_merge( Emails::unknownVars( $event, $subject ), Emails::unknownVars( $event, $body ) );
		$msg     = 'Modello salvato.';
		if ( isset( $_POST['test'] ) ) {
			Emails::sendTest( $this->db, $event, $this->email, $this->sampleVars() );
			$msg .= ' Email di prova inviata a ' . $this->email . '.';
		}
		if ( $unknown ) {
			$msg .= ' Attenzione: segnaposto sconosciuti per questo evento: {' . implode( '}, {', array_unique( $unknown ) ) . '}.';
		}
		$this->done( 'email', $msg, $to, $event, $enabled ? '' : ' (spenta)' );
	}

	private function sampleVars(): array {
		return array(
			'link' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/entra/ESEMPIO', 'dominio' => 'miaassociazione.it', 'vecchio_dominio' => 'vecchio-sito.it',
			'piano' => 'Pro Fiscale', 'nuovo_piano' => 'Pro Contabile', 'scadenza' => date( 'd/m/Y', time() + 30 * 86400 ), 'email' => $this->email,
			'account_url' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/account',
		);
	}

	// --- Catalogo del plugin: bozza e «Pubblica» -------------------------------------------------------------------------------

	private function catalog( bool $get, string $b ): void {
		$base  = ( new ConfigCatalog() )->document();
		$codes = array_column( $base['plans'], 'code' );
		if ( $get ) {
			$draft = CatalogStore::draft( $this->db );
			$this->show( 'catalog', 'catalogo', array(
				'draft'     => $draft,
				'has_draft' => CatalogStore::hasDraft( $this->db ),
				'plans'     => $base['plans'],
				'errors'    => CatalogStore::validate( $draft, $codes ),
				'published' => CatalogStore::published( $this->db ),
				'history'   => CatalogStore::history( $this->db ),
				'levels'    => ConfigCatalog::SUPPORT_LEVELS,
			) );
			return;
		}
		$to    = self::BASE . '/catalogo';
		$draft = CatalogStore::draft( $this->db );
		if ( 'caratteristiche' === $b ) {
			list( $list, $errs ) = CatalogStore::parseFeatures( (string) ( $_POST['features'] ?? '' ) );
			if ( $errs ) {
				throw new UserError( implode( ' ', $errs ) );
			}
			$old = array();
			foreach ( $draft['features'] as $f ) {
				$old[ $f['id'] ] = $f;
			}
			foreach ( $list as $i => $f ) {
				$list[ $i ]['detail'] = $old[ $f['id'] ]['detail'] ?? '';
			}
			$draft['features'] = $list;
			CatalogStore::saveDraft( $this->db, $draft, $this->email );
			$this->done( 'catalog_features', 'Caratteristiche salvate nella bozza (' . count( $list ) . ').', $to );
		} elseif ( 'piani' === $b ) {
			foreach ( $codes as $code ) {
				$in = (array) ( $_POST['plan'][ $code ] ?? array() );
				$draft['plans'][ $code ] = array(
					'audience'      => (string) ( $in['audience'] ?? '' ),
					'visible'       => ! empty( $in['visible'] ),
					'order'         => (int) ( $in['order'] ?? 0 ),
					'support_level' => (string) ( $in['support_level'] ?? '' ),
					'sites'         => $in['sites'] ?? null,
				);
				foreach ( $draft['features'] as $f ) {
					$draft['matrix'][ $code ][ $f['id'] ] = array(
						'state' => (string) ( $_POST['cell'][ $code ][ $f['id'] ] ?? 'excluded' ),
						'note'  => (string) ( $_POST['note'][ $code ][ $f['id'] ] ?? '' ),
					);
				}
			}
			CatalogStore::saveDraft( $this->db, $draft, $this->email );
			$this->done( 'catalog_plans', 'Piani salvati nella bozza.', $to );
		} elseif ( 'importa-confronto' === $b ) {
			$imp               = ConfrontoImport::parse( (string) file_get_contents( dirname( __DIR__, 2 ) . '/content/confronto.md' ), $codes );
			$draft['features'] = $imp['features'];
			$draft['matrix']   = $imp['matrix'];
			CatalogStore::saveDraft( $this->db, $draft, $this->email );
			$this->done( 'catalog_import', 'Importate ' . count( $imp['features'] ) . ' caratteristiche dal confronto nella bozza.', $to );
		} elseif ( 'pubblica' === $b ) {
			$rev = CatalogStore::publish( $this->db, $base, $this->email );
			$this->done( 'catalog_publish', 'Catalogo pubblicato: revisione ' . $rev . '.', $to );
		} elseif ( 'scarta' === $b ) {
			CatalogStore::discard( $this->db );
			$this->done( 'catalog_discard', 'Bozza scartata.', $to );
		} else {
			$this->notFound();
		}
	}

	// --- Codici promo (sempre di un solo prodotto) -----------------------------------------------------------------------------

	private function promos( bool $get, string $b ): void {
		$codes = array_column( ( new ConfigCatalog() )->document()['plans'], 'code' );
		$to    = self::BASE . '/codici-promo';
		if ( $get ) {
			$this->show( 'promos', 'codici', array( 'rows' => Promos::all( $this->db ), 'plans' => ( new ConfigCatalog() )->document()['plans'], 'edit' => Promos::get( $this->db, (string) ( $_GET['codice'] ?? '' ) ) ) );
		} elseif ( '' === $b ) {
			$in          = $_POST;
			$in['value'] = 'amount' === ( $in['kind'] ?? '' ) ? (int) round( (float) str_replace( ',', '.', (string) ( $in['value'] ?? '0' ) ) * 100 ) : (int) ( $in['value'] ?? 0 );
			$row         = Promos::validate( $in, $codes );
			Promos::save( $this->db, $row );
			$this->done( 'promo_save', 'Codice promo ' . $row['code'] . ' salvato.', $to, $row['code'] );
		} elseif ( 'stato' === $b ) {
			$on = ! empty( $_POST['active'] );
			Promos::setActive( $this->db, (string) ( $_POST['code'] ?? '' ), $on );
			$this->done( 'promo_state', 'Codice promo ' . strtoupper( (string) ( $_POST['code'] ?? '' ) ) . ( $on ? ' riattivato.' : ' sospeso.' ), $to );
		} else {
			$this->notFound();
		}
	}

	// --- Verifica in due passaggi (opzionale, per persona) ----------------------------------------------------------------------

	/** Pagina «inserisci il codice» per chi ha la verifica attiva. */
	private function mfaVerify( bool $get ): void {
		if ( ! $get ) {
			if ( Mfa::check( $this->db, $this->email, (string) ( $_POST['code'] ?? '' ) ) ) {
				Mfa::markSession( $this->email );
				Audit::log( $this->db, $this->email, 'mfa_ok', 'Verifica in due passaggi superata.' );
				header( 'Location: ' . self::BASE, true, 303 );
				return;
			}
			Audit::log( $this->db, $this->email, 'mfa_fail', 'Codice errato.' );
			$_SESSION['flash'] = 'Codice non corretto.';
			header( 'Location: ' . self::BASE . '/verifica', true, 303 );
			return;
		}
		View::render( 'wordpress-admin/mfa_verify', array( 'flash' => $this->flash(), 'admin_email' => $this->email ) );
	}

	/** «Sicurezza»: ognuno attiva o spegne la verifica per sé. */
	private function security( bool $get, string $b ): void {
		$on = Mfa::enabled( $this->db, $this->email );
		if ( $get ) {
			$secret = '';
			if ( ! $on ) {
				if ( empty( $_SESSION['plugin_mfa_pending'] ) ) {
					$_SESSION['plugin_mfa_pending'] = Totp::generate();
				}
				$secret = (string) $_SESSION['plugin_mfa_pending'];
			}
			$this->show( 'security', 'sicurezza', array( 'on' => $on, 'secret' => $secret, 'uri' => '' !== $secret ? Totp::uri( $secret, $this->email ) : '' ) );
		} elseif ( 'attiva' === $b && ! $on ) {
			Mfa::enable( $this->db, $this->email, (string) ( $_SESSION['plugin_mfa_pending'] ?? '' ), (string) ( $_POST['code'] ?? '' ) );
			unset( $_SESSION['plugin_mfa_pending'] );
			Mfa::markSession( $this->email );
			$this->done( 'mfa_on', 'Verifica in due passaggi attivata per ' . $this->email . '.', self::BASE . '/sicurezza' );
		} elseif ( 'spegni' === $b && $on ) {
			Mfa::disable( $this->db, $this->email, (string) ( $_POST['code'] ?? '' ) );
			unset( $_SESSION['plugin_mfa'] );
			$this->done( 'mfa_off', 'Verifica in due passaggi spenta per ' . $this->email . '.', self::BASE . '/sicurezza' );
		} else {
			$this->notFound();
		}
	}

	// --- Utilità ------------------------------------------------------------------------------------------------------------

	private function sub( string $id ): array {
		$s = $this->billing->subscription( $id );
		if ( ! $s ) {
			http_response_code( 404 );
			throw new UserError( 'Licenza non trovata.' );
		}
		return $s;
	}

	private function show( string $view, string $nav, array $vars = array() ): void {
		View::render( 'wordpress-admin/' . $view, $vars + array( 'nav' => $nav, 'flash' => $this->flash(), 'admin_email' => $this->email, 'role' => $this->role, 'has_saas' => '' !== Staff::saas( $this->db, $this->email ) ) );
	}

	private function flash(): string {
		$f = (string) ( $_SESSION['flash'] ?? '' );
		unset( $_SESSION['flash'] );
		return $f;
	}

	/** Registra l'azione, mostra il messaggio alla pagina successiva e rimanda lì. */
	private function done( string $action, string $message, string $to, string $detail = '', string $extra = '' ): void {
		Audit::log( $this->db, $this->email, $action, trim( $message . ' ' . ( '' !== $detail && $detail !== $message ? '[' . $detail . ']' : '' ) . $extra ) );
		$_SESSION['flash'] = $message;
		header( 'Location: ' . $to, true, 303 );
	}

	private function notFound(): void {
		http_response_code( 404 );
		View::render( 'message', array( 'title' => 'Pagina non trovata', 'text' => 'Questa pagina non esiste.' ) );
	}
}
