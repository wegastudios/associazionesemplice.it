<?php
namespace Portale\Plugin;

use Portale\Db;
use Portale\UserError;

/**
 * Bozza e pubblicazione del catalogo del plugin (ARCHITETTURA_DOMINI §4.2).
 * - La BOZZA è una sola, modificabile; le modifiche non sono visibili finché non si preme «Pubblica».
 * - Ogni pubblicazione crea una REVISIONE immutabile (`plugin_catalog_revs`) con numero crescente.
 * - La `plan_version` di un piano sale di uno solo se il suo contenuto (testo, limiti, caratteristiche, livello di assistenza) è cambiato.
 * Nomi e prezzi dei piani NON stanno qui: restano in «Piani e prezzi» (config) e il catalogo li legge da lì finché il negozio non passa dal catalogo.
 */
final class CatalogStore {

	const STATES = array( 'included', 'limit', 'excluded' );

	/** Revisione pubblicata più recente come documento, o null se non è mai stato pubblicato. */
	public static function published( Db $db ): ?array {
		$row = $db->one( 'SELECT doc FROM plugin_catalog_revs ORDER BY rev DESC LIMIT 1' );
		$doc = $row ? json_decode( (string) $row['doc'], true ) : null;
		return is_array( $doc ) ? $doc : null;
	}

	public static function hasDraft( Db $db ): bool {
		return null !== $db->one( 'SELECT 1 AS x FROM plugin_catalog_draft WHERE id = 1' );
	}

	/** Bozza corrente; se non c'è, parte dall'ultima pubblicazione (o dai piani di oggi). */
	public static function draft( Db $db ): array {
		$row = $db->one( 'SELECT doc FROM plugin_catalog_draft WHERE id = 1' );
		$d   = $row ? json_decode( (string) $row['doc'], true ) : null;
		return is_array( $d ) ? self::normalize( $d ) : self::fromDocument( ( new DbCatalog( $db ) )->document() );
	}

	public static function saveDraft( Db $db, array $draft, string $actor ): void {
		$json = (string) json_encode( self::normalize( $draft ), JSON_UNESCAPED_UNICODE );
		if ( self::hasDraft( $db ) ) {
			$db->run( 'UPDATE plugin_catalog_draft SET doc = ?, updated_at = ?, updated_by = ? WHERE id = 1', array( $json, Db::now(), $actor ) );
		} else {
			$db->run( 'INSERT INTO plugin_catalog_draft (id, doc, updated_at, updated_by) VALUES (1,?,?,?)', array( $json, Db::now(), $actor ) );
		}
	}

	public static function discard( Db $db ): void {
		$db->run( 'DELETE FROM plugin_catalog_draft WHERE id = 1' );
	}

	/** Documento catalogo → bozza modificabile. */
	public static function fromDocument( array $doc ): array {
		$d = array( 'plans' => array(), 'features' => array(), 'matrix' => array() );
		foreach ( (array) ( $doc['plans'] ?? array() ) as $p ) {
			$p['limits']              = (array) ( $p['limits'] ?? array() );
			$d['plans'][ $p['code'] ] = array(
				'audience'      => (string) ( $p['audience'] ?? '' ),
				'visible'       => ! empty( $p['visible'] ),
				'order'         => (int) ( $p['order'] ?? 0 ),
				'support_level' => (string) ( $p['support_level'] ?? 'paid' ),
				'sites'         => isset( $p['limits']['sites'] ) ? (int) $p['limits']['sites'] : null,
			);
		}
		foreach ( (array) ( $doc['features'] ?? array() ) as $f ) {
			$d['features'][] = self::feature( (array) $f );
			foreach ( (array) ( $f['plans'] ?? array() ) as $code => $cell ) {
				$d['matrix'][ $code ][ $f['id'] ] = array( 'state' => (string) ( $cell['state'] ?? 'excluded' ), 'note' => (string) ( $cell['note'] ?? '' ) );
			}
		}
		return self::normalize( $d );
	}

	/** Chiavi comuni per il confronto tra prodotti (stesse del catalogo del SaaS; docs/CONFRONTO_PIANI.md). */
	const COMPARE_KEYS = array( 'members', 'ledger', 'activities', 'member_area', 'online_payments', 'resources', 'einvoice', 'fiscal', 'site', 'custom_domain', 'two_factor', 'operators', 'trial' );

	/** Una caratteristica in forma canonica: codice e testo, più gruppo, chiave di confronto e dettaglio (facoltativi). */
	public static function feature( array $f ): array {
		return array(
			'id'      => (string) ( $f['id'] ?? '' ),
			'text'    => trim( (string) ( $f['text'] ?? '' ) ),
			'group'   => trim( (string) ( $f['group'] ?? '' ) ),
			'compare' => trim( (string) ( $f['compare'] ?? '' ) ),
			'detail'  => trim( (string) ( $f['detail'] ?? '' ) ),
		);
	}

	/** Forma canonica della bozza (tipi sicuri; i valori vengono controllati da {@see validate}). */
	public static function normalize( array $d ): array {
		$out = array( 'plans' => array(), 'features' => array(), 'matrix' => array() );
		foreach ( (array) ( $d['plans'] ?? array() ) as $code => $p ) {
			$sites                  = $p['sites'] ?? null;
			$out['plans'][ (string) $code ] = array(
				'audience'      => trim( (string) ( $p['audience'] ?? '' ) ),
				'visible'       => ! empty( $p['visible'] ),
				'order'         => (int) ( $p['order'] ?? 0 ),
				'support_level' => '' !== (string) ( $p['support_level'] ?? '' ) ? (string) $p['support_level'] : ( 'free' === (string) $code ? 'free' : 'paid' ), // non scelto = Standard
				'sites'         => ( null === $sites || '' === $sites ) ? null : (int) $sites,
			);
		}
		foreach ( (array) ( $d['features'] ?? array() ) as $f ) {
			$out['features'][] = self::feature( (array) $f );
		}
		foreach ( (array) ( $d['matrix'] ?? array() ) as $code => $row ) {
			foreach ( (array) $row as $fid => $cell ) {
				$out['matrix'][ (string) $code ][ (string) $fid ] = array( 'state' => (string) ( $cell['state'] ?? 'excluded' ), 'note' => trim( (string) ( $cell['note'] ?? '' ) ) );
			}
		}
		return $out;
	}

	/** Righe «id | testo» → elenco di caratteristiche. @return array [elenco, errori] */
	public static function parseFeatures( string $text ): array {
		$list   = array();
		$errors = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 4 ) );
			if ( count( $parts ) < 2 ) {
				$errors[] = 'Riga «' . mb_substr( $line, 0, 40 ) . '»: usa il formato «codice | testo | gruppo | chiave di confronto» (gruppo e chiave sono facoltativi).';
				continue;
			}
			$list[] = self::feature( array( 'id' => $parts[0], 'text' => $parts[1], 'group' => $parts[2] ?? '', 'compare' => $parts[3] ?? '' ) );
		}
		return array( $list, $errors );
	}

	/**
	 * Controlli che impediscono errori costosi. @param string[] $planCodes codici dei piani che esistono (slug)
	 * @return string[] errori (vuoto = pubblicabile)
	 */
	public static function validate( array $d, array $planCodes ): array {
		$err = array();
		$ids = array();
		foreach ( $d['features'] as $f ) {
			if ( 1 !== preg_match( '/^[a-z0-9_]{2,40}$/', $f['id'] ) ) {
				$err[] = 'Codice caratteristica «' . $f['id'] . '» non valido (2–40 caratteri: lettere minuscole, cifre, trattino basso).';
			}
			if ( isset( $ids[ $f['id'] ] ) ) {
				$err[] = 'Codice caratteristica «' . $f['id'] . '» ripetuto.';
			}
			$ids[ $f['id'] ] = true;
			if ( '' === $f['text'] || mb_strlen( $f['text'] ) > 160 || $f['text'] !== strip_tags( $f['text'] ) || false !== strpos( $f['text'], '<' ) ) {
				$err[] = 'Testo della caratteristica «' . $f['id'] . '»: da 1 a 160 caratteri, senza HTML.';
			}
			if ( '' !== $f['compare'] && ! in_array( $f['compare'], self::COMPARE_KEYS, true ) ) {
				$err[] = 'Caratteristica «' . $f['id'] . '»: chiave di confronto «' . $f['compare'] . '» sconosciuta (valide: ' . implode( ', ', self::COMPARE_KEYS ) . ').';
			}
			if ( mb_strlen( $f['group'] ) > 60 || mb_strlen( $f['detail'] ) > 600 || false !== strpos( $f['group'] . $f['detail'], '<' ) ) {
				$err[] = 'Caratteristica «' . $f['id'] . '»: gruppo al massimo 60 caratteri, dettaglio 600, senza HTML.';
			}
		}
		$visible = 0;
		foreach ( $planCodes as $code ) {
			$p = $d['plans'][ $code ] ?? null;
			if ( ! $p ) {
				$err[] = 'Piano «' . $code . '» mancante nella bozza.';
				continue;
			}
			if ( 'free' === $code ) {
				if ( 'free' !== $p['support_level'] ) {
					$err[] = 'Piano «' . $code . '»: il piano gratuito ha sempre l\'assistenza «Gratuita».';
				}
			} elseif ( ! in_array( $p['support_level'], array( 'paid', 'top' ), true ) ) {
				$err[] = 'Piano «' . $code . '»: scegli l\'assistenza «Standard» o «Top».';
			}
			if ( $p['order'] < 0 || $p['order'] > 999 ) {
				$err[] = 'Piano «' . $code . '»: ordine da 0 a 999.';
			}
			if ( null !== $p['sites'] && ( $p['sites'] < 1 || $p['sites'] > 1000 ) ) {
				$err[] = 'Piano «' . $code . '»: siti ammessi da 1 a 1000 (o vuoto).';
			}
			if ( mb_strlen( $p['audience'] ) > 200 || false !== strpos( $p['audience'], '<' ) ) {
				$err[] = 'Piano «' . $code . '»: «a chi è rivolto» al massimo 200 caratteri, senza HTML.';
			}
			$has = 0;
			foreach ( (array) ( $d['matrix'][ $code ] ?? array() ) as $fid => $cell ) {
				if ( ! in_array( $cell['state'], self::STATES, true ) ) {
					$err[] = 'Piano «' . $code . '», caratteristica «' . $fid . '»: stato non valido.';
				} elseif ( 'excluded' !== $cell['state'] && isset( $ids[ $fid ] ) ) {
					++$has;
				}
				if ( mb_strlen( $cell['note'] ) > 120 || false !== strpos( $cell['note'], '<' ) ) {
					$err[] = 'Piano «' . $code . '», caratteristica «' . $fid . '»: nota al massimo 120 caratteri, senza HTML.';
				}
			}
			if ( $p['visible'] ) {
				++$visible;
				if ( 0 === $has ) {
					$err[] = 'Piano «' . $code . '»: un piano visibile senza caratteristiche non si può pubblicare.';
				}
			}
		}
		if ( 0 === $visible ) {
			$err[] = 'Deve restare visibile almeno un piano.';
		}
		return $err;
	}

	/**
	 * Pubblica la bozza: nuova revisione immutabile. @param array $base documento di base (nomi e prezzi vivi, da {@see ConfigCatalog})
	 * @return int numero della nuova revisione
	 * @throws UserError se la bozza non è valida
	 */
	public static function publish( Db $db, array $base, string $actor, ?string $at = null ): int {
		$d     = self::draft( $db );
		$codes = array_column( $base['plans'], 'code' );
		$err   = self::validate( $d, $codes );
		if ( $err ) {
			throw new UserError( 'Non posso pubblicare: ' . implode( ' ', $err ) );
		}
		$prev     = self::published( $db );
		$prevPlan = array();
		foreach ( (array) ( $prev['plans'] ?? array() ) as $p ) {
			$prevPlan[ $p['code'] ] = $p;
		}
		$doc            = $base;
		$doc['rev']     = (int) ( $prev['rev'] ?? 0 ) + 1;
		$doc['features'] = array();
		foreach ( $d['features'] as $f ) {
			$cells = array();
			foreach ( $codes as $code ) {
				$c            = $d['matrix'][ $code ][ $f['id'] ] ?? array( 'state' => 'excluded', 'note' => '' );
				$cells[ $code ] = array( 'state' => $c['state'], 'note' => $c['note'] );
			}
			$row = array( 'id' => $f['id'], 'text' => $f['text'] );
			foreach ( array( 'group', 'compare', 'detail' ) as $k ) {
				if ( '' !== $f[ $k ] ) {
					$row[ $k ] = $f[ $k ];
				}
			}
			$row['plans']      = $cells;
			$doc['features'][] = $row;
		}
		foreach ( $doc['plans'] as $i => $p ) {
			$e                       = $d['plans'][ $p['code'] ];
			$p['audience']           = $e['audience'];
			$p['visible']            = $e['visible'];
			$p['order']              = $e['order'];
			$p['support_level']      = $e['support_level'];
			$p['limits']             = null === $e['sites'] ? (object) array() : (object) array( 'sites' => $e['sites'] );
$p['features'] = array();
			$p['cells']    = array();
			foreach ( $doc['features'] as $f ) {
				$cell                = $f['plans'][ $p['code'] ];
				$p['cells'][ $f['id'] ] = $cell['state'] . '|' . $cell['note'] . '|' . $f['text'];
				if ( 'excluded' !== $cell['state'] ) {
					$p['features'][] = $f['id'];
				}
			}
			$was = $prevPlan[ $p['code'] ] ?? null;
			$p['plan_version'] = $was ? (int) $was['plan_version'] + ( self::fingerprint( $was ) === self::fingerprint( $p ) ? 0 : 1 ) : 1;
			$doc['plans'][ $i ] = $p;
		}
		// Il contenuto di un piano cambia anche se cambia una sua casella nella tabella: l'impronta guarda caratteristiche, testi e note.
		$db->run( 'INSERT INTO plugin_catalog_revs (rev, doc, at, actor) VALUES (?,?,?,?)', array( $doc['rev'], (string) json_encode( $doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $at ?? Db::now(), $actor ) );
		self::discard( $db );
		return $doc['rev'];
	}

	/** Impronta del contenuto di un piano (senza `plan_version`). */
	private static function fingerprint( array $plan ): string {
		return hash( 'sha256', (string) json_encode( array( $plan['audience'] ?? '', (bool) ( $plan['visible'] ?? false ), (int) ( $plan['order'] ?? 0 ), $plan['support_level'] ?? '', (array) ( $plan['limits'] ?? array() ), $plan['features'] ?? array(), (array) ( $plan['cells'] ?? array() ) ) ) );
	}

	/** Revisioni pubblicate, dalla più recente. */
	public static function history( Db $db, int $limit = 20 ): array {
		return $db->all( 'SELECT rev, at, actor FROM plugin_catalog_revs ORDER BY rev DESC LIMIT ' . max( 1, $limit ) );
	}
}
