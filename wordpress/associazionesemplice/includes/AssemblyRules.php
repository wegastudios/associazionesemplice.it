<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole (pure) delle assemblee dei soci: convocazione, numero legale, deleghe. Il resto sta in {@see Assemblies}.
 * I numeri di partenza sono quelli del codice civile e del Codice del Terzo settore; lo statuto dell'ente può prevedere altro,
 * per questo il numero legale si scrive assemblea per assemblea.
 */
final class AssemblyRules {

	const ORDINARY      = 'ordinary';
	const EXTRAORDINARY = 'extraordinary';

	const DRAFT     = 'draft';
	const CONVENED  = 'convened';
	const HELD      = 'held';
	const CANCELLED = 'cancelled';

	/** Giorni di preavviso della convocazione sotto i quali si avvisa la segreteria (art. 20 c.c.: almeno otto giorni). */
	const MIN_NOTICE_DAYS = 8;

	/** Soglia di soci tra «fino a tre deleghe» e «fino a cinque» (art. 24 c. 3 Codice del Terzo settore). */
	const LARGE_ASSOCIATION = 500;

	public static function kinds(): array {
		return array( self::ORDINARY => 'Assemblea ordinaria', self::EXTRAORDINARY => 'Assemblea straordinaria' );
	}

	public static function status_labels(): array {
		return array( self::DRAFT => 'Bozza', self::CONVENED => 'Convocata', self::HELD => 'Svolta', self::CANCELLED => 'Annullata' );
	}

	/** Numero legale di partenza: [prima convocazione, seconda convocazione] in percentuale degli aventi diritto. */
	public static function default_quorum( string $kind ): array {
		return self::EXTRAORDINARY === $kind ? array( 75, 50 ) : array( 50, 0 );
	}

	/** Percentuale valida (0–100), con 0 se vuota o fuori misura. */
	public static function clean_percent( $v ): int {
		return max( 0, min( 100, (int) $v ) );
	}

	/**
	 * Quanti soci servono per il numero legale. Il 50% vale «metà più uno» (più della metà); le altre percentuali valgono
	 * «almeno» quella parte, arrotondata per eccesso. Zero percento: nessun minimo.
	 */
	public static function required( int $eligible, int $percent ): int {
		$percent = self::clean_percent( $percent );
		if ( $eligible <= 0 || 0 === $percent ) {
			return 0;
		}
		if ( 50 === $percent ) {
			return intdiv( $eligible, 2 ) + 1;
		}
		return (int) ceil( $eligible * $percent / 100 );
	}

	/**
	 * Il numero legale c'è? Contano i presenti e le deleghe dei presenti (una delega di chi non c'è non conta).
	 *
	 * @return array{counted:int,required:int,reached:bool}
	 */
	public static function quorum( int $eligible, int $present, int $proxies_of_present, int $percent ): array {
		$counted  = max( 0, $present ) + max( 0, $proxies_of_present );
		$required = self::required( $eligible, $percent );
		return array( 'counted' => $counted, 'required' => $required, 'reached' => $counted >= $required );
	}

	/** Quante deleghe può avere al massimo un socio: tre fino a 499 soci, cinque da 500 (art. 24 c. 3 CTS). */
	public static function max_proxies( int $eligible ): int {
		return $eligible >= self::LARGE_ASSOCIATION ? 5 : 3;
	}

	/**
	 * Si può registrare questa delega? Restituisce il motivo del rifiuto, vuoto se va bene.
	 *
	 * @param int[] $delegators_of_delegate chi ha già delegato lo stesso socio
	 * @param int[] $delegators             chi ha già delegato qualcuno (compreso chi sta delegando)
	 * @param int[] $delegates              chi ha già ricevuto una delega
	 */
	public static function proxy_refusal( int $delegator, int $delegate, bool $delegator_eligible, bool $delegate_eligible, array $delegators_of_delegate, array $delegators, array $delegates, int $max ): string {
		if ( $delegator === $delegate ) {
			return 'Non si può delegare sé stessi.';
		}
		if ( ! $delegator_eligible ) {
			return 'Chi delega deve essere un socio in regola.';
		}
		if ( ! $delegate_eligible ) {
			return 'La delega si dà a un altro socio in regola, non a chi non ha diritto di voto.';
		}
		if ( in_array( $delegator, $delegates, true ) ) {
			return 'Questo socio ha già ricevuto deleghe: non può a sua volta delegare.';
		}
		if ( in_array( $delegate, $delegators, true ) ) {
			return 'Il socio scelto ha già delegato qualcun altro: scegline un altro.';
		}
		if ( in_array( $delegator, $delegators, true ) ) {
			return 'Questo socio ha già dato una delega per questa assemblea: revocala prima.';
		}
		if ( count( array_diff( $delegators_of_delegate, array( $delegator ) ) ) >= $max ) {
			return 'Il socio scelto ha già ' . $max . ' deleghe, che è il massimo.';
		}
		return '';
	}

	/** Si può cambiare lo stato così? */
	public static function can_move( string $from, string $to ): bool {
		$map = array(
			self::DRAFT     => array( self::CONVENED, self::CANCELLED ),
			self::CONVENED  => array( self::HELD, self::CANCELLED, self::DRAFT ),
			self::HELD      => array(),
			self::CANCELLED => array( self::DRAFT ),
		);
		return in_array( $to, $map[ $from ] ?? array(), true );
	}

	/** Giorni tra la convocazione e la prima convocazione (date «Y-m-d»); negativo se la convocazione è dopo. */
	public static function notice_days( string $convened_on, string $first_call_date ): int {
		$a = \DateTimeImmutable::createFromFormat( '!Y-m-d', $convened_on );
		$b = \DateTimeImmutable::createFromFormat( '!Y-m-d', $first_call_date );
		return $a && $b ? (int) $a->diff( $b )->format( '%r%a' ) : 0;
	}

	/** Avviso se la convocazione è troppo a ridosso della riunione, vuoto se va bene. */
	public static function notice_warning( int $days ): string {
		return $days < self::MIN_NOTICE_DAYS ? 'La prima convocazione è fra meno di ' . self::MIN_NOTICE_DAYS . ' giorni: controlla che il preavviso previsto dallo statuto sia rispettato.' : '';
	}

	/**
	 * Una seconda convocazione ha senso solo dopo la prima.
	 *
	 * @return string|null messaggio d'errore
	 */
	public static function check_calls( string $first, string $second ): ?string {
		if ( '' === $second ) {
			return null;
		}
		return $second > $first ? null : 'La seconda convocazione deve venire dopo la prima.';
	}

	/** Il numero legale che vale per la convocazione in cui si svolge l'assemblea (1 = prima, 2 = seconda). */
	public static function percent_for_call( int $call, int $first, int $second ): int {
		return 2 === $call ? self::clean_percent( $second ) : self::clean_percent( $first );
	}

	/** La riga di testo con cui il verbale ricorda presenze e numero legale. */
	public static function summary_line( int $eligible, int $present, int $proxies, array $quorum, int $call ): string {
		return 'Aventi diritto al voto: ' . $eligible . '. Presenti: ' . $present . ', rappresentati per delega: ' . $proxies . '. In ' . ( 2 === $call ? 'seconda' : 'prima' )
			. ' convocazione ' . ( $quorum['required'] > 0 ? 'servivano ' . $quorum['required'] . ' soci' : 'non era richiesto un numero minimo' )
			. ': l\'assemblea ' . ( $quorum['reached'] ? 'è validamente costituita' : 'NON raggiunge il numero legale' ) . '.';
	}
}
