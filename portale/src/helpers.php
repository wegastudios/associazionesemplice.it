<?php
// Funzioni di aiuto per le pagine (stato e tipo delle licenze, importi, date).

/** Stato mostrabile di una licenza: [testo, classe]. Una licenza «attiva» ma oltre la scadenza risulta scaduta. */
function sub_status( array $s ): array {
	$exp = ! empty( $s['expires_at'] ) ? strtotime( $s['expires_at'] . ' UTC' ) : 0;
	if ( 'canceled' === $s['status'] ) {
		return array( 'Annullata', 'warn' );
	}
	if ( 'merged' === $s['status'] ) {
		return array( 'Rinnovo registrato', '' );
	}
	if ( 'pending' === $s['status'] ) {
		return array( 'In attesa', 'warn' );
	}
	if ( $exp && $exp < time() ) {
		return array( 'Scaduta', 'bad' );
	}
	if ( 'past_due' === $s['status'] ) {
		return array( 'Pagamento non riuscito', 'bad' );
	}
	return array( 'Attiva', '' );
}

/** Tipo di licenza in italiano: gratuita, prova, manuale, ricorrente o singolo. */
function sub_kind( array $s ): string {
	switch ( $s['provider'] ) {
		case 'free':
			return 'Gratuita';
		case 'trial':
			return 'Prova';
		case 'manual':
			return 'Assegnata manualmente';
	}
	$who = 'stripe' === $s['provider'] ? 'Carta' : 'PayPal';
	return $who . ' · ' . ( 'once' === ( $s['mode'] ?? 'recurring' ) ? 'singolo' : ( 'year' === $s['period'] ? 'annuale ricorrente' : 'mensile ricorrente' ) );
}

function money( $cents ): string {
	$cents = (int) $cents;
	return number_format( $cents / 100, ( $cents % 100 ) ? 2 : 0, ',', '.' ) . ' €';
}

function date_it( ?string $utc ): string {
	return $utc ? date( 'd/m/Y', strtotime( $utc . ' UTC' ) ) : '—';
}

/**
 * Gestore `onsubmit` che chiede conferma. Il messaggio può contenere dati (email, domini): va passato come stringa JavaScript
 * vera (json_encode) e poi come attributo HTML; e() da solo non basta, perché il browser decodifica &#039; prima di eseguire lo script.
 */
function js_confirm( string $message ): string {
	return e( 'return confirm(' . json_encode( $message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ')' );
}

/**
 * Fumetto di aiuto: un piccolo «?» che apre una nuvoletta con la spiegazione (clic o tastiera; si chiude con Esc o fuori).
 * Il testo è semplice: **grassetto** e a capo con una riga vuota; tutto il resto viene reso innocuo.
 */
function tip( string $text, string $title = '' ): string {
	$body = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e( $text ) );
	$body = preg_replace( '/\n{2,}/', '</p><p>', trim( $body ) );
	static $n = 0;
	++$n;
	return '<span class="tip"><button type="button" class="tip-btn" aria-label="Spiegazione: ' . e( $title ?: 'aiuto' ) . '" aria-expanded="false" aria-controls="tip' . $n . '">?</button>'
		. '<span class="tip-bubble" id="tip' . $n . '" role="note" hidden>' . ( '' !== $title ? '<b class="tip-title">' . e( $title ) . '</b>' : '' ) . '<p>' . $body . '</p></span></span>';
}
