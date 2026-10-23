<?php /** @var string $content */
$robots = ! \Portale\Config::get( 'indexable', false );
$logged = ! empty( $_SESSION['customer'] );
$support_href = \Portale\SupportSso::enabled() && $logged ? '/supporto/accedi' : \Portale\SupportSso::supportUrl();
$v      = (string) @filemtime( __DIR__ . '/../public/assets/portale.css' );
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Associazione Semplice · Il gestionale per le associazioni</title>
<meta name="description" content="Soci, eventi, prima nota e privacy per la tua associazione. Plugin WordPress gratuito, con piani Pro per pagamenti online e contabilità.">
<?php if ( $robots ) : ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<meta name="theme-color" content="#1f218c">
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg?v=<?php echo e( $v ); ?>"><link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/favicon-32.png?v=<?php echo e( $v ); ?>"><link rel="alternate icon" href="/assets/brand/favicon.ico?v=<?php echo e( $v ); ?>"><link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png?v=<?php echo e( $v ); ?>">
<link rel="stylesheet" href="/assets/portale.css?v=<?php echo e( $v ); ?>">
<?php echo \Portale\Tracking::slot( 'head' ); ?></head>
<body>
<header class="site-header"><div class="wrap">
	<a class="logo" href="/" aria-label="Associazione Semplice"><picture class="logo-full"><source media="(prefers-color-scheme: dark)" srcset="/assets/brand/logo-orizzontale-negativo.svg?v=<?php echo e( $v ); ?>"><img src="/assets/brand/logo-orizzontale-colore.svg?v=<?php echo e( $v ); ?>" alt="Associazione Semplice" width="143" height="52"></picture><picture class="logo-sym"><source media="(prefers-color-scheme: dark)" srcset="/assets/brand/simbolo-negativo.svg?v=<?php echo e( $v ); ?>"><img src="/assets/brand/simbolo-colore.svg?v=<?php echo e( $v ); ?>" alt="Associazione Semplice" width="40" height="40"></picture></a>
	<nav class="nav" aria-label="Principale">
		<a href="/#funzioni">Funzioni</a><a href="/#prezzi">Prezzi</a><a href="/#domande">Domande</a><a href="<?php echo e( $support_href ); ?>">Supporto</a>
		<?php if ( ! empty( $_SESSION['staff'] ) ) : ?><a href="/admin">Gestione</a><?php endif; ?>
		<a class="btn small" href="<?php echo $logged ? '/account' : '/accedi'; ?>">Area riservata</a>
	</nav>
</div></header>
<main><?php echo $content; ?></main>
<footer class="site-footer"><div class="wrap">
	<div>© <?php echo date( 'Y' ); ?> Associazione Semplice</div>
	<div><a href="/free">Licenza gratuita</a><a href="/plugin">Il plugin</a><a href="/confronto">Confronto versioni</a><a href="<?php echo e( $support_href ); ?>">Supporto</a><a href="/accedi">Area riservata</a><a href="/privacy">Privacy</a><a href="/termini">Termini</a><a href="/cookie">Cookie</a><?php if ( \Portale\Tracking::banner() ) : ?><a href="#" data-cc-open>Preferenze cookie</a><?php endif; ?></div>
</div></footer>
<?php if ( \Portale\Tracking::banner() ) : $cc = \Portale\Tracking::categories(); ?>
<div class="cc" id="cc" role="dialog" aria-label="Cookie" aria-live="polite" hidden data-rev="<?php echo (int) \Portale\Tracking::conf()['rev']; ?>" data-cats="<?php echo e( implode( ',', array_keys( $cc ) ) ); ?>">
	<div class="cc-box">
		<p class="cc-text"><?php echo e( \Portale\Tracking::bannerText() ); ?> <a href="/cookie">Informativa sui cookie</a></p>
		<div class="cc-prefs" hidden>
			<label class="check"><input type="checkbox" checked disabled> Necessari (sempre attivi)</label>
			<?php foreach ( $cc as $k => $l ) : ?><label class="check"><input type="checkbox" data-cc-pref="<?php echo e( $k ); ?>"> <?php echo e( $l ); ?></label><?php endforeach; ?>
		</div>
		<div class="cc-actions">
			<button type="button" class="btn alt small" data-cc="reject">Rifiuta</button>
			<button type="button" class="btn alt small" data-cc="custom">Personalizza</button>
			<button type="button" class="btn small" data-cc="all">Accetta tutti</button>
		</div>
	</div>
</div>
<?php endif; ?>
<script src="/assets/portale.js?v=<?php echo e( $v ); ?>" defer></script>
<?php echo \Portale\Tracking::slot( 'foot' ); ?></body>
</html>
