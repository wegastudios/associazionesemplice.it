<?php
namespace AssociazioneSemplice\Admin;

use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Edition;
use AssociazioneSemplice\MemberType;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\SocialYear;

defined( 'ABSPATH' ) || exit;

/** Download CSV (separatore ";" e virgola decimale: si apre bene in Excel italiano). */
final class Exports {

	public static function register(): void {
		add_action( 'admin_post_asem_export', array( __CLASS__, 'handle' ) );
	}

	public static function link( string $what, array $args, string $label ): string {
		if ( ! Edition::allows( 'export' ) ) {
			return '<span class="button disabled" title="Esportazione sospesa: licenza non in regola">' . esc_html( $label ) . ' 🔒</span>';
		}
		$url =wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'asem_export', 'what' => $what ), $args ), admin_url( 'admin-post.php' ) ), 'asem_export' );
		return '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Un testo che inizia con = + - @ (o tabulazione / ritorno a capo) verrebbe eseguito come formula da Excel e LibreOffice: gli si mette
	 * un apice davanti. I numeri (importi, anche negativi, e numeri di telefono con il +) restano com'è.
	 */
	public static function neutralize( string $v ): string {
		// Il controllo guarda il primo carattere "vero": spazi e caratteri invisibili davanti non devono nascondere il segno.
		$t = preg_replace( '/^[ \n\0\x0B\x{00A0}\x{200B}\x{FEFF}]+/u', '', $v );
		$t = null === $t ? $v : $t; // testo non UTF-8: si controlla com'è
		if ( '' === $t || ! preg_match( '/^[=+\-@\t\r\x{FF1D}]/u', $t ) && ! preg_match( '/^[=+\-@\t\r]/', $t ) || preg_match( '/^[+\-]?[0-9., ]+$/', $v ) ) {
			return $v;
		}
		return "'" . $v;
	}

	public static function line( array $cells ): string {
		$out = array();
		foreach ( $cells as $c ) {
			$v     = self::neutralize( null === $c ? '' : (string) $c );
			$out[] = preg_match( '/[;"\r\n]/', $v ) ? '"' . str_replace( '"', '""', $v ) . '"' : $v;
		}
		return implode( ';', $out ) . "\r\n";
	}

	private static function d( ?string $ymd ): string {
		return $ymd ? ( new \DateTimeImmutable( $ymd ) )->format( 'd/m/Y' ) : '';
	}

	public static function handle(): void {
		if ( ! current_user_can( Plugin::CAP_OPS ) ) {
			wp_die( 'Non hai il permesso per questa operazione: chiedilo a un amministratore.', 403 );
		}
		check_admin_referer( 'asem_export' );
		if ( ! Edition::allows( 'export' ) ) {
			wp_die( 'L\'esportazione dei dati è sospesa: la licenza di Associazione Semplice non è in regola. Sistemala da Impostazioni, riquadro «Licenza».', 'Licenza non in regola', array( 'response' => 402, 'back_link' => true ) );
		}
		$what =isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		if ( ( ( 'period' === $what && ! Edition::has( 'fiscal' ) ) || ( 'social' === $what && ! Edition::has( 'reports' ) ) ) ) { // in questa edizione si esportano solo soci e prima nota
			wp_die( 'Questa esportazione non è disponibile in questa edizione.', 403 );
		}
		switch ( $what ) { // $chunks: il file a pezzi, scritto in uscita man mano (prima nota e soci possono essere molto lunghi)
			case 'ledger':
				$from   = Ui::get_str( 'from' );
				$to     = Ui::get_str( 'to' );
				$name   = "prima-nota-$from-$to.csv";
				$chunks = self::ledger_chunks( $from, $to );
				break;
			case 'period':
				list( $name, $csv ) = self::period( Ui::get_str( 'from' ), Ui::get_str( 'to' ) );
				$chunks = array( $csv );
				break;
			case 'social':
				list( $name, $csv ) = self::social( Ui::get_int( 'year', (int) Settings::social_year()->start_year ) );
				$chunks = array( $csv );
				break;
			case 'people':
				$name   = 'soci.csv';
				$chunks = self::people_chunks();
				break;
			default:
				wp_die( 'Export non valido.' );
		}
		\AssociazioneSemplice\Audit::log( 'export.downloaded', 'export', 0, array( 'what' => $what ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name ) . '"' );
		\AssociazioneSemplice\Download::csv_chunks( $chunks );
		exit;
	}

	/** Righe per blocco quando il file si scrive man mano in uscita. */
	const CHUNK_ROWS = 1000;

	/** La prima nota come file intero (stesso contenuto di quello che si scarica). */
	public static function ledger( string $from, string $to ): array {
		return array( "prima-nota-$from-$to.csv", implode( '', iterator_to_array( self::ledger_chunks( $from, $to ), false ) ) );
	}

	/** I soci come file intero (stesso contenuto di quello che si scarica). */
	public static function people(): array {
		return array( 'soci.csv', implode( '', iterator_to_array( self::people_chunks(), false ) ) );
	}

	/** La prima nota a blocchi di 1.000 righe: intestazione, poi i movimenti nello stesso ordine di sempre. @return \Generator<string> */
	private static function ledger_chunks( string $from, string $to ): \Generator {
		$vat = \AssociazioneSemplice\Fiscal::vat_applies();
		yield self::line( array_merge( array( 'Data', 'Tipo', 'Conto', 'Modalità', 'Voce', 'Attività', 'N. tessera', 'Persona', 'Descrizione', 'Competenza', 'Riferimento', 'Entrata', 'Uscita' ), $vat ? array( 'Aliquota IVA', 'Imponibile', 'IVA' ) : array() ) );
		$csv = '';
		$n   = 0;
		foreach ( Plugin::ledger()->rows( $from, $to, null, true ) as $r ) {
			$plus = Labels::sign( $r['type'] ) > 0 ? Money::plain( (int) $r['amount_cents'] ) : '';
			$minus = Labels::sign( $r['type'] ) < 0 ? Money::plain( (int) $r['amount_cents'] ) : '';
			$cells = array(
				self::d( $r['tx_date'] ), Labels::tx_types()[ $r['type'] ], $r['account_name'], Labels::methods()[ $r['method'] ] ?? $r['method'],
				Labels::is_transfer( $r['type'] ) ? 'Giroconto' : $r['category_name'], $r['activity_name'], $r['person_card'], $r['person_name'],
				$r['description'], $r['competence_month'], $r['document_ref'], $plus, $minus,
			);
			if ( $vat ) { // aliquota, imponibile e IVA contenuta (l'importo della riga è sempre quello lordo)
				$has_rate = null !== $r['vat_rate'] && '' !== $r['vat_rate'];
				$cells    = array_merge( $cells, Labels::is_transfer( $r['type'] ) ? array( '', '', '' ) : array( $has_rate ? (string) (int) $r['vat_rate'] . '%' : 'fuori campo', $has_rate ? Money::plain( (int) $r['amount_cents'] - (int) $r['vat_cents'] ) : '', $has_rate ? Money::plain( (int) $r['vat_cents'] ) : '' ) );
			}
			$csv .= self::line( $cells );
			if ( 0 === ++$n % self::CHUNK_ROWS ) {
				yield $csv;
				$csv = '';
			}
		}
		if ( '' !== $csv ) {
			yield $csv;
		}
	}

	public static function period( string $from, string $to ): array {
		$r   = Plugin::reports()->period( $from, $to );
		$csv = self::line( array( 'Rendiconto per cassa', Settings::get( 'association_name' ) ) );
		$csv .= self::line( array( 'Periodo', self::d( $from ) . ' - ' . self::d( $to ) ) ) . "\r\n";
		$csv .= self::line( array( 'SALDI DEI CONTI', 'Iniziale', 'Entrate', 'Uscite', 'Giroconti', 'Finale' ) );
		foreach ( $r['accounts'] as $a ) {
			$csv .= self::line( array( $a['account']['name'], Money::plain( $a['opening'] ), Money::plain( $a['income'] ), Money::plain( $a['expense'] ), Money::plain( $a['transfers'] ), Money::plain( $a['closing'] ) ) );
		}
		$csv .= self::line( array( 'Totale', Money::plain( $r['opening_total'] ), Money::plain( $r['total_income'] ), Money::plain( $r['total_expense'] ), '0,00', Money::plain( $r['closing_total'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'Fondi accantonati (rimborsi ai volontari)', '', '', '', '', Money::plain( -$r['funds_total'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'Disponibilità reale (saldi meno fondi)', '', '', '', '', Money::plain( $r['available'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'ENTRATE', 'Voce di rendiconto', 'Importo' ) );
		foreach ( $r['income'] as $x ) {
			$csv .= self::line( array( $x['name'], $x['fiscal_group'], Money::plain( $x['cents'] ) ) );
		}
		$csv .= self::line( array( 'Totale entrate', '', Money::plain( $r['total_income'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'USCITE', 'Voce di rendiconto', 'Importo' ) );
		foreach ( $r['expenses'] as $x ) {
			$csv .= self::line( array( $x['name'], $x['fiscal_group'], Money::plain( $x['cents'] ) ) );
		}
		$csv .= self::line( array( 'Totale uscite', '', Money::plain( $r['total_expense'] ) ) ) . "\r\n";
		$csv .= self::line( array( 'Avanzo / disavanzo', '', Money::plain( $r['result'] ) ) );
		return array( "rendiconto-$from-$to.csv", $csv );
	}

	public static function social( int $start_year ): array {
		$year = new SocialYear( $start_year, Settings::start_month() );
		$r    = Plugin::reports()->social_year( $year );
		$csv  = self::line( array( 'Valutazione anno delle attività', $year->label(), Settings::get( 'association_name' ) ) );
		foreach ( MemberType::member_types() as $t ) {
			$csv .= self::line( array( MemberType::label( $t ), $r['members_by_type'][ $t ] ?? 0 ) );
		}
		$csv .= "\r\n" . self::line( array( 'ATTIVITÀ', 'Referente', 'Iscritti attivi', 'Incassi', 'Costi', "Resta all'associazione" ) );
		foreach ( $r['activities'] as $a ) {
			$csv .= self::line( array( $a['activity']['name'], $a['activity']['instructor_name'], $a['participants'], Money::plain( $a['income'] ), Money::plain( $a['cost'] ), Money::plain( $a['margin'] ) ) );
		}
		$parts = count( $r['fiscal_slices'] ) > 1 ? $r['fiscal_slices'] : array( array( 'label' => '', 'general_income' => $r['general_income'], 'general_expenses' => $r['general_expenses'], 'income' => array_sum( array_column( $r['general_income'], 'cents' ) ), 'expense' => array_sum( array_column( $r['general_expenses'], 'cents' ) ) ) );
		foreach ( $parts as $part ) { // l'anno delle attività attraversa più anni fiscali: un blocco per ognuno
			$suffix = '' === $part['label'] ? '' : ' - ' . $part['label'];
			$csv   .= "\r\n" . self::line( array( 'ENTRATE GENERALI (non di attività)' . $suffix, '', 'Importo' ) );
			foreach ( $part['general_income'] as $x ) {
				$csv .= self::line( array( $x['name'], '', Money::plain( $x['cents'] ) ) );
			}
			$csv .= self::line( array( 'USCITE GENERALI (non di attività)' . $suffix, '', 'Importo' ) );
			foreach ( $part['general_expenses'] as $x ) {
				$csv .= self::line( array( $x['name'], '', Money::plain( $x['cents'] ) ) );
			}
			if ( '' !== $part['label'] ) {
				$csv .= self::line( array( 'Totale generali ' . $part['label'], Money::plain( $part['income'] ), Money::plain( $part['expense'] ) ) );
			}
		}
		$csv .= "\r\n" . self::line( array( 'Totale entrate', Money::plain( $r['total_income'] ) ) );
		$csv .= self::line( array( 'Totale uscite', Money::plain( $r['total_expense'] ) ) );
		$csv .= self::line( array( 'Risultato', Money::plain( $r['result'] ) ) );
		return array( 'anno-attivita-' . str_replace( '/', '-', $year->label() ) . '.csv', $csv );
	}

	/** I soci a blocchi di 1.000 righe. @return \Generator<string> */
	private static function people_chunks(): \Generator {
		yield self::line( array( 'Numero tessera', 'Tipo', 'Nome', 'Cognome', 'Email', 'Telefono', 'Codice fiscale', 'Data di nascita', 'Tutore', 'Indirizzo', 'CAP', 'Comune', 'Provincia', 'Ospite di', 'Tessera valida fino al', 'Tipo di tesseramento', 'Foto e video' ) );
		$csv    = '';
		$n      = 0;
		$tutori = \AssociazioneSemplice\Guardians::names_by_person();
		foreach ( Plugin::people()->search() as $p ) {
			$csv .= self::line(
				array( $p['card_number'], \AssociazioneSemplice\Levels::label( $p ), $p['first_name'], $p['last_name'], $p['email'], $p['phone'], $p['tax_code'], self::d( $p['birth_date'] ?? null ), $tutori[ (int) $p['id'] ] ?? '', $p['address'], $p['zip'], $p['city'], $p['province'], $p['host_name'], self::d( MemberType::GUEST === $p['type'] ? null : $p['active_until'] ), \AssociazioneSemplice\MembershipRoles::label( $p['affiliation_role'] ?? null ), null === ( $p['image_consent'] ?? null ) ? '' : ( (int) $p['image_consent'] ? 'Sì' : 'No' ) )
			);
			if ( 0 === ++$n % self::CHUNK_ROWS ) {
				yield $csv;
				$csv = '';
			}
		}
		if ( '' !== $csv ) {
			yield $csv;
		}
	}
}
