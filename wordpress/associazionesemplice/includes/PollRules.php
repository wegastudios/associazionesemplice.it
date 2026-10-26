<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) delle votazioni e dei sondaggi: finestra di voto, chi può votare, scelte valide, schede e conteggio. Il resto sta in Polls. */
final class PollRules {

	const UPCOMING = 'upcoming';
	const OPEN     = 'open';
	const CLOSED   = 'closed';

	const MIN_OPTIONS = 2;
	const MAX_OPTIONS = 20;

	const AUDIENCE_MEMBERS  = 'members';
	const AUDIENCE_ACTIVITY = 'activity';

	public static function kinds(): array {
		return array( 'vote' => 'Votazione', 'poll' => 'Sondaggio' );
	}

	public static function audiences(): array {
		return array( self::AUDIENCE_MEMBERS => 'Tutti i soci in regola', self::AUDIENCE_ACTIVITY => 'Gli iscritti a un\'attività' );
	}

	public static function status_labels(): array {
		return array( self::UPCOMING => 'Non ancora aperta', self::OPEN => 'Aperta', self::CLOSED => 'Chiusa' );
	}

	/**
	 * La finestra di voto: aperta da `opens_at` (compreso) a `closes_at` (escluso). Le date sono «Y-m-d H:i:s» nel fuso del sito.
	 *
	 * @return string upcoming | open | closed
	 */
	public static function status( string $opens_at, string $closes_at, string $now ): string {
		if ( $now < $opens_at ) {
			return self::UPCOMING;
		}
		return $now < $closes_at ? self::OPEN : self::CLOSED;
	}

	/** Data e ora da un campo «datetime-local» (o da «Y-m-d H:i[:s]») a «Y-m-d H:i:00». Null se non valida. */
	public static function parse_local( string $v ): ?string {
		$v = trim( str_replace( 'T', ' ', $v ) );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(:\d{2})?$/', $v, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return null;
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':00';
	}

	/** Il valore per un campo «datetime-local». */
	public static function to_local( string $dt ): string {
		return '' === $dt ? '' : str_replace( ' ', 'T', substr( $dt, 0, 16 ) );
	}

	/** @return string|null messaggio d'errore se la chiusura non viene dopo l'apertura */
	public static function check_window( string $opens_at, string $closes_at ): ?string {
		return $closes_at > $opens_at ? null : 'La chiusura deve venire dopo l\'apertura.';
	}

	/**
	 * Le opzioni scritte nel modulo (una per riga o un elenco): senza vuoti né doppioni.
	 *
	 * @param string[] $raw
	 * @return string[]
	 * @throws \InvalidArgumentException
	 */
	public static function normalize_options( array $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( $raw as $label ) {
			$label = mb_substr( trim( preg_replace( '/\s+/', ' ', (string) $label ) ), 0, 190 );
			$key   = mb_strtolower( $label );
			if ( '' === $label || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $label;
		}
		if ( count( $out ) < self::MIN_OPTIONS ) {
			throw new \InvalidArgumentException( 'Servono almeno due opzioni di risposta.' );
		}
		if ( count( $out ) > self::MAX_OPTIONS ) {
			throw new \InvalidArgumentException( 'Al massimo ' . self::MAX_OPTIONS . ' opzioni di risposta.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $out;
	}

	/**
	 * Può votare? Solo i soci veri (non i genitori o tutori, non gli ospiti), in regola con la tessera, non sospesi né usciti,
	 * e rientranti tra chi ha diritto al voto (tutti i soci, oppure gli iscritti dell'attività).
	 */
	public static function is_eligible( string $type, bool $active_member, bool $in_audience ): bool {
		return MemberType::is_member( $type ) && $active_member && $in_audience;
	}

	/** Motivo per cui non si può votare ora, '' se si può. */
	public static function cannot_vote_reason( string $status, bool $eligible, bool $already ): string {
		if ( self::UPCOMING === $status ) {
			return 'La votazione non è ancora aperta.';
		}
		if ( self::CLOSED === $status ) {
			return 'La votazione è chiusa.';
		}
		if ( ! $eligible ) {
			return 'Non hai diritto di voto in questa votazione.';
		}
		return $already ? 'Hai già votato.' : '';
	}

	/**
	 * Controlla le scelte fatte e le riduce a numeri senza doppioni.
	 *
	 * @param int[] $chosen    opzioni scelte
	 * @param int[] $valid_ids opzioni della votazione
	 * @return int[]
	 * @throws \InvalidArgumentException
	 */
	public static function check_choices( bool $multiple, int $max_choices, array $chosen, array $valid_ids ): array {
		$chosen = array_values( array_unique( array_filter( array_map( 'intval', $chosen ) ) ) );
		if ( ! $chosen ) {
			throw new \InvalidArgumentException( 'Scegli una risposta.' );
		}
		foreach ( $chosen as $id ) {
			if ( ! in_array( $id, array_map( 'intval', $valid_ids ), true ) ) {
				throw new \InvalidArgumentException( 'La risposta scelta non è tra quelle della votazione.' );
			}
		}
		if ( ! $multiple && count( $chosen ) > 1 ) {
			throw new \InvalidArgumentException( 'In questa votazione si sceglie una sola risposta.' );
		}
		if ( $multiple && $max_choices > 0 && count( $chosen ) > $max_choices ) {
			throw new \InvalidArgumentException( 'Puoi scegliere al massimo ' . $max_choices . ' risposte.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $chosen;
	}

	/**
	 * Le schede da salvare per un voto PALESE (chi ha scelto cosa). Nel voto segreto non si salva nessuna scheda: restano solo i contatori
	 * delle opzioni e l'elenco di chi ha votato, senza legame tra le due cose.
	 *
	 * @param int[] $option_ids
	 * @return array[] poll_id, option_id, person_id (vuoto se segreto)
	 */
	public static function ballot_rows( int $poll_id, bool $secret, int $person_id, array $option_ids ): array {
		$rows = array();
		if ( $secret ) {
			return $rows;
		}
		foreach ( $option_ids as $oid ) {
			$rows[] = array( 'poll_id' => $poll_id, 'option_id' => (int) $oid, 'person_id' => $person_id );
		}
		return $rows;
	}

	/**
	 * Conta le scelte per opzione.
	 *
	 * @param int[] $option_ids tutte le opzioni, nell'ordine di visualizzazione
	 * @param int[] $votes      una voce per ogni scelta registrata (l'opzione scelta)
	 * @return array<int,int> opzione => voti (anche zero)
	 */
	public static function tally( array $option_ids, array $votes ): array {
		$out = array();
		foreach ( $option_ids as $id ) {
			$out[ (int) $id ] = 0;
		}
		foreach ( $votes as $v ) {
			if ( isset( $out[ (int) $v ] ) ) {
				$out[ (int) $v ]++;
			}
		}
		return $out;
	}

	/** Percentuale intera (arrotondata) di `$part` su `$total`; 0 se non ci sono voti. */
	public static function percent( int $part, int $total ): int {
		return $total > 0 ? (int) round( $part * 100 / $total ) : 0;
	}

	/** Le opzioni che hanno preso più voti (più di una se pari). Vuoto se nessuno ha votato. @param array<int,int> $tally */
	public static function winners( array $tally ): array {
		$max = $tally ? max( $tally ) : 0;
		return $max > 0 ? array_keys( array_filter( $tally, function ( $n ) use ( $max ) {
			return $n === $max;
		} ) ) : array();
	}

	/** Chi vede i risultati: la segreteria sempre; i soci solo a votazione chiusa e se la segreteria ha scelto di mostrarli. */
	public static function results_visible( bool $is_staff, bool $show_to_members, string $status ): bool {
		return $is_staff || ( $show_to_members && self::CLOSED === $status );
	}
}
