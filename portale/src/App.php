<?php
namespace Portale;

/** Router e pagine del portale (nessun framework). Il pannello di gestione è in {@see Admin}. */
final class App {

	/** @var Db */
	private $db;
	/** @var Billing */
	private $billing;
	/** @var Auth */
	private $auth;

	public function __construct( Db $db, Billing $billing, Auth $auth ) {
		$this->db      = $db;
		$this->billing = $billing;
		$this->auth    = $auth;
	}

	public function run( string $method, string $path ): void {
		$path = '/' . trim( $path, '/' );
		try {
			if ( 'POST' === $method && 0 === strpos( $path, '/webhook/' ) ) {
				$this->webhook( $path );
				return;
			}
			if ( 'POST' === $method && '/api/saas/v1/catalog-changed' === $path ) {
				// Avviso firmato del SaaS (contratto §4.2): senza sessione né CSRF, vale la firma. Il catalogo si scarica a risposta consegnata.
				$ok = Catalog::notify( $this->db, (string) substr( (string) file_get_contents( 'php://input' ), 0, 4096 ) );
				http_response_code( $ok ? 200 : 401 );
				header( 'Content-Type: application/json; charset=utf-8' );
				echo $ok ? '{"ok":true}' : '{"error":"invalid_token"}';
				if ( $ok ) {
					$this->after[] = function () {
						Catalog::refresh( $this->db );
					};
					$this->finish();
				}
				return;
			}
			if ( 0 === strpos( $path, '/api/license/' ) || 0 === strpos( $path, '/api/plugin/' ) || '/.well-known/jwks.json' === $path ) {
				$this->licenseApi( $method, $path );
				return;
			}
			if ( 'GET' === $method && Plugin\CatalogApi::PATH === $path ) {
				Plugin\CatalogApi::serve( new Plugin\DbCatalog( $this->db ) );
				return;
			}
			if ( 'GET' === $method && Plugin\DownloadLink::PATH === $path ) {
				// Scarico con collegamento firmato di 5 minuti legato alla licenza (nessun accesso: il diritto è nel token e si ricontrolla qui).
				list( $code, $file, $prod, $ver ) = Plugin\DownloadLink::resolve( $this->db, (string) ( $_GET['t'] ?? '' ) );
				if ( 200 === $code ) {
					Releases::stream( $file, $prod, $ver );
					return;
				}
				http_response_code( $code );
				View::render( 'message', array( 'title' => 'Scarico non disponibile', 'text' => $file ) );
				return;
			}
			// La POST dell'accesso staff unico arriva dal SaaS (altro sito): non ha il nostro CSRF, vale il token firmato.
			if ( 'POST' === $method && Plugin\Admin::BASE . '/sso' !== $path && ! $this->csrfOk() ) {
				throw new UserError( 'Sessione scaduta: ricarica la pagina e riprova.' );
			}
			$this->route( $method, $path );
			$this->finish();
		} catch ( UserError $e ) {
			http_response_code( 422 );
			View::render( 'message', array( 'title' => 'Non è andata a buon fine', 'text' => $e->getMessage() ) );
		} catch ( WegaException $e ) {
			error_log( '[portale] wega ' . $e->errorCode . ' ' . $e->httpStatus . ' ' . $e->getMessage() );
			http_response_code( 502 );
			// Alla gestione si mostra la causa vera; ai clienti un messaggio generico.
			$detail = ( ! empty( $_SESSION['customer'] ) && Staff::ADMIN === Staff::role( $this->db, $this->customerEmail( (string) $_SESSION['customer'] ) ) ) ?' Risposta del server licenze: ' . $e->errorCode . ' (HTTP ' . $e->httpStatus . ') ' . $e->getMessage() : '';
			View::render( 'message', array( 'title' => 'Servizio momentaneamente non disponibile', 'text' => 'Non è stato possibile completare l\'operazione con il server licenze: riprova tra qualche minuto.' . $detail ) );
		} catch ( \Throwable $e ) {
			error_log( '[portale] ' . $e->getMessage() );
			http_response_code( 500 );
			View::render( 'message', array( 'title' => 'Qualcosa è andato storto', 'text' => 'Riprova tra qualche minuto. Se il problema continua scrivici.' ) );
		}
	}

	private function route( string $method, string $path ): void {
		$get = 'GET' === $method;
		if ( '/admin' === $path || 0 === strpos( $path, '/admin/' ) ) {
			// Vecchio indirizzo dell'amministrazione del plugin: ora vive sotto /wordpress/admin (301; 308 per i POST, che conservano il metodo).
			$qs = (string) ( $_SERVER['QUERY_STRING'] ?? '' );
			header( 'Location: ' . Plugin\Admin::BASE . substr( $path, 6 ) . ( '' !== $qs ? '?' . $qs : '' ), true, $get ? 301 : 308 );
		} elseif ( ! $get && Plugin\Admin::BASE . '/sso' === $path ) {
			// Accesso staff unico: la POST del selettore porta il token del SaaS (spento finché `staff_sso.enabled` non è true).
			if ( ! Plugin\StaffSso::enabled() ) {
				http_response_code( 404 );
			} elseif ( Plugin\StaffSso::receive( $this->db, (string) ( $_POST['token'] ?? '' ) ) ) {
				header( 'Location: ' . Plugin\Admin::BASE, true, 303 );
			} else {
				header( 'Location: ' . Plugin\StaffSso::loginUrl(), true, 303 );
			}
		} elseif ( Identity::enabled() && Plugin\Admin::BASE . '/accedi' === $path ) {
			// Accesso unico: l'accesso è /accedi del portale.
			header( 'Location: /accedi', true, 303 );
		} elseif ( Plugin\Admin::BASE === $path || 0 === strpos( $path, Plugin\Admin::BASE . '/' ) ) {
			// Accesso staff unico (se acceso): la sessione viene dal SaaS e si riconferma ogni 5 minuti; altrimenti l'accesso di sempre.
			$staff = null;
			if ( Plugin\StaffSso::enabled() ) {
				$staff = Plugin\StaffSso::current();
				if ( $staff && Plugin\StaffSso::due( $staff ) ) {
					$staff = Plugin\StaffSso::recheck( $staff );
				}
				if ( ! $staff ) {
					header( 'Location: ' . Plugin\StaffSso::loginUrl(), true, 303 );
					return;
				}
			}
			( new Plugin\Admin( $this->db, $this->billing, $staff ? '' : $this->customerId(), $staff ) )->handle( $method, $path );
		} elseif ( ( $legacy = Sections::legacy( $method, $path, (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ) ) ) {
			// Vecchi indirizzi di /saas: accesso dal gestionale, vecchia gestione delle associazioni, /saas/admin (vedi {@see Sections}).
			header( 'Location: ' . $legacy[1], true, $legacy[0] );
		} elseif ( $get && Sections::view( $path ) ) {
			// Sezioni di contenuti /saas e /wordpress (l'amministrazione di ciascun prodotto vive sotto /admin).
			$view = (string) Sections::view( $path );
			View::render( $view, array( 'title' => 'wordpress_changelog' === $view ? 'Novità del plugin' : ( 'saas_section' === $view ? 'Il gestionale online' : 'Il plugin per WordPress' ), 'releases' => 'wordpress_changelog' === $view ? Sections::releases( $this->db ) : array(), 'catalog' => 'saas_section' === $view ? $this->saasCatalog() : null ) );
		} elseif ( $get && '/' === $path ) {
			View::render( 'home', array( 'plans' => Config::get( 'plans', array() ), 'trial' => Config::get( 'trial_enabled', true ) ? Settings::limit( 'trial_days' ) : 0 ) );
		} elseif ( $get && '/api/piani' === $path ) {
			// Nomi dei piani per il sito di supporto: sola lettura, senza prezzi né dati riservati.
			header( 'Content-Type: application/json; charset=utf-8' );
			View::security();
			header( 'X-Robots-Tag: noindex' );
			header( 'Cache-Control: public, max-age=300' );
			echo json_encode( Site::publicPlans(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( $get && '/robots.txt' === $path ) {
			View::headers( 'text/plain; charset=utf-8' );
			echo Config::get( 'indexable', false ) ? "User-agent: *\nAllow: /\nDisallow: /account\nDisallow: /admin\nDisallow: /wordpress/admin\nDisallow: /saas/admin\nDisallow: /api\nDisallow: /scarica\nDisallow: /entra\n" : "User-agent: *\nDisallow: /\n";
		} elseif ( $get && isset( Legal::PAGES[ ltrim( $path, '/' ) ] ) ) {
			$slug = ltrim( $path, '/' );
			View::render( 'legal', array( 'title' => Legal::PAGES[ $slug ][0], 'html' => Legal::html( Legal::body( $slug ) ) ) );
		} elseif ( $get && '/plugin' === $path ) {
			View::render( 'legal', array( 'title' => 'Il plugin Associazione Semplice', 'html' => Legal::html( (string) file_get_contents( __DIR__ . '/../content/plugin.md' ) ) ) );
		} elseif ( $get && '/piani' === $path ) {
			header( 'Location: /prezzi', true, 301 );
		} elseif ( $get && '/prezzi' === $path ) {
			View::render( 'pricing', array( 'catalog' => $this->saasCatalog(), 'title' => 'Prezzi', 'plans' => Config::get( 'plans', array() ), 'trial' => Config::get( 'trial_enabled', true ) ? Settings::limit( 'trial_days' ) : 0 ) );
		} elseif ( $get && '/funzioni' === $path ) {
			View::render( 'features', array( 'title' => 'Funzioni' ) );
		} elseif ( $get && '/domande' === $path ) {
			View::render( 'faq', array( 'title' => 'Domande frequenti' ) );
		} elseif ( $get && '/prova' === $path ) {
			View::render( 'demo', array( 'title' => 'Prova la demo', 'plans' => Config::get( 'plans', array() ), 'accounts' => Demo::accounts(), 'links' => Demo::links(), 'matrix' => Demo::roles(), 'notes' => Demo::roleNotes(), 'base' => Demo::url() ) );
		} elseif ( $get && '/confronto' === $path ) {
			View::render( 'confronto', array( 'catalog' => $this->saasCatalog(), 'title' => 'Confronto tra le versioni' ) );
		} elseif ( $get && '/confronto-demo' === $path ) {
			// Stessa tabella di /confronto, senza pagina intorno: la legge la demo e la mostra nella sua home.
			$fragment = true;
			View::headers( 'text/html; charset=utf-8' );
			require __DIR__ . '/../views/confronto.php';
		} elseif ( '/saas/acquista' === $path ) {
			$this->saasBuy( $get );
		} elseif ( $get && '/free' === $path ) {
			View::render( 'free', array( 'title' => 'Licenza gratuita' ) );
		} elseif ( ! $get && '/free' === $path ) {
			$this->formLimit();
			list( $cust, $sub ) = $this->billing->startFree( (string) $this->in( 'email' ), (string) $this->in( 'domain' ), (string) $this->in( 'name' ) );
			if ( ! $this->auth->canIssue( $cust['id'] ) ) {
				throw new UserError( 'Hai richiesto troppi link: riprova tra un po\'.' );
			}
			$this->sendLink( $cust, $this->auth->issue( $cust['id'], $sub['id'] ), 'free_confirm', array( 'dominio' => $sub['domain'] ) );
			View::render( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Ti abbiamo scritto un link per confermare l\'email e ricevere la chiave.' ) );
		} elseif ( $get && '/acquista' === $path ) {
			$plan   = Site::code( (string) ( $_GET['plan'] ?? '' ) );
			$period = (string) ( $_GET['period'] ?? '' );
			$price  = Config::price( $plan, $period );
			if ( ! $price ) {
				throw new UserError( 'Piano non disponibile.' );
			}
			View::render( 'buy', array(
				'plan' => $plan, 'period' => $period, 'price' => $price, 'label' => Config::get( 'plans.' . $plan . '.label' ),
				'methods' => $this->billing->methods( $price ), 'days' => Settings::limit( 'year' === $period ? 'duration_year' : 'duration_month' ),
			) );
		} elseif ( ! $get && '/acquista' === $path ) {
			$this->formLimit();
			list( $provider, $mode ) = array_pad( explode( ':', (string) $this->in( 'method' ), 2 ), 2, '' );
			$url = $this->billing->startPaid( (string) $this->in( 'email' ), (string) $this->in( 'domain' ), (string) $this->in( 'plan' ), (string) $this->in( 'period' ), $provider, $mode, (string) $this->in( 'name' ) );
			header( 'Location: ' . $url, true, 303 );
		} elseif ( $get && '/paypal/ritorno' === $path ) {
			$this->paypalReturn( (string) ( $_GET['token'] ?? '' ) );
		} elseif ( $get && '/grazie' === $path && 'saas' === ( $_SESSION['grazie']['kind'] ?? '' ) ) {
			View::render( 'thanks_saas', array( 'title' => 'Grazie!', 'org' => (string) $_SESSION['grazie']['associazione'], 'email' => (string) $_SESSION['grazie']['email'] ) );
		} elseif ( $get && '/grazie' === $path ) {
			View::render( 'message', array( 'title' => 'Grazie!', 'text' => 'Appena il pagamento è confermato ti scriviamo un link per entrare nell\'area riservata e trovare la chiave di licenza del plugin o l\'indirizzo del tuo gestionale.' ) );
		} elseif ( $get && '/accedi' === $path ) {
			View::render( 'login', array( 'title' => 'Accedi', 'for_support' => '/supporto/accedi' === ( $_SESSION['after_login'] ?? '' ), 'identity' => Identity::enabled() ) );
		} elseif ( '/twofactor' === $path ) {
			$this->twoFactor( $get );
		} elseif ( Identity::enabled() && '/accedi/password-dimenticata' === $path ) {
			if ( $get ) {
				View::render( 'identity_reset_request', array( 'title' => 'Password dimenticata' ) );
			} else {
				$this->formLimit();
				$email = strtolower( trim( (string) $this->in( 'email' ) ) );
				Identity::resetRequest( $email, $this->clientIp() );
				// Chi si è registrato nel portale dopo l'importazione nel SaaS non è ancora nell'elenco unico: riceve il link di accesso del portale (da cui sceglie la password).
				$cust = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
				if ( $cust && $this->auth->canIssue( $cust['id'] ) ) {
					$this->sendLink( $cust, $this->auth->issue( $cust['id'] ), 'login_link' );
				}
				View::render( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Se l\'indirizzo è registrato, ti abbiamo scritto un link per scegliere una nuova password o per entrare. Vale poco: usalo subito.' ) );
			}
		} elseif ( Identity::enabled() && 0 === strpos( $path, '/accedi/reimposta/' ) ) {
			$code = substr( $path, 18 );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $code ) ) {
				throw new UserError( 'Il link non è valido o è scaduto: richiedine uno nuovo.' );
			}
			if ( $get ) {
				View::render( 'identity_reset', array( 'title' => 'Nuova password', 'code' => $code, 'min' => (int) Settings::limit( 'password_min' ) ) );
			} else {
				$this->formLimit();
				$pw = (string) $this->in( 'password' );
				if ( $pw !== (string) $this->in( 'confirm' ) || mb_strlen( $pw ) < (int) Settings::limit( 'password_min' ) ) {
					throw new UserError( 'Le due password devono coincidere ed essere lunghe almeno ' . (int) Settings::limit( 'password_min' ) . ' caratteri.' );
				}
				if ( 'ok' !== Identity::resetComplete( $code, $pw ) ) {
					throw new UserError( 'Il link non è valido o è scaduto: richiedine uno nuovo.' );
				}
				View::render( 'message', array( 'title' => 'Password cambiata', 'text' => 'Fatto: ora puoi entrare con la nuova password. Le altre sessioni aperte sono state chiuse.' ) );
			}
		} elseif ( Identity::enabled() && ! $get && '/account/area' === $path ) {
			$this->identityArea( (string) $this->in( 'to' ) );
		} elseif ( Identity::enabled() && ( '/account/profilo' === $path || 0 === strpos( $path, '/account/profilo/' ) ) ) {
			$this->profile( $get, (string) substr( $path, 17 ) );
		} elseif ( Identity::enabled() && 0 === strpos( $path, '/accedi/conferma-email/' ) ) {
			// Il link arriva per email: la GET mostra solo il pulsante (gli antispam non consumano il link), la POST conferma.
			$code = substr( $path, 23 );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $code ) ) {
				throw new UserError( 'Il link non è valido o è scaduto: richiedine uno nuovo dal tuo profilo.' );
			}
			if ( $get ) {
				View::render( 'identity_email_confirm', array( 'title' => 'Conferma il nuovo indirizzo', 'code' => $code ) );
			} elseif ( Identity::emailConfirm( $code ) ) {
				View::render( 'message', array( 'title' => 'Indirizzo cambiato', 'text' => 'Fatto: da ora entri con il nuovo indirizzo email.' ) );
			} else {
				throw new UserError( 'Il link non è valido o è scaduto: richiedine uno nuovo dal tuo profilo.' );
			}
		} elseif ( $get && '/account/esci' === $path ) {
			// Link da altre aree (supporto, gestionale): una GET non chiude niente, chiede conferma (il pulsante porta il nostro token).
			View::render( 'logout', array( 'title' => 'Esci' ) );
		} elseif ( '/account/esci' === $path ) {
			$this->logout();
		} elseif ( ! $get && '/accedi' === $path ) {
			$email = strtolower( trim( (string) $this->in( 'email' ) ) );
			if ( 'link' !== (string) $this->in( 'mode' ) && '' !== (string) $this->in( 'password' ) ) {
				// Accesso unico (spento di default): prima l'elenco delle persone del SaaS; se non conosce l'email si prosegue con i clienti.
				if ( Identity::enabled() && $this->identityLogin( $email, (string) $this->in( 'password' ) ) ) {
					return;
				}
				$this->startSession( $this->auth->loginWithPassword( $email, (string) $this->in( 'password' ), $this->clientIp() ), false );
				return;
			}
			$this->formLimit();
			$cust = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
			// Stessa risposta se l'email non esiste: non si rivela chi è cliente.
			if ( $cust && $this->auth->canIssue( $cust['id'] ) ) {
				$this->sendLink( $cust, $this->auth->issue( $cust['id'] ), 'login_link' );
			}
			View::render( 'message', array( 'title' => 'Controlla la posta', 'text' => 'Se l\'indirizzo è registrato, ti abbiamo mandato un link per entrare. Dal link puoi anche scegliere una nuova password.' ) );
		} elseif ( $get && 0 === strpos( $path, '/entra/' ) ) {
			// Clic di una persona (il browser lo dichiara con Sec-Fetch-User): si entra subito. Visite automatiche degli antispam,
			// che non lo dichiarano, vedono la pagina col pulsante e non consumano il link.
			if ( '?1' === ($_SERVER['HTTP_SEC_FETCH_USER'] ?? '') && 'navigate' === ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') ) {
				$this->enter( substr( $path, 7 ) );
			} else {
				View::render( 'enter', array( 'title' => 'Conferma l\'accesso', 'token' => substr( $path, 7 ) ) );
			}
		} elseif ( ! $get && '/entra' === $path ) {
			$this->enter( (string) $this->in( 'token' ) );
		} elseif ( $get && 0 === strpos( $path, '/scarica/' ) ) {
			$this->download( $path );
		} elseif ( $get && '/supporto/accedi' === $path ) {
			// Accesso unico al sito di supporto: serve aver fatto l'accesso qui; il ruolo (admin, solo supporto, cliente) si legge dall'email.
			header( 'Cache-Control: no-store' );
			// Persona dell'elenco unico (anche senza scheda cliente): l'email è quella della sessione unica.
			$idn = Identity::enabled() ? Identity::check() : null;
			if ( empty( $_SESSION['customer'] ) && ! $idn ) {
				// Non connesso: dopo l'accesso si torna qui (destinazione fissa, mai da parametri dell'indirizzo: niente redirect aperti).
				$_SESSION['after_login'] = '/supporto/accedi';
				header( 'Location: /accedi', true, 303 );
				return;
			}
			header( 'Location: ' . SupportSso::url( $this->db, ! empty( $_SESSION['customer'] ) ? $this->customerEmail( (string) $_SESSION['customer'] ) : $idn['email'] ), true, 303 );
		} elseif ( '/account/impostazioni' === $path ) {
			// Interruttori del portale per la titolare (e chi ha il ruolo admin): niente da scrivere in config.php.
			$idn = Identity::enabled() ? Identity::check() : null;
			if ( ! $idn && empty( $_SESSION['customer'] ) ) {
				header( 'Location: /accedi', true, 303 );
				return;
			}
			if ( ! ( $idn && 'admin' === ( $idn['areas']['wordpress'] ?? '' ) ) && empty( $_SESSION['admin'] ) ) {
				http_response_code( 403 );
				throw new UserError( 'Questa pagina è solo per chi amministra il portale.' );
			}
			$flash = '';
			if ( ! $get ) {
				Switches::save( $this->db, $_POST );
				Audit::log( $this->db, $idn ? (string) $idn['email'] : $this->customerEmail( (string) $_SESSION['customer'] ), 'switches', 'Interruttori: ' . json_encode( Switches::current() ) );
				$flash = 'Salvato: vale subito.';
			}
			View::render( 'switches', array( 'title' => 'Impostazioni del portale', 'all' => Switches::ALL, 'on' => Switches::current(), 'flash' => $flash ) );
		} elseif ( $get && '/account' === $path ) {
			$this->account();
		} elseif ( SaasAccount::manageEnabled() && 1 === preg_match( '#^/account/gestionale/([0-9a-f]{32})(?:/(dominio|dominio-togli|piano))?$#', $path, $mg ) ) {
			$this->saasManage( $get, $mg[1], $mg[2] ?? '' );
		} elseif ( $get && '/account/da-gestionale' === $path ) {
			// «Gestisci abbonamento» dal gestionale (contratto §4.3). Destinazione fissa; se qualcosa non torna si passa dall'accesso normale, senza dire perché.
			header( 'Cache-Control: no-store' );
			$cust = SaasAccount::ssoCustomer( $this->db, (string) ( $_GET['t'] ?? '' ) );
			if ( $cust ) {
				$this->startSession( $cust, false );
				header( 'Location: /account#gestionale', true, 303 );
			} else {
				header( 'Location: /accedi', true, 303 );
			}
		} elseif ( ! $get && '/account/gestionale-disdici' === $path ) {
			$o = SaasAccount::mine( $this->db, $this->customerId(), (string) $this->in( 'id' ) );
			if ( ! $o ) {
				throw new UserError( 'Abbonamento non trovato.' );
			}
			SaasAccount::cancel( $this->db, $o );
			$_SESSION['acc_flash'] = 'Rinnovo disdetto: il gestionale resta attivo fino alla scadenza già pagata.';
			header( 'Location: /account#gestionale', true, 303 );
		} elseif ( ! $get && '/account/gestionale-riattiva' === $path ) {
			$gid = (string) $this->in( 'id' );
			$row = null;
			foreach ( SaasAccount::forCustomer( $this->db, $this->customerId() ) as $g ) {
				if ( $g['id'] === $gid ) {
					$row = $g;
				}
			}
			if ( ! $row || ! SaasAccount::reactivation( $row, $this->db ) ) {
				throw new UserError( 'Questo gestionale non ha bisogno di essere riattivato.' );
			}
			$url = SaasCheckout::reactivate( $this->db, $this->billing, $row, (int) $this->in( 'seen_total' ) );
			if ( null === $url ) {
				$_SESSION['acc_flash'] = 'Fatto: il pagamento è andato a buon fine. Il gestionale torna attivo tra pochi istanti: ricarica la pagina tra poco.';
				header( 'Location: /account#gestionale', true, 303 );
			} else {
				header( 'Location: ' . $url, true, 303 );
			}
		} elseif ( ! $get && '/account/gestionale-fatturazione' === $path ) {
			$o = SaasAccount::mine( $this->db, $this->customerId(), (string) $this->in( 'id' ) );
			if ( ! $o || 'stripe' !== $o['provider'] || empty( $o['provider_customer'] ) ) {
				throw new UserError( 'Portale di fatturazione non disponibile per questo abbonamento.' );
			}
			header( 'Location: ' . Stripe::portalUrl( (string) $o['provider_customer'] ), true, 303 );
		} elseif ( ! $get && '/account/nome' === $path ) {
			$this->billing->setName( $this->customerId(), (string) $this->in( 'name' ) );
			$_SESSION['acc_flash'] = 'Nome salvato: comparirà anche nelle richieste di supporto.';
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/password' === $path ) {
			$this->changePassword();
		} elseif ( ! $get && '/account/dominio' === $path ) {
			$this->billing->changeDomain( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'domain' ) );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/annulla' === $path ) {
			$this->billing->cancel( (string) $this->in( 'id' ), $this->customerId() );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/prova' === $path ) {
			$this->billing->startTrial( $this->customerId(), (string) $this->in( 'id' ) );
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/rinnova' === $path ) {
			$url = $this->billing->startRenewal( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'provider' ) );
			header( 'Location: ' . $url, true, 303 );
		} elseif ( ! $get && '/account/piano' === $path ) {
			$url = $this->billing->planChange()->startUpgrade( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'to' ) );
			header( 'Location: ' . $url, true, 303 );
		} elseif ( ! $get && '/account/piano-programma' === $path ) {
			$this->billing->planChange()->scheduleDowngrade( (string) $this->in( 'id' ), $this->customerId(), (string) $this->in( 'to' ) );
			$_SESSION['acc_flash'] = 'Cambio piano programmato: la licenza passa al piano scelto dal prossimo rinnovo.';
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/piano-annulla' === $path ) {
			$this->billing->planChange()->cancelScheduled( (string) $this->in( 'id' ), $this->customerId() );
			$_SESSION['acc_flash'] = 'Cambio piano annullato: la licenza resta com\'è.';
			header( 'Location: /account', true, 303 );
		} elseif ( ! $get && '/account/fatturazione' === $path ) {
			$sub = $this->billing->subscription( (string) $this->in( 'id' ) );
			if ( ! $sub || $sub['customer_id'] !== $this->customerId() || empty( $sub['provider_customer'] ) || 'stripe' !== $sub['provider'] ) {
				throw new UserError( 'Portale di fatturazione non disponibile per questo abbonamento.' );
			}
			header( 'Location: ' . Stripe::portalUrl( $sub['provider_customer'] ), true, 303 );
		} elseif ( ! $get && '/esci' === $path ) {
			$this->logout();
		} else {
			http_response_code( 404 );
			View::render( 'message', array( 'title' => 'Pagina non trovata', 'text' => 'Questa pagina non esiste.' ) );
		}
	}

	/** Ultima copia salvata del catalogo del SaaS (listino e confronto); se è vecchia di oltre 10 minuti si ricarica a risposta consegnata. */
	private function saasCatalog(): ?array {
		if ( Catalog::needsRefresh( $this->db ) ) {
			$this->after[] = function () {
				Catalog::refresh( $this->db );
			};
		}
		return Catalog::stored( $this->db );
	}

	private function enter( string $token ): void {
		$t = $this->auth->consume( $token );
		if ( ! $t ) {
			throw new UserError( 'Il link non è valido o è scaduto: richiedine uno nuovo.' );
		}
		if ( $t['subscription_id'] ) {
			$sub = $this->billing->subscription( $t['subscription_id'] );
			if ( $sub && 'free' === $sub['provider'] && $sub['customer_id'] === $t['customer_id'] ) {
				$this->billing->activate( $sub['id'] );
			}
		}
		$this->startSession( $this->db->one( 'SELECT * FROM customers WHERE id = ?', array( $t['customer_id'] ) ) ?: array( 'id' => $t['customer_id'], 'email' => '' ), true );
	}

	/** Chiude la sessione unica (il SaaS la revoca: le altre aree lo scoprono al controllo successivo) e quella del portale. */
	private function logout(): void {
		Identity::logout();
		$_SESSION = array();
		session_destroy();
		header( 'Location: /', true, 303 );
	}

	/**
	 * Accesso con l'elenco unico delle persone. @return bool true se la richiesta è gestita (sessione aperta, codice richiesto o errore mostrato);
	 * false se il SaaS non conosce l'email o non risponde: si prosegue con l'accesso dei clienti.
	 */
	private function identityLogin( string $email, string $password ): bool {
		$r = Identity::login( $email, $password, $this->clientIp() );
		if ( 'ok' === $r['status'] ) {
			$this->identityOpen( $r, $email );
			return true;
		}
		if ( 'code_required' === $r['status'] ) {
			session_regenerate_id( true );
			$_SESSION['identity_ticket'] = array( 'ticket' => $r['ticket'], 'email' => $email, 'at' => time() );
			header( 'Location: /twofactor', true, 303 );
			return true;
		}
		if ( 'limit' === $r['status'] ) {
			http_response_code( 429 );
			throw new UserError( 'Troppi tentativi: aspetta un minuto e riprova.' );
		}
		return false;
	}

	/** Dopo la login unica: sessione centrale, eventuale scheda cliente con la stessa email, apertura della gestione del plugin se c'è il ruolo. */
	private function identityOpen( array $ok, string $email ): void {
		$email = strtolower( trim( $email ) );
		session_regenerate_id( true );
		unset( $_SESSION['csrf'], $_SESSION['identity_ticket'] );
		Identity::open( $ok, $email );
		$cust = $this->db->one( 'SELECT * FROM customers WHERE email = ?', array( $email ) );
		if ( $cust ) {
			$_SESSION['customer'] = $cust['id'];
			$this->billing->touchCustomer( (string) $cust['id'] );
			$_SESSION['via_link'] = false;
		}
		if ( isset( $ok['areas']['wordpress'] ) && Plugin\StaffSso::enabled() ) {
			$token = Identity::tokenFor( $_SESSION['identity'], 'wordpress' );
			if ( null !== $token ) {
				Plugin\StaffSso::receive( $this->db, $token );
			}
		}
		$to = '/supporto/accedi' === ( $_SESSION['after_login'] ?? '' ) ? '/supporto/accedi' : '/account';
		unset( $_SESSION['after_login'] );
		header( 'Location: ' . $to, true, 303 );
	}

	/** Il codice del secondo livello dopo email e password. */
	private function twoFactor( bool $get ): void {
		$t = $_SESSION['identity_ticket'] ?? null;
		if ( ! Identity::enabled() || ! is_array( $t ) || time() - (int) $t['at'] > 300 ) {
			unset( $_SESSION['identity_ticket'] );
			header( 'Location: /accedi', true, 303 );
			return;
		}
		if ( $get ) {
			View::render( 'twofactor', array( 'title' => 'Codice di verifica', 'error' => '' ) );
			return;
		}
		$r = Identity::code( (string) $t['ticket'], (string) $this->in( 'code' ), $this->clientIp() );
		if ( 'ok' === $r['status'] ) {
			$this->identityOpen( $r, (string) $t['email'] );
			return;
		}
		http_response_code( 'limit' === $r['status'] ? 429 : 401 );
		View::render( 'twofactor', array( 'title' => 'Codice di verifica', 'error' => 'limit' === $r['status'] ? 'Troppi tentativi: aspetta un minuto.' : 'Il codice non è corretto o è scaduto.' ) );
	}

	/**
	 * «Gestisci» il gestionale: stato (numeri e stato dal SaaS), dominio personalizzato e controllo «ci sto dentro?» prima di cambiare piano
	 * (contratto v2 §3.4–3.6). Non cambia piano né incassa: dice solo se i dati rientrano nei limiti.
	 */
	private function saasManage( bool $get, string $id, string $act ): void {
		$o = SaasAccount::org( $this->db, $this->customerId(), $id );
		if ( ! $o ) {
			throw new UserError( 'Abbonamento non trovato.' );
		}
		if ( in_array( $act, array( 'dominio', 'dominio-togli' ), true ) && ! SaasAccount::domainEnabled() ) {
			throw new UserError( 'L\'indirizzo personalizzato non è ancora disponibile.' );
		}
		$org = (string) $o['org_id'];
		$ref = (string) $o['org_ref'];
		$flash = '';
		$error = '';
		$dns   = null;
		$check = null;
		$cat   = $this->saasCatalog();
		$plans = array();
		foreach ( $cat ? Catalog::plans( $cat['doc'] ) : array() as $p ) {
			if ( is_array( $p ) && is_string( $p['code'] ?? null ) && $p['code'] !== $o['plan'] ) {
				$plans[ $p['code'] ] = array( 'name' => (string) ( $p['name'] ?? $p['label'] ?? $p['code'] ), 'version' => (int) ( $p['plan_version'] ?? 0 ) );
			}
		}
		if ( ! $get ) {
			$this->formLimit();
			if ( 'dominio' === $act ) {
				$d = SaasAccount::cleanDomain( (string) $this->in( 'domain' ) );
				if ( null === $d ) {
					$error = SaasAccount::domainReason( 'invalid' );
				} else {
					list( $c, $r ) = SaasClient::domainCheck( $org, $ref, $d );
					if ( 200 !== $c || empty( $r['ok'] ) ) {
						$error = 200 === $c ? SaasAccount::domainReason( (string) ( $r['reason'] ?? '' ) ) : 'Il gestionale non risponde: riprova tra poco.';
					} else {
						list( $c, $r ) = SaasClient::domainSet( $org, $ref, $d );
						if ( 200 === $c ) {
							$dns   = $r;
							$flash = 'Indirizzo salvato: ora crea il collegamento nel pannello di chi gestisce il tuo dominio.';
						} else {
							$error = 409 === $c ? SaasAccount::domainReason( (string) ( $r['reason'] ?? '' ) ) : 'Il gestionale non risponde: riprova tra poco.';
						}
					}
				}
			} elseif ( 'dominio-togli' === $act ) {
				list( $c ) = SaasClient::domainSet( $org, $ref, null );
				if ( 200 === $c ) {
					$flash = 'Indirizzo personalizzato tolto: resta quello standard.';
				} else {
					$error = 'Il gestionale non risponde: riprova tra poco.';
				}
			} elseif ( 'piano' === $act ) {
				$to = Site::code( (string) $this->in( 'to_plan' ) );
				if ( ! isset( $plans[ $to ] ) ) {
					$error = 'Scegli un piano dall\'elenco.';
				} else {
					list( $c, $r ) = SaasClient::planChangeCheck( $org, $ref, $to, $plans[ $to ]['version'] );
					$check = 200 === $c ? array( 'to' => $plans[ $to ]['name'], 'allowed' => ! empty( $r['allowed'] ), 'blockers' => array_slice( array_map( 'strval', array_filter( (array) ( $r['blockers'] ?? array() ), 'is_scalar' ) ), 0, 8 ), 'effects' => array_slice( array_map( 'strval', array_filter( (array) ( $r['effects'] ?? array() ), 'is_scalar' ) ), 0, 8 ) ) : null;
					if ( null === $check ) {
						$error = 'Il gestionale non risponde: riprova tra poco.';
					}
				}
			}
		}
		list( $c, $st ) = SaasClient::orgStatus( $org, $ref );
		View::render( 'saas_manage', array( 'title' => 'Gestisci il gestionale', 'o' => $o, 'st' => 200 === $c ? $st : null, 'flash' => $flash, 'error' => $error, 'dns' => $dns, 'check' => $check, 'plans' => $plans ) );
	}

	/** Profilo e sicurezza: password, email, secondo livello, sessioni aperte. Tutto lo fa il SaaS; qui si mostrano esiti e moduli. */
	private function profile( bool $get, string $what ): void {
		$s = Identity::check();
		if ( ! $s ) {
			header( 'Location: /accedi', true, 303 );
			return;
		}
		$flash = '';
		$errors = array();
		$qr = null;
		if ( ! $get ) {
			$in  = function ( string $k, int $max = 1024 ): string { return mb_substr( (string) ( $_POST[ $k ] ?? '' ), 0, $max ); };
			$min = (int) Settings::limit( 'password_min' );
			$r   = null;
			if ( 'password' === $what ) {
				if ( $in( 'password' ) !== $in( 'confirm' ) || mb_strlen( $in( 'password' ) ) < $min ) {
					$errors[] = 'Le due password devono coincidere ed essere lunghe almeno ' . $min . ' caratteri.';
				} else {
					$r = Identity::act( 'password', array( 'current' => $in( 'current' ), 'new' => $in( 'password' ) ) );
					$flash = 'Password cambiata: le altre sessioni aperte sono state chiuse.';
				}
			} elseif ( 'email' === $what ) {
				$r = Identity::act( 'email', array( 'password' => $in( 'password' ), 'new_email' => strtolower( trim( $in( 'new_email', 254 ) ) ), 'link_base' => Identity::linkBase( 'conferma-email' ) ) );
				$flash = 'Ti abbiamo scritto al nuovo indirizzo: apri il link per confermare (vale un\'ora). Finché non lo apri resta valido quello di prima.';
			} elseif ( '2fa-avvia' === $what ) {
				$r = Identity::act( '2fa-start', array( 'password' => $in( 'password' ) ) );
				if ( 'ok' === $r['status'] && is_string( $r['body']['secret'] ?? null ) ) {
					$svg = (string) ( $r['body']['qr_svg'] ?? '' );
					$qr  = array( 'secret' => $r['body']['secret'], 'svg' => 0 === strpos( $svg, '<svg' ) && ! preg_match( '/<script|\son\w+\s*=|javascript:/i', $svg ) ? $svg : '' );
				}
			} elseif ( '2fa-conferma' === $what ) {
				$r = Identity::act( '2fa-confirm', array( 'code' => preg_replace( '/\s+/', '', $in( 'code', 12 ) ) ) );
				$flash = 'Fatto: da ora, dopo la password, ti chiediamo il codice dell\'app.';
			} elseif ( '2fa-disattiva' === $what ) {
				$r = Identity::act( '2fa-disable', array( 'password' => $in( 'password' ), 'code' => preg_replace( '/\s+/', '', $in( 'code', 12 ) ) ) );
				$flash = 'Secondo livello disattivato.';
			} elseif ( 'sessione-chiudi' === $what && 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $in( 'target', 40 ) ) ) {
				$r = Identity::act( 'session-revoke', array( 'target' => $in( 'target', 40 ) ) );
				$flash = 'Sessione chiusa.';
			}
			if ( $r ) {
				if ( 'closed' === $r['status'] ) {
					header( 'Location: /accedi', true, 303 );
					return;
				}
				if ( 'ok' !== $r['status'] ) {
					$flash = '';
					$errors = $r['messages'] ?: array( 'limit' === $r['status'] ? 'Troppi tentativi: aspetta un minuto e riprova.' : ( 'down' === $r['status'] ? 'Il servizio non risponde: riprova tra poco.' : 'Qualcosa non torna: controlla password e codice e riprova.' ) );
				} elseif ( null !== $qr ) {
					$flash = '';
				}
			}
		}
		$p = Identity::profile( $s );
		if ( ! $p ) {
			Identity::close();
			header( 'Location: /accedi', true, 303 );
			return;
		}
		View::render( 'profile', array( 'title' => 'Profilo e sicurezza', 'p' => $p, 'email' => $s['email'], 'flash' => $flash, 'errors' => $errors, 'qr' => $qr, 'min' => (int) Settings::limit( 'password_min' ) ) );
	}

	/** «Entra» in un'area dalla pagina Account: il SaaS dà il token (solo con il ruolo), il browser lo manda in POST all'indirizzo fisso dell'area. */
	private function identityArea( string $area ): void {
		$s = Identity::check( true );
		if ( ! $s ) {
			header( 'Location: /accedi', true, 303 );
			return;
		}
		$dest  = array( 'wordpress' => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ) . Plugin\Admin::BASE . '/sso' ) + Plugin\StaffSso::DEST;
		$token = isset( $dest[ $area ], $s['areas'][ $area ] ) ? Identity::tokenFor( $s, $area ) : null;
		if ( null === $token ) {
			throw new UserError( 'Non riesco ad aprire quell\'area ora: riprova tra poco.' );
		}
		View::render( 'area_vai', array( 'action' => $dest[ $area ], 'token' => $token, 'name' => array( 'wordpress' => 'Gestione del plugin', 'saas' => 'Gestionale', 'supporto' => 'Supporto' )[ $area ], 'title' => 'Apertura' ) );
	}

	/** Apre la sessione del cliente; entrando dal link per email la password si può impostare senza digitare la vecchia. */
	private function startSession( array $cust, bool $viaLink ): void {
		session_regenerate_id( true );
		unset( $_SESSION['csrf'] ); // nuovo token dopo l'accesso
		$_SESSION['customer'] = $cust['id'];
		$this->billing->touchCustomer( (string) $cust['id'] );
		$_SESSION['via_link'] = $viaLink;
		$role                = Staff::role( $this->db, (string) $cust['email'] ) ?? '';
		$_SESSION['staff']    = $role;
		$_SESSION['admin']    = Staff::ADMIN === $role;
		$to = '' !== $role ? Plugin\Admin::BASE : ( '' !== Staff::saas( $this->db, (string) $cust['email'] ) ? '/saas/admin' : '/account' );
		if ( '/supporto/accedi' === ( $_SESSION['after_login'] ?? '' ) ) {
			$to = '/supporto/accedi';
		}
		unset( $_SESSION['after_login'] );
		header( 'Location: ' . $to, true, 303 );
	}

	/** Limite orario per indirizzo IP sui moduli pubblici che mandano email o creano dati (link di accesso, licenza gratuita, acquisto). */
	private function formLimit(): void {
		if ( ! RateLimit::hit( $this->db, 'form', $this->clientIp(), Settings::limit( 'form_ip' ) ) ) {
			http_response_code( 429 );
			throw new UserError( 'Troppe richieste da questo indirizzo: riprova tra un\'ora.' );
		}
	}

	/** Modulo di accesso staff di /wordpress/admin: la password la controlla il SaaS (solo con l'accesso unico acceso). */
	private function staffLogin( bool $get ): void {
		if ( $get ) {
			View::render( 'wordpress-admin/staff_login', array( 'error' => '', 'title' => 'Accesso alla gestione' ) );
			return;
		}
		if ( ! $this->csrfOk() ) {
			http_response_code( 403 );
			throw new UserError( 'Sessione scaduta: ricarica la pagina e riprova.' );
		}
		$ip    = $this->clientIp();
		$email = strtolower( trim( (string) ( $_POST['email'] ?? '' ) ) );
		if ( ! RateLimit::hit( $this->db, 'staff_login_ip', $ip, 20 ) || ( '' !== $email && ! RateLimit::hit( $this->db, 'staff_login_email', $email, 8 ) ) ) {
			http_response_code( 429 );
			throw new UserError( 'Troppi tentativi: riprova tra un\'ora.' );
		}
		if ( Plugin\StaffSso::login( $this->db, $email, (string) ( $_POST['password'] ?? '' ), (string) ( $_POST['code'] ?? '' ), $ip ) ) {
			session_regenerate_id( true );
			header( 'Location: ' . Plugin\Admin::BASE, true, 303 );
			return;
		}
		http_response_code( 401 );
		View::render( 'wordpress-admin/staff_login', array( 'error' => 'Email, password o codice non corretti.', 'title' => 'Accesso alla gestione' ) );
	}

	private function clientIp(): string {
		return ClientIp::from( $_SERVER, (bool) Config::get( 'trust_forwarded_for', false ), (int) Config::get( 'forwarded_hops', 1 ) );
	}

	private function changePassword(): void {
		$cid = $this->customerId();
		if ( $this->auth->hasPassword( $cid ) && empty( $_SESSION['via_link'] ) && ! $this->auth->checkPassword( $cid, (string) $this->in( 'current' ) ) ) {
			throw new UserError( 'La password attuale non è corretta.' );
		}
		if ( (string) $this->in( 'password' ) !== (string) $this->in( 'confirm' ) ) {
			throw new UserError( 'Le due password non coincidono.' );
		}
		$this->auth->setPassword( $cid, (string) $this->in( 'password' ), $this->customerEmail( $cid ) );
		$_SESSION['acc_flash'] = 'Password salvata: da ora puoi entrare con email e password.';
		header( 'Location: /account', true, 303 );
	}

	/** Scarico di un pacchetto del plugin: solo con accesso eseguito, licenza in regola e entro il limite giornaliero. */
	private function download( string $path ): void {
		$cid   = $this->customerId();
		$parts = explode( '/', trim( substr( $path, 9 ), '/' ) );
		if ( 2 !== count( $parts ) || ! isset( Releases::PRODUCTS[ $parts[0] ] ) || ! Releases::validVersion( $parts[1] ) ) {
			throw new UserError( 'Questa versione non è disponibile.' );
		}
		// Il ruolo si rilegge dall'email a ogni richiesta: chi non è più amministratore non conserva i privilegi nella sessione già aperta.
		$file = Releases::authorize( $this->db, $cid, Staff::ADMIN === Staff::role( $this->db, $this->customerEmail( $cid ) ), $this->billing->forCustomer( $cid ), $parts[0], $parts[1] );
		Releases::stream( $file, $parts[0], $parts[1] );
	}

	private function account(): void {
		$idn = Identity::enabled() ? Identity::check() : null;
		if ( empty( $_SESSION['customer'] ) && Identity::enabled() ) {
			if ( ! $idn ) {
				header( 'Location: /accedi', true, 303 );
				return;
			}
			// Persona dell'elenco unico senza scheda cliente: solo il punto d'arrivo con le sue aree.
			View::render( 'account_staff', array( 'title' => 'Il tuo account', 'email' => $idn['email'], 'areas' => $idn['areas'] ) );
			return;
		}
		$cid  = $this->customerId();
		$subs = $this->billing->forCustomer( $cid );
		$trial = array();
		foreach ( $subs as $s ) {
			$trial[ $s['id'] ] = $this->billing->trialAvailable( $s );
		}
		$flash = (string) ( $_SESSION['acc_flash'] ?? '' );
		unset( $_SESSION['acc_flash'] );
		View::render( 'account', array(
			'name' => (string) ( $this->db->one( 'SELECT name FROM customers WHERE id = ?', array( $cid ) )['name'] ?? '' ), 'has_password' => $this->auth->hasPassword( $cid ), 'via_link' => ! empty( $_SESSION['via_link'] ), 'flash' => $flash,
			'subs' => $subs, 'email' => $this->customerEmail( $cid ), 'trial' => $trial, 'trial_days' => Settings::limit( 'trial_days' ),
			'trial_label' => (string) Config::get( 'plans.' . Config::get( 'trial_plan', 'full' ) . '.label', 'Pro' ),
			'areas' => $idn ? $idn['areas'] : array(), 'saas' => array_map( function ( $r ) { $r['re'] = SaasAccount::reactivation( $r, $this->db ); return $r; }, SaasAccount::forCustomer( $this->db, $cid ) ), 'billing' => $this->billing, 'is_admin' => ! empty( $_SESSION['staff'] ),
			'releases' => array_combine( Releases::entitled( $subs ), array_map( function ( $p ) { return Releases::all( $this->db, $p ); }, Releases::entitled( $subs ) ) ) ?: array(),
		) );
	}

	/**
	 * Acquisto del gestionale: «Calcola» mostra il totale dal listino di adesso (e controlla il codice promo); «Ordina e paga» apre l'ordine
	 * e manda al pagamento. Se tra le due cose il listino cambia, il cliente rivede il nuovo totale e conferma di nuovo.
	 */
	private function saasBuy( bool $get ): void {
		$cat = $this->saasCatalog();
		$in  = $get ? $_GET : $_POST;
		$v   = array(
			'plan' => Site::code( (string) ( $in['plan'] ?? '' ) ), 'period' => 'year' === ( $in['period'] ?? '' ) ? 'year' : 'month',
			'members' => max( 1, (int) ( $in['members'] ?? 50 ) ), 'addons' => SaasCheckout::cleanAddons( (array) ( $in['addons'] ?? array() ) ),
			'promo' => mb_substr( trim( (string) ( $in['promo'] ?? '' ) ), 0, 40 ), 'email' => trim( (string) ( $in['email'] ?? '' ) ),
			'name' => trim( (string) ( $in['name'] ?? '' ) ), 'org_name' => trim( (string) ( $in['org_name'] ?? '' ) ),
			'slug' => strtolower( trim( (string) ( $in['slug'] ?? '' ) ) ), 'provider' => (string) ( $in['provider'] ?? '' ),
		);
		if ( '' === $v['slug'] && '' !== $v['org_name'] ) {
			$v['slug'] = SaasCheckout::slugFrom( $v['org_name'] );
		}
		$quote = null;
		$error = '';
		if ( ! $get ) {
			$this->formLimit();
			if ( 'pay' === (string) $this->in( 'step' ) ) {
				$url = SaasCheckout::start( $this->db, $this->billing, $v + array( 'seen_total' => $_POST['seen_total'] ?? '' ) );
				$_SESSION['grazie'] = array( 'kind' => 'saas', 'associazione' => $v['org_name'], 'email' => $v['email'] ); // la pagina «Grazie» sa che ordine è
				header( 'Location: ' . $url, true, 303 );
				return;
			}
		}
		if ( $cat && '' !== $v['plan'] ) {
			try {
				$promo = SaasCheckout::promo( $v['promo'], $v['plan'], (int) ( Quote::build( $cat['doc'], $v['plan'], $v['period'], $v['members'], $v['addons'] )['plan_version'] ), $v['period'] );
				$quote = Quote::build( $cat['doc'], $v['plan'], $v['period'], $v['members'], $v['addons'], $promo );
			} catch ( UserError $e ) {
				$error = $e->getMessage();
			}
		}
		View::render( 'saas_buy', array( 'title' => 'Prendi il gestionale', 'doc' => $cat ? $cat['doc'] : null, 'v' => $v, 'quote' => $quote, 'error' => $error, 'providers' => SaasCheckout::providers() ) );
	}

	/** Il cliente torna da PayPal dopo aver approvato un pagamento singolo: qui si incassa l'ordine. */
	private function paypalReturn( string $orderId ): void {
		$sub = $this->billing->byProviderRef( 'paypal', $orderId );
		if ( ! $sub || 'once' !== $sub['mode'] ) {
			throw new UserError( 'Pagamento non trovato.' );
		}
		if ( 'pending' === $sub['status'] ) {
			if ( ! PayPal::captureOrder( $orderId ) ) {
				throw new UserError( 'PayPal non ha completato il pagamento. Non è stato addebitato nulla: riprova.' );
			}
			$this->billing->completePayment( $sub['id'] );
		}
		header( 'Location: /grazie', true, 303 );
	}

	/** API per il plugin: rimbalzo delle verifiche verso il server Wega (vedi {@see LicenseProxy}). */
	private function licenseApi( string $method, string $path ): void {
		$proxy = new LicenseProxy( $this->db, $this->billing->wega() );
		if ( 'POST' === $method && '/api/license/validate' === $path ) {
			$res = $proxy->validate( (string) substr( (string) file_get_contents( 'php://input' ), 0, 4096 ), $this->clientIp() );
		} elseif ( 'GET' === $method && '/api/plugin/latest' === $path ) {
			$res = $proxy->latest( $this->clientIp() );
		} elseif ( 'POST' === $method && '/api/plugin/update' === $path ) {
			$res = PluginUpdates::check( $this->db, $proxy, (string) substr( (string) file_get_contents( 'php://input' ), 0, 4096 ), $this->clientIp() );
		} elseif ( 'GET' === $method && '/api/plugin/package' === $path ) {
			// Scarico dello zip con indirizzo firmato (nessun accesso richiesto, ma il token dura pochi minuti).
			list( $code, $file, $prod, $ver ) = PluginUpdates::package( $this->db, (string) ( $_GET['t'] ?? '' ) );
			if ( 200 === $code && $proxy->hit( 'package', $this->clientIp() ) ) {
				Releases::stream( $file, $prod, $ver );
				return;
			}
			$res = array( 200 === $code ? 429 : $code, (string) json_encode( array( 'error' => array( 'code' => 'PACKAGE', 'message' => 200 === $code ? 'Troppe richieste: riprova più tardi.' : $file ) ) ), array() );
		} elseif ( 'GET' === $method && '/.well-known/jwks.json' === $path ) {
			$res = $proxy->jwks();
		} else {
			$res = array( 404, '{"error":{"code":"NOT_FOUND","message":"Non trovato."}}', array() );
		}
		http_response_code( $res[0] );
		header( 'Content-Type: application/json; charset=utf-8' );
		View::security();
		header( 'X-Robots-Tag: noindex' );
		foreach ( $res[2] as $h ) {
			header( $h );
		}
		echo $res[1];
	}

	private function webhook( string $path ): void {
		$payload = (string) file_get_contents( 'php://input' );
		$hooks   = new Webhooks( $this->db, $this->billing );
		if ( '/webhook/stripe' === $path ) {
			$code = $hooks->stripe( $payload, (string) ( $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '' ) );
		} elseif ( '/webhook/paypal' === $path ) {
			$h = array();
			foreach ( $_SERVER as $k => $v ) {
				if ( 0 === strpos( $k, 'HTTP_PAYPAL_' ) ) {
					$h[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = (string) $v;
				}
			}
			$code = $hooks->paypal( $payload, $h );
		} else {
			$code = 404;
		}
		http_response_code( $code );
	}

	/** @var callable[] email da mandare dopo che la risposta è stata consegnata */
	private $after = array();

	private function sendLink( array $cust, string $token, string $event, array $vars = array() ): void {
		// L'email parte a risposta consegnata: il tempo di risposta non dice se l'indirizzo è di un cliente.
		$this->after[] = function () use ( $cust, $token, $event, $vars ) {
			Emails::send( $this->db, $event, $cust['email'], $vars + array( 'link' => rtrim( (string) Config::get( 'base_url' ), '/' ) . '/entra/' . $token ) );
		};
	}

	/** Consegna la risposta e poi manda le email in coda. */
	private function finish(): void {
		if ( ! $this->after ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			flush();
		}
		$jobs        = $this->after;
		$this->after = array();
		foreach ( $jobs as $job ) {
			try {
				$job();
			} catch ( \Throwable $e ) {
				error_log( '[portale] email non inviata: ' . $e->getMessage() );
			}
		}
	}

	/** @return mixed */
	private function in( string $key ) {
		return $_POST[ $key ] ?? null;
	}

	private function customerId(): string {
		if ( empty( $_SESSION['customer'] ) ) {
			header( 'Location: /accedi', true, 303 );
			exit;
		}
		return (string) $_SESSION['customer'];
	}

	private function customerEmail( string $id ): string {
		$c = $this->db->one( 'SELECT email FROM customers WHERE id = ?', array( $id ) );
		return $c ? (string) $c['email'] : '';
	}

	private function csrfOk(): bool {
		return ! empty( $_SESSION['csrf'] ) && hash_equals( (string) $_SESSION['csrf'], (string) ( $_POST['csrf'] ?? '' ) );
	}

	public static function csrf(): string {
		if ( empty( $_SESSION['csrf'] ) ) {
			$_SESSION['csrf'] = bin2hex( random_bytes( 16 ) );
		}
		return (string) $_SESSION['csrf'];
	}
}
