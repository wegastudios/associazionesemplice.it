<?php
namespace Portale;

/**
 * Gestione della piattaforma SaaS (/saas): area autonoma, con menu e pagine proprie, separata dalla gestione del plugin (/admin).
 * Stesso accesso dello staff, ma i permessi sono propri (Staff::saas): «solo supporto» legge, l'amministratore anche scrive.
 * Ogni richiesta ricontrolla l'indirizzo; ogni modifica finisce nel registro.
 */
final class Platform {

	/** @var Db */
	private $db;
	/** @var string */
	private $email;
	/** @var string admin o support */
	private $level;
	/** @var bool vero se la stessa persona può anche entrare nella gestione del plugin */
	private $plugin;

	public function __construct( Db $db, string $customerId ) {
		$this->db     = $db;
		$row          = $db->one( 'SELECT email FROM customers WHERE id = ?', array( $customerId ) );
		$this->email  = $row ? (string) $row['email'] : '';
		$this->level  = Staff::saas( $db, $this->email );
		$this->plugin = null !== Staff::role( $db, $this->email );
		if ( '' === $this->level ) {
			http_response_code( 403 );
			throw new UserError( 'Questa area è riservata alla gestione della piattaforma.' );
		}
	}

	public function handle( string $method, string $path ): void {
		header( 'Cache-Control: no-store' );
		$get = 'GET' === $method;
		$p   = explode( '/', trim( substr( $path, 5 ), '/' ) ); // dopo «/saas»
		$a   = $p[0] ?? '';
		$b   = $p[1] ?? '';
		$base = array( 'saas_ready' => SaasClient::enabled() );

		if ( $get && '' === $a ) {
			$data = array( 'orgs' => array(), 'total' => 0, 'error' => '' );
			$f    = array( 'q' => (string) ( $_GET['q'] ?? '' ), 'status' => (string) ( $_GET['status'] ?? '' ), 'page' => (int) ( $_GET['page'] ?? 1 ) );
			try {
				$r    = SaasClient::orgs( $this->email, $f );
				$data = array( 'orgs' => (array) ( $r['orgs'] ?? array() ), 'total' => (int) ( $r['total'] ?? 0 ), 'error' => '' );
			} catch ( UserError $e ) {
				$data['error'] = $e->getMessage();
			}
			$this->show( 'orgs', 'associazioni', $base + $data + array( 'f' => $f ) );
		} elseif ( $get && 'utenti' !== $a ) {
			$org = array();
			$ops = array();
			$err = '';
			try {
				$org = SaasClient::org( $this->email, $a );
				$ops = (array) ( SaasClient::operators( $this->email, $a )['operators'] ?? array() );
			} catch ( UserError $e ) {
				$err = $e->getMessage();
			}
			$this->show( 'org', 'associazioni', $base + array( 'org' => $org, 'ops' => $ops, 'org_id' => $a, 'error' => $err, 'can_write' => Staff::ADMIN === $this->level ) );
		} elseif ( ! $get && 'utenti' === $a && '' !== $b ) {
			if ( Staff::ADMIN !== $this->level ) {
				http_response_code( 403 );
				throw new UserError( 'Solo gli amministratori possono disattivare o riattivare gli utenti.' );
			}
			$disabled = '1' === (string) ( $_POST['disabled'] ?? '' );
			$orgId    = (string) ( $_POST['org'] ?? '' );
			SaasClient::setUserDisabled( $this->email, $b, $disabled, (string) ( $_POST['reason'] ?? '' ) );
			$msg = $disabled ? 'Utente disattivato.' : 'Utente riattivato.';
			Audit::log( $this->db, $this->email, 'saas_user_state', $msg . ' [' . $b . ']' );
			$_SESSION['flash'] = $msg;
			header( 'Location: ' . ( preg_match( '/^[A-Za-z0-9-]{8,64}$/', $orgId ) ? '/saas/' . $orgId : '/saas' ), true, 303 );
		} else {
			http_response_code( 404 );
			View::render( 'message', array( 'title' => 'Pagina non trovata', 'text' => 'Questa pagina non esiste.' ) );
		}
	}

	private function show( string $view, string $nav, array $vars = array() ): void {
		$flash = (string) ( $_SESSION['flash'] ?? '' );
		unset( $_SESSION['flash'] );
		View::render( 'saas/' . $view, $vars + array( 'nav' => $nav, 'flash' => $flash, 'admin_email' => $this->email, 'level' => $this->level, 'has_plugin' => $this->plugin ) );
	}
}
