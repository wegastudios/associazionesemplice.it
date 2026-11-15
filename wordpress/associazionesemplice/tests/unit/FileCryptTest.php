<?php
use AssociazioneSemplice\FileCrypt;
use AssociazioneSemplice\FileCryptError;
use PHPUnit\Framework\TestCase;

/** Cifratura a flussi dei file riservati: andata e ritorno, casi limite dei blocchi, manomissioni, chiavi e dati associati. Si salta dove manca libsodium. */
final class FileCryptTest extends TestCase {

	/** @var string[] */
	private $tmp = array();

	protected function setUp(): void {
		if ( ! FileCrypt::available() ) {
			$this->markTestSkipped( 'libsodium non disponibile' );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->tmp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->tmp = array();
	}

	private function key(): string {
		return str_repeat( 'k', 32 );
	}

	private function keys( int $id = 7, ?string $key = null ): callable {
		$key = $key ?? $this->key();
		return function ( int $asked ) use ( $id, $key ) {
			return $asked === $id ? $key : null;
		};
	}

	private function tmpfile(): string {
		$f           = tempnam( sys_get_temp_dir(), 'asem-fc' );
		$this->tmp[] = $f;
		return $f;
	}

	/** Cifra un testo e dà il contenuto cifrato. */
	private function enc( string $plain, ?string $ad = null, int $id = 7 ): string {
		$in  = fopen( 'php://memory', 'w+b' );
		fwrite( $in, $plain );
		rewind( $in );
		$out = fopen( 'php://memory', 'w+b' );
		$r   = FileCrypt::encrypt_stream( $in, $out, $this->key(), $id, $ad ?? 'as-file|site|abc' );
		$this->assertSame( strlen( $plain ), $r['bytes'] );
		$this->assertSame( hash( 'sha256', $plain ), $r['sha256'] );
		rewind( $out );
		return (string) stream_get_contents( $out );
	}

	/** Decifra e dà il testo in chiaro. */
	private function dec( string $cipher, ?string $ad = null, ?callable $keys = null ): string {
		$in = fopen( 'php://memory', 'w+b' );
		fwrite( $in, $cipher );
		rewind( $in );
		$buf = '';
		$r   = FileCrypt::decrypt_stream(
			$in,
			function ( string $d ) use ( &$buf ): void {
				$buf .= $d;
			},
			$keys ?? $this->keys(),
			$ad ?? 'as-file|site|abc'
		);
		$this->assertSame( strlen( $buf ), $r['bytes'] );
		$this->assertSame( hash( 'sha256', $buf ), $r['sha256'] );
		return $buf;
	}

	private function reason( callable $f ): string {
		try {
			$f();
		} catch ( FileCryptError $e ) {
			return $e->reason;
		}
		return '';
	}

	/** @dataProvider sizes */
	public function test_roundtrip_at_every_block_boundary( int $n ): void {
		$plain  = $n > 0 ? random_bytes( $n ) : '';
		$cipher = $this->enc( $plain );
		$blocks = max( 1, (int) ceil( $n / FileCrypt::CHUNK ) );
		$this->assertSame( FileCrypt::HEADER_LEN + $n + $blocks * FileCrypt::ABYTES, strlen( $cipher ) );
		$this->assertSame( $plain, $this->dec( $cipher ) );
	}

	public static function sizes(): array {
		$c = FileCrypt::CHUNK;
		return array( array( 0 ), array( 1 ), array( $c - 1 ), array( $c ), array( $c + 1 ), array( 2 * $c ), array( 2 * $c + 5 ), array( 300000 ) );
	}

	public function test_empty_file_is_one_final_block(): void {
		$cipher = $this->enc( '' );
		$this->assertSame( FileCrypt::HEADER_LEN + FileCrypt::ABYTES, strlen( $cipher ) );
		$this->assertSame( '', $this->dec( $cipher ) );
	}

	public function test_header_layout(): void {
		$cipher = $this->enc( 'ciao', null, 123456 );
		$this->assertSame( "\x01", $cipher[0] );
		$this->assertSame( 123456, unpack( 'N', substr( $cipher, 1, 4 ) )[1] );
		$this->assertGreaterThan( 0, strlen( $cipher ) );
	}

	public function test_ten_megabytes_through_files(): void {
		$src = $this->tmpfile();
		$h   = fopen( $src, 'wb' );
		$rnd = random_bytes( 1048576 );
		for ( $i = 0; $i < 10; $i++ ) {
			fwrite( $h, $rnd );
			$rnd = hash( 'sha256', $rnd, true ) . substr( $rnd, 32 ); // contenuto che cambia da un megabyte all'altro
		}
		fclose( $h );
		$enc = $this->tmpfile();
		unlink( $enc ); // encrypt_file crea il file: non deve esistere
		$r   = FileCrypt::encrypt_file( $src, $enc, $this->key(), 7, 'as-file|site|x' );
		$this->assertSame( 10485760, $r['bytes'] );
		$this->assertSame( hash_file( 'sha256', $src ), $r['sha256'] );
		$this->assertTrue( FileCrypt::is_encrypted_file( $enc ) );
		$this->assertFalse( FileCrypt::is_encrypted_file( $src ) );
		$this->assertSame( 10485760 + FileCrypt::HEADER_LEN + 160 * FileCrypt::ABYTES, filesize( $enc ) );
		$check = FileCrypt::decrypt_file( $enc, null, $this->keys(), 'as-file|site|x' ); // solo controllo, senza scrivere
		$this->assertSame( $r['sha256'], $check['sha256'] );
		$back = $this->tmpfile();
		unlink( $back );
		FileCrypt::decrypt_file( $enc, $back, $this->keys(), 'as-file|site|x' );
		$this->assertSame( hash_file( 'sha256', $src ), hash_file( 'sha256', $back ) );
	}

	public function test_tampered_block_is_refused(): void {
		$cipher = $this->enc( random_bytes( 200000 ) );
		foreach ( array( FileCrypt::HEADER_LEN + 10, FileCrypt::HEADER_LEN + FileCrypt::CHUNK + FileCrypt::ABYTES + 100, strlen( $cipher ) - 1 ) as $pos ) {
			$bad         = $cipher;
			$bad[ $pos ] = chr( ord( $bad[ $pos ] ) ^ 1 );
			$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $bad ) { $this->dec( $bad ); } ), "byte $pos" );
		}
	}

	public function test_corrupted_header_is_refused(): void {
		$cipher = $this->enc( 'contenuto' );
		$bad    = $cipher;
		$bad[10] = chr( ord( $bad[10] ) ^ 1 ); // intestazione secretstream
		$this->assertNotSame( '', $this->reason( function () use ( $bad ) { $this->dec( $bad ); } ) );
		$bad     = $cipher;
		$bad[0]  = "\x02"; // formato sconosciuto
		$this->assertSame( FileCryptError::FORMAT, $this->reason( function () use ( $bad ) { $this->dec( $bad ); } ) );
		$this->assertSame( FileCryptError::FORMAT, $this->reason( function () { $this->dec( 'corto' ); } ) );
		$this->assertSame( FileCryptError::FORMAT, $this->reason( function () { $this->dec( '' ); } ) );
	}

	public function test_truncated_file_is_refused(): void {
		$cipher = $this->enc( random_bytes( 3 * FileCrypt::CHUNK ) );
		$cut    = substr( $cipher, 0, FileCrypt::HEADER_LEN + 2 * ( FileCrypt::CHUNK + FileCrypt::ABYTES ) ); // tagliato esattamente a fine blocco
		$this->assertSame( FileCryptError::TRUNCATED, $this->reason( function () use ( $cut ) { $this->dec( $cut ); } ) );
		$cut = substr( $cipher, 0, strlen( $cipher ) - 5 ); // tagliato dentro l'ultimo blocco
		$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $cut ) { $this->dec( $cut ); } ) );
	}

	public function test_data_after_the_final_block_is_refused(): void {
		$cipher = $this->enc( 'contenuto' ) . random_bytes( 40 );
		// l'ultimo blocco è più corto del massimo: i dati in più si leggono come parte del blocco e non si autenticano
		$this->assertContains( $this->reason( function () use ( $cipher ) { $this->dec( $cipher ); } ), array( FileCryptError::AUTH, FileCryptError::FORMAT ) );
	}

	public function test_wrong_key_and_unknown_key(): void {
		$cipher = $this->enc( 'segreto' );
		$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $cipher ) { $this->dec( $cipher, null, $this->keys( 7, str_repeat( 'x', 32 ) ) ); } ) );
		$this->assertSame( FileCryptError::NO_KEY, $this->reason( function () use ( $cipher ) { $this->dec( $cipher, null, $this->keys( 8 ) ); } ) );
	}

	public function test_different_associated_data_is_refused(): void {
		$cipher = $this->enc( 'segreto', 'as-file|sito-a|abc' );
		$this->assertSame( 'segreto', $this->dec( $cipher, 'as-file|sito-a|abc' ) );
		$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $cipher ) { $this->dec( $cipher, 'as-file|sito-a|altro-file' ); } ) ); // spostato altrove
		$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $cipher ) { $this->dec( $cipher, 'as-file|sito-b|abc' ); } ) ); // altro sito
		$this->assertSame( 'as-file|s|a/b', FileCrypt::ad_file( 's', 'a/b' ) );
	}

	public function test_failed_encryption_leaves_no_file(): void {
		$src = $this->tmpfile();
		file_put_contents( $src, 'x' );
		$dst = $this->tmpfile();
		unlink( $dst );
		$this->assertSame( FileCryptError::BAD_KEY, $this->reason( function () use ( $src, $dst ) { FileCrypt::encrypt_file( $src, $dst, 'corta', 1, 'ad' ); } ) );
		$this->assertFileDoesNotExist( $dst );
		$this->assertSame( FileCryptError::IO, $this->reason( function () use ( $dst ) { FileCrypt::encrypt_file( '/non/esiste', $dst, str_repeat( 'k', 32 ), 1, 'ad' ); } ) );
	}

	public function test_fields_roundtrip_and_checks(): void {
		$k  = $this->key();
		$ad = FileCrypt::ad_field( 'site', 'medical_certs', 'doctor', 5 );
		$e  = FileCrypt::encrypt_field( 'Dott. Rossi — Via è', $k, 7, $ad );
		$this->assertTrue( FileCrypt::is_field( $e ) );
		$this->assertFalse( FileCrypt::is_field( 'Dott. Rossi' ) );
		$this->assertSame( 'Dott. Rossi — Via è', FileCrypt::decrypt_field( $e, $this->keys(), $ad ) );
		$this->assertNotSame( $e, FileCrypt::encrypt_field( 'Dott. Rossi — Via è', $k, 7, $ad ) ); // nonce diverso a ogni volta
		$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $e ) { FileCrypt::decrypt_field( $e, $this->keys(), FileCrypt::ad_field( 'site', 'medical_certs', 'doctor', 6 ) ); } ) ); // altra riga
		$this->assertSame( FileCryptError::AUTH, $this->reason( function () use ( $e, $ad ) { FileCrypt::decrypt_field( $e, $this->keys( 7, str_repeat( 'z', 32 ) ), $ad ); } ) );
		$this->assertSame( FileCryptError::NO_KEY, $this->reason( function () use ( $e, $ad ) { FileCrypt::decrypt_field( $e, $this->keys( 9 ), $ad ); } ) );
		$this->assertSame( FileCryptError::FORMAT, $this->reason( function () use ( $ad ) { FileCrypt::decrypt_field( FileCrypt::FIELD_PREFIX . 'AAAA', $this->keys(), $ad ); } ) );
		$this->assertSame( '', FileCrypt::decrypt_field( FileCrypt::encrypt_field( '', $k, 7, $ad ), $this->keys(), $ad ) );
	}

	public function test_key_wrapping_and_ids(): void {
		$dek    = random_bytes( 32 );
		$master = FileCrypt::master_from_material( 'salt|abc' );
		$this->assertSame( 32, strlen( $master ) );
		$w = FileCrypt::wrap_key( $dek, $master );
		$this->assertSame( $dek, FileCrypt::unwrap_key( $w, $master ) );
		$this->assertNull( FileCrypt::unwrap_key( $w, FileCrypt::master_from_material( 'salt|altro' ) ) );
		$this->assertNull( FileCrypt::unwrap_key( 'non base64 !!', $master ) );
		$this->assertSame( FileCrypt::key_id( $dek ), FileCrypt::key_id( $dek ) );
		$this->assertNotSame( FileCrypt::key_id( $dek ), FileCrypt::key_id( random_bytes( 32 ) ) );
		$this->assertLessThan( 268435456, FileCrypt::key_id( $dek ) );
	}

	public function test_key_from_phrase(): void {
		if ( ! FileCrypt::pwhash_available() ) {
			$this->markTestSkipped( 'Argon2id non disponibile' );
		}
		$salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
		$a    = FileCrypt::key_from_phrase( 'una frase lunga', $salt, 2, 8192 * 8 );
		$this->assertSame( 32, strlen( $a ) );
		$this->assertSame( $a, FileCrypt::key_from_phrase( 'una frase lunga', $salt, 2, 8192 * 8 ) );
		$this->assertNotSame( $a, FileCrypt::key_from_phrase( 'un\'altra frase', $salt, 2, 8192 * 8 ) );
		$this->assertSame( FileCryptError::NO_SODIUM, $this->reason( function () { FileCrypt::key_from_phrase( 'x', 'sale corto', 2, 8192 * 8 ); } ) );
	}
}
