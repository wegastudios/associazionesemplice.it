<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole del certificato medico sportivo: tipi, controllo dei dati, certificato in corso di una persona. Logica pura, senza WordPress. */
final class CertificateRules {

	const NON_AGONISTIC = 'non_agonistic';
	const AGONISTIC     = 'agonistic';
	const OTHER         = 'other';

	/** Giorni dopo la scadenza in cui si manda ancora il promemoria «scaduto». */
	const EXPIRED_WINDOW = 30;

	public static function kinds(): array {
		return array(
			self::NON_AGONISTIC => 'Non agonistico',
			self::AGONISTIC     => 'Agonistico',
			self::OTHER         => 'Altro',
		);
	}

	public static function is_kind( string $k ): bool {
		return isset( self::kinds()[ $k ] );
	}

	/**
	 * Controlla i dati di un certificato.
	 *
	 * @return array{kind:string,issued_on:string,expires_on:string,doctor:string}
	 * @throws \InvalidArgumentException
	 */
	public static function clean( array $in ): array {
		$kind = (string) ( $in['kind'] ?? '' );
		if ( ! self::is_kind( $kind ) ) {
			throw new \InvalidArgumentException( 'Scegli il tipo di certificato.' );
		}
		$issued  = trim( (string) ( $in['issued_on'] ?? '' ) );
		$expires = trim( (string) ( $in['expires_on'] ?? '' ) );
		if ( ! ExpiryRules::is_date( $issued ) ) {
			throw new \InvalidArgumentException( 'Indica la data di rilascio del certificato.' );
		}
		if ( ! ExpiryRules::is_date( $expires ) ) {
			throw new \InvalidArgumentException( 'Indica la data di scadenza del certificato.' );
		}
		if ( $expires < $issued ) {
			throw new \InvalidArgumentException( 'Il certificato non può scadere prima di essere stato rilasciato.' );
		}
		$doctor = trim( strip_tags( (string) ( $in['doctor'] ?? '' ) ) );
		return array( 'kind' => $kind, 'issued_on' => $issued, 'expires_on' => $expires, 'doctor' => mb_substr( $doctor, 0, 190 ) );
	}

	/**
	 * Il certificato che conta oggi: tra quelli già rilasciati, quello che scade più tardi (a parità, l'ultimo inserito).
	 *
	 * @param array[] $certs righe con id, issued_on, expires_on
	 */
	public static function current( array $certs, string $today ): ?array {
		$best = null;
		foreach ( $certs as $c ) {
			if ( (string) $c['issued_on'] > $today ) {
				continue; // rilasciato in una data futura: non vale ancora
			}
			if ( null === $best || (string) $c['expires_on'] > (string) $best['expires_on'] || ( (string) $c['expires_on'] === (string) $best['expires_on'] && (int) $c['id'] > (int) $best['id'] ) ) {
				$best = $c;
			}
		}
		return $best;
	}

	/** Stato di una persona: valido, in scadenza, scaduto o mancante. */
	public static function status( array $certs, string $today, int $warn ): string {
		$c = self::current( $certs, $today );
		return ExpiryRules::status( $c ? (string) $c['expires_on'] : null, $today, $warn );
	}

	/** Promemoria da mandare per uno stato: tipo (colonna `kind` dei promemoria) oppure vuoto. */
	public static function reminder_kind( string $status, int $days_left ): string {
		if ( ExpiryRules::EXPIRING === $status ) {
			return 'cert_soon';
		}
		if ( ExpiryRules::EXPIRED === $status && -$days_left <= self::EXPIRED_WINDOW ) {
			return 'cert_expired';
		}
		return '';
	}

	/** Riferimento del promemoria: cambia con la scadenza, così un certificato rinnovato ne riavvia uno nuovo. */
	public static function reminder_ref( int $cert_id, string $expires_on ): string {
		return $cert_id . ':' . $expires_on;
	}

	/** Quali persone, tra le iscritte alle attività che lo richiedono, non hanno nessun certificato (o ce l'hanno scaduto). */
	public static function filter_by_status( array $rows, string $status ): array {
		return array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $status ) {
					return '' === $status || $r['status'] === $status;
				}
			)
		);
	}
}
