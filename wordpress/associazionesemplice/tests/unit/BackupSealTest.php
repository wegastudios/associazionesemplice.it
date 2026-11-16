<?php
use AssociazioneSemplice\BackupSeal as S;
use AssociazioneSemplice\FileCrypt;
use PHPUnit\Framework\TestCase;

/** Firma e controlli di una copia di sicurezza: impronte di ogni voce, firma con i codici del sito, tetti di dimensione, profili di derivazione accettati. */
final class BackupSealTest extends TestCase {

	/** @var string[] */
	private $tmp = array();

	protected function tearDown(): void {
		foreach ( $this->tmp as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->tmp = array();
	}

	/** Uno ZIP di prova con le voci date (nome => contenuto). */
	private function zip( array $entries ): \ZipArchive {
		$path        = tempnam( sys_get_temp_dir(), 'asem-seal' );
		$this->tmp[] = $path;
		$z           = new \ZipArchive();
		$this->assertTrue( true === $z->open( $path, \ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $body ) {
			$z->addFromString( $name, $body );
		}
		$z->close();
		$z = new \ZipArchive();
		$z->open( $path );
		return $z;
	}

	public function test_key_and_fingerprint_follow_the_site_codes(): void {
		$a = S::key( 'codici-del-sito-a' );
		$b = S::key( 'codici-del-sito-b' );
		$this->assertSame( 32, strlen( $a ) );
		$this->assertSame( $a, S::key( 'codici-del-sito-a' ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{16}$/', S::fingerprint( $a ) ) );
		$this->assertSame( S::fingerprint( $a ), S::fingerprint( S::key( 'codici-del-sito-a' ) ) );
		$this->assertNotSame( S::fingerprint( $a ), S::fingerprint( $b ) );
		$this->assertStringNotContainsString( bin2hex( $a ), S::fingerprint( $a ) );
	}

	public function test_canonical_ignores_the_order_of_keys_but_not_of_lists(): void {
		$this->assertSame( S::canonical( array( 'b' => 1, 'a' => array( 'y' => 2, 'x' => 3 ) ) ), S::canonical( array( 'a' => array( 'x' => 3, 'y' => 2 ), 'b' => 1 ) ) );
		$this->assertNotSame( S::canonical( array( 'l' => array( 1, 2 ) ) ), S::canonical( array( 'l' => array( 2, 1 ) ) ) );
		$this->assertSame( '{"a":"è/x"}', S::canonical( array( 'a' => 'è/x' ) ) );
	}

	public function test_signature_covers_every_field_but_itself(): void {
		$key = S::key( 'codici' );
		$m   = array( 'plugin' => 'associazionesemplice', 'tables' => array( 'asem_people' => 3 ), 'entries' => array( 'tabelle/asem_people.jsonl' => str_repeat( 'a', 64 ) ) );
		$sig = S::signature( $m, $key );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $sig ) );
		$this->assertSame( $sig, S::signature( $m + array( 'sig' => 'qualunque' ), $key ), 'il campo «sig» non entra nella firma' );
		$this->assertSame( $sig, S::signature( json_decode( (string) json_encode( $m ), true ), $key ), 'la firma si rifà uguale dopo la lettura del JSON' );
		$other = $m;
		$other['tables']['asem_people'] = 4;
		$this->assertNotSame( $sig, S::signature( $other, $key ) );
		$other = $m;
		$other['entries']['tabelle/asem_people.jsonl'] = str_repeat( 'b', 64 );
		$this->assertNotSame( $sig, S::signature( $other, $key ) );
		$other = $m;
		$other['voce_nuova'] = 1;
		$this->assertNotSame( $sig, S::signature( $other, $key ) );
		$this->assertNotSame( $sig, S::signature( $m, S::key( 'altri codici' ) ), 'con altri codici la firma è diversa' );
	}

	public function test_hashes_list_every_entry_but_the_manifest(): void {
		$z = $this->zip( array( 'manifest.json' => '{}', 'tabelle/asem_people.jsonl' => "{\"id\":1}\n", 'allegati/' . str_repeat( 'a', 32 ) => 'contenuto' ) );
		$s = S::sizes( $z );
		$this->assertSame( '', $s['problem'] );
		$h = S::hashes( $z, $s['sizes'] );
		$names = array_keys( $h );
		sort( $names );
		$this->assertSame( array( 'allegati/' . str_repeat( 'a', 32 ), 'tabelle/asem_people.jsonl' ), $names );
		$this->assertSame( hash( 'sha256', 'contenuto' ), $h[ 'allegati/' . str_repeat( 'a', 32 ) ] );
	}

	public function test_compare_finds_unlisted_missing_and_changed_entries(): void {
		$h = hash( 'sha256', 'uno' );
		$g = hash( 'sha256', 'due' );
		$this->assertSame( '', S::compare( array( 'a' => $h, 'b' => $g ), array( 'a' => $h, 'b' => $g ) ) );
		$this->assertSame( 'unlisted', S::compare( array( 'a' => $h ), array( 'a' => $h, 'b' => $g ) ), 'una voce che l\'elenco non cita' );
		$this->assertSame( 'missing', S::compare( array( 'a' => $h, 'b' => $g ), array( 'a' => $h ) ), 'una voce citata che non c\'è' );
		$this->assertSame( 'changed', S::compare( array( 'a' => $h ), array( 'a' => $g ) ), 'una voce con un\'impronta diversa' );
		$this->assertSame( 'changed', S::compare( array( 'a' => 'non-una-impronta' ), array( 'a' => $h ) ) );
		$this->assertSame( 'changed', S::compare( array( 'a' => strtoupper( $g ) ), array( 'a' => $h ) ) );
		$this->assertSame( '', S::compare( array( 'a' => strtoupper( $h ) ), array( 'a' => $h ) ), 'maiuscole e minuscole sono la stessa impronta' );
	}

	public function test_limits_are_checked_on_declared_sizes_before_any_read(): void {
		$z = $this->zip( array( 'manifest.json' => '{}', 'tabelle/asem_people.jsonl' => str_repeat( 'x', 100 ), 'chiavi.json' => str_repeat( 'k', 50 ) ) );
		$this->assertSame( '', S::sizes( $z )['problem'] );
		$this->assertSame( 'entry', S::sizes( $z, array( 'entry' => 99 ) )['problem'], 'una voce più grande del tetto per voce' );
		$this->assertSame( 'entry', S::sizes( $z, array( 'small' => 49 ) )['problem'], 'un file piccolo (chiavi, impostazioni, indice) più grande del suo tetto' );
		$this->assertSame( 'total', S::sizes( $z, array( 'total' => 120 ) )['problem'], 'la somma di tutte le voci decompresse' );
		$this->assertSame( 'entries', S::sizes( $z, array( 'entries' => 2 ) )['problem'], 'troppe voci' );
	}

	public function test_too_many_attachments_are_refused(): void {
		$e = array( 'manifest.json' => '{}' );
		for ( $i = 0; $i < 5; $i++ ) {
			$e[ 'allegati/' . str_pad( dechex( $i ), 32, '0', STR_PAD_LEFT ) ] = 'x';
		}
		$z = $this->zip( $e );
		$this->assertSame( '', S::sizes( $z, array( 'attachments' => 5 ) )['problem'] );
		$this->assertSame( 'attachments', S::sizes( $z, array( 'attachments' => 4 ) )['problem'] );
	}

	public function test_default_limits_are_finite(): void {
		$l = S::limits();
		foreach ( array( 'entry', 'small', 'total', 'attachments', 'entries' ) as $k ) {
			$this->assertGreaterThan( 0, $l[ $k ] );
		}
		$this->assertLessThanOrEqual( 8 * 1048576, $l['small'], 'chiavi, impostazioni e indice sono file piccoli' );
		$this->assertGreaterThanOrEqual( $l['entry'], $l['total'] );
	}

	public function test_only_the_profiles_the_plugin_produces_are_accepted(): void {
		$salt = base64_encode( str_repeat( 's', 16 ) );
		$mb   = 1048576;
		$this->assertTrue( FileCrypt::kdf_accepted( array( 'alg' => 'argon2id13', 'salt' => $salt, 'ops' => 2, 'mem' => 64 * $mb ) ) );
		$this->assertTrue( FileCrypt::kdf_accepted( array( 'alg' => 'argon2id13', 'salt' => $salt, 'ops' => 3, 'mem' => 24 * $mb ) ) );
		$this->assertTrue( FileCrypt::kdf_accepted( array( 'alg' => 'pbkdf2-sha256', 'salt' => $salt, 'iter' => 600000 ) ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'argon2id13', 'salt' => $salt, 'ops' => 12, 'mem' => 1024 * $mb ) ), 'il massimo che il lettore tollerava un tempo' );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'argon2id13', 'salt' => $salt, 'ops' => 2, 'mem' => 24 * $mb ) ), 'una coppia che il plugin non produce' );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'argon2id13', 'salt' => $salt, 'ops' => 3, 'mem' => 64 * $mb ) ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'pbkdf2-sha256', 'salt' => $salt, 'iter' => 5000000 ) ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'pbkdf2-sha256', 'salt' => $salt, 'iter' => 100000 ) ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'scrypt', 'salt' => $salt ) ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'pbkdf2-sha256', 'salt' => base64_encode( 'corto' ), 'iter' => 600000 ) ), 'sale troppo corto' );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'pbkdf2-sha256', 'salt' => '***', 'iter' => 600000 ) ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array( 'alg' => 'pbkdf2-sha256', 'salt' => $salt, 'iter' => '600000' ) ), 'i numeri devono essere numeri' );
		$this->assertFalse( FileCrypt::kdf_accepted( 'testo' ) );
		$this->assertFalse( FileCrypt::kdf_accepted( array() ) );
	}
}
