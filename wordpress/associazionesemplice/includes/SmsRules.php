<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** SMS ai soci: regole pure (numeri, lunghezza, mittente, nuovi tentativi), senza rete e senza WordPress. */
final class SmsRules {

	const MAX_CHARS    = 480; // tre messaggi concatenati da 160 caratteri
	const MAX_ATTEMPTS = 3;
	const CHANNEL      = 'sms';

	/** Caratteri dell'alfabeto GSM a 7 bit che contano 1; quelli di estensione contano 2. */
	const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	const GSM_EXT   = "^{}\\[~]|€\f";

	/** Numero nel formato internazionale E.164 («+393331234567»); senza prefisso si assume l'Italia. Vuoto se non è un numero valido. */
	public static function e164( string $raw ): string {
		$wa = Phone::whatsapp( $raw );
		return '' === $wa ? '' : '+' . $wa;
	}

	/** «+39 ••• ••• 4567»: il numero in parte nascosto, per i registri. */
	public static function mask( string $e164 ): string {
		$d = (string) preg_replace( '/\D+/', '', $e164 );
		if ( strlen( $d ) < 6 ) {
			return '***';
		}
		return '+' . substr( $d, 0, 2 ) . ' ••• ' . substr( $d, -3 );
	}

	/** Lunghezza del messaggio e numero di SMS che occuperà. @return array{encoding:string,units:int,parts:int} */
	public static function measure( string $text ): array {
		$units = 0;
		$gsm   = true;
		foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $ch ) {
			if ( false !== mb_strpos( self::GSM_BASIC, $ch, 0, 'UTF-8' ) ) {
				$units++;
			} elseif ( false !== mb_strpos( self::GSM_EXT, $ch, 0, 'UTF-8' ) ) {
				$units += 2;
			} else {
				$gsm = false;
				break;
			}
		}
		if ( ! $gsm ) { // basta un carattere fuori dall'alfabeto GSM (ad esempio un'emoji) e il messaggio passa a UCS-2
			$units = 0;
			foreach ( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $ch ) {
				$units += strlen( $ch ) >= 4 ? 2 : 1; // fuori dal piano base di Unicode: due unità
			}
			$single = 70;
			$multi  = 67;
		} else {
			$single = 160;
			$multi  = 153;
		}
		$parts = 0 === $units ? 0 : ( $units <= $single ? 1 : (int) ceil( $units / $multi ) );
		return array( 'encoding' => $gsm ? 'GSM-7' : 'UCS-2', 'units' => $units, 'parts' => $parts );
	}

	/** Testo pronto: segnaposto sostituiti, a capo uniformi, spazi in eccesso tolti. */
	public static function personalize( string $body, string $first_name, string $association ): string {
		$text = strtr( $body, array( '{nome}' => $first_name, '{associazione}' => $association ) );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return trim( (string) preg_replace( "/[ \t]+/", ' ', $text ) );
	}

	/** Testo di un promemoria in forma breve: associazione, oggetto e l'inizio del messaggio. */
	public static function reminder_text( string $association, string $subject, string $body ): string {
		$text = ( '' !== $association ? $association . ': ' : '' ) . trim( $subject ) . '. ' . trim( (string) preg_replace( '/\s+/', ' ', $body ) );
		return self::truncate( $text, 300 );
	}

	/** Accorcia a un massimo di caratteri, mettendo i puntini se serve. */
	public static function truncate( string $text, int $max ): string {
		return mb_strlen( $text, 'UTF-8' ) <= $max ? $text : rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . '…';
	}

	/** Mittente: fino a 11 caratteri tra lettere e cifre, oppure un numero (fino a 15 cifre). Vuoto se non valido. */
	public static function clean_sender( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^\+?\d{6,15}$/', $raw ) ) {
			return $raw;
		}
		$alnum = (string) preg_replace( '/[^A-Za-z0-9 ]/', '', $raw );
		$alnum = trim( $alnum );
		return strlen( $alnum ) > 11 ? substr( $alnum, 0, 11 ) : $alnum;
	}

	/** Dopo quanti minuti riprovare dopo il tentativo n (1, 2…); null = basta così. */
	public static function retry_after_minutes( int $attempts_done, bool $permanent ): ?int {
		if ( $permanent || $attempts_done >= self::MAX_ATTEMPTS ) {
			return null;
		}
		return array( 1 => 15, 2 => 120 )[ $attempts_done ] ?? 120;
	}

	/** Controllo del testo di una comunicazione. @return string|null il motivo del rifiuto */
	public static function check_text( string $text ): ?string {
		if ( '' === trim( $text ) ) {
			return 'Scrivi il testo dell\'SMS.';
		}
		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_CHARS ) {
			return 'SMS troppo lungo: al massimo ' . self::MAX_CHARS . ' caratteri.';
		}
		return null;
	}
}
