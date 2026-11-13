<?php
// Copia in config.php (fuori dalla cartella pubblica se possibile) e compila. Mai nel repository.
return array(
	'base_url' => 'https://portale.associazionesemplice.it',
	'app_key'  => '',   // 32 byte in base64: php -r "echo base64_encode(random_bytes(32));"  (cifra le chiavi di licenza)
	'mail_from' => 'info@associazionesemplice.it',
	'indexable' => false, // finché è false: robots.txt, X-Robots-Tag e meta robots vietano l'indicizzazione (cambiare anche public/robots.txt prima del lancio)
	'trust_forwarded_for' => false, // true solo dietro un proxy fidato: i limiti del rimbalzo licenze usano X-Forwarded-For invece dell'IP della connessione
	'forwarded_hops' => 1, // con trust_forwarded_for: quanti proxy fidati stanno davanti al portale (si legge X-Forwarded-For da destra, mai da sinistra)
	'admins'    => array(),   // email che possono entrare nel pannello di gestione (stesso accesso dei clienti, link per email)

	// Sito di supporto clienti e accesso unico: lo stesso segreto (almeno 32 caratteri casuali) va nel .env del sito di supporto.
	'support' => array( 'url' => 'https://supporto.associazionesemplice.it', 'sso_secret' => '' ),

	// PostgreSQL (database unico della piattaforma: schema `portal`, ruolo `portal_app`). SQLite resta solo per le prove locali e per il passaggio.
	'db' => array(
		'dsn'       => 'sqlite:' . __DIR__ . '/data/portale.sqlite',
		// 'dsn'    => 'pgsql:host=127.0.0.1;port=5432;dbname=associazionesemplice',
		'user'      => '',
		'pass'      => '',
		// 'pass_file' => '/percorso/fuori/dal/repository/portal_db.pass',  // al posto di 'pass': file con la sola password
		// 'schema'    => 'portal',
	),

	// Server licenze Wega (token con scope licenses:create, licenses:read, licenses:change-binding).
	'wega' => array(
		'base_url' => 'https://license.wegastudios.com/api/v1',
		'token'    => '',   // wic_xxxxxxxx.SEGRETO
	'fallback_ip' => '65.108.75.89', // usato solo se il DNS non risolve license.wegastudios.com (vuoto = mai)
	),

	'stripe' => array(
		'secret_key'     => '',
		'webhook_secret' => '',
	),
	'paypal' => array(
		'live'       => false,
		'client_id'  => '',
		'secret'     => '',
		'webhook_id' => '',
	),

	// Giorni di margine tra pagamento e scadenza della licenza (la tolleranza del server è 5 giorni).
	'duration_days' => array( 'month' => 31, 'year' => 366, 'free' => 30 ),
	// Un pagamento PayPal è trattato come rinnovo solo negli ultimi N giorni di validità (evita il doppio conteggio del primo).
	'renew_window_days' => 5,

	// Piani: plan_name è il nome inviato al server licenze. I prezzi sono in centesimi (solo per la vetrina:
	// l'importo realmente addebitato è quello del prezzo/piano creato su Stripe e PayPal).
	'plans' => array(
		'free' => array( 'label' => 'Free', 'plan_name' => 'Free', 'prices' => array() ),
		'lite' => array(
			'label' => 'Pro Contabile', 'plan_name' => 'Pro Lite',
			'description' => 'Tutto il Pro, senza la parte contabile.',
			'prices' => array(
				'month' => array( 'cents' => 500,  'stripe_price' => '', 'paypal_plan' => '' ),
				'year'  => array( 'cents' => 4800, 'stripe_price' => '', 'paypal_plan' => '' ),
			),
		),
		'full' => array(
			'label' => 'Pro Fiscale', 'plan_name' => 'Pro Full',
			'description' => 'Tutto: contabilità, IVA, 5 per mille, rendiconto.',
			'prices' => array(
				'month' => array( 'cents' => 1200, 'stripe_price' => '', 'paypal_plan' => '' ),
				'year'  => array( 'cents' => 11900, 'stripe_price' => '', 'paypal_plan' => '' ),
			),
		),
	),

	// Dove portare chi apre /saas/admin se il server non lo serve già sotto il dominio principale (docs/ARCHITETTURA_DOMINI.md §3.3).
	// Dove rimandare /saas/admin finché non lo serve già il SaaS sotto questo dominio; VUOTO = nessun rimando (lo serve il SaaS, il portale non si mette in mezzo).
	'saas_admin_url' => 'https://app.associazionesemplice.it/admin',
	// Demo pubblica del gestionale (collegamento in home, /saas e /prova con gli accessi ruolo@dominio); vuoto = nessun collegamento.
	// Mettere l'indirizzo solo quando la demo risponde davvero. Password = quella degli accessi di prova (SAAS_DEMO_PASSWORD del SaaS).
	'saas_demo_url'  => '', // es. 'https://demosaas.associazionesemplice.it'
	'saas_demo_password' => '',
	// Proxy verso il SaaS: /saas/admin, /saas/staff, /saas/api e /saas/vai si servono dal gestionale restando su questo dominio. Spento = come prima (rinvio di /saas/admin a saas_admin_url).
	'saas_proxy' => array( 'enabled' => false, 'upstream' => 'https://app.associazionesemplice.it' ),
	// Accesso unico (saas/docs/IDENTITA_API.md): /accedi, /twofactor, /account/esci e il reset password parlano col SaaS con richieste firmate (chiave staff_area_sign_key). Spento = accesso come prima.
	'identity' => array( 'enabled' => false ),
	// Pagina «Gestisci» nell'area clienti (stato, dominio, controllo cambio piano): accendere solo quando il SaaS ha in produzione gli endpoint v2 (promo, plan-change, domain, status).
	'saas_retry' => false, // lasciare false: con Stripe riprova Stripe (Smart Retries, al massimo 8 in una settimana: in Stripe → Fatturazione → Recupero ricavi impostarli sulla durata più vicina ai 7 giorni). Il portale sa rifare i tentativi (3 al giorno per 2 giorni, poi 1 al giorno per 5; bin/retry.php ogni ora) ma oggi solo per Stripe: servirebbe per altri metodi che non riprovano da soli
	'saas_v2' => array( 'enabled' => false, 'domain' => false ), // 'domain': dominio personalizzato ai clienti, solo quando i certificati sono decisi

	// Catalogo del SaaS (piani, prezzi, promo): chiave pubblica con cui il SaaS firma, come «kid => x» (si legge con `php artisan saas:keys:generate` / dal SaaS).
	// Senza chiavi il portale non scarica il catalogo e /saas non mostra prezzi. Indirizzo opzionale: 'saas_catalog_url' (default {saas_api_url}/api/catalogo/v1).
	'saas_public_keys' => array(),
);
