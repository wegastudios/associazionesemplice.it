#!/usr/bin/perl
# Riduce un foglio di stile o uno script del front-end senza Node: toglie commenti e spazi superflui, non riscrive mai il codice
# (nomi, numeri e ordine restano quelli del sorgente, che nel repository resta leggibile).
# Uso: perl wordpress/minify-assets.pl css|js file-in file-out
# Esce con errore (e non scrive il file) se il controllo di coerenza fallisce; chi lo chiama, in quel caso, copia il sorgente com'è.
use strict;
use warnings;

my ( $kind, $in, $out ) = @ARGV;
die "uso: minify-assets.pl css|js file-in file-out\n" unless defined $out && ( $kind eq 'css' || $kind eq 'js' );
open( my $fh, '<:raw', $in ) or die "non si legge $in\n";
my $src = do { local $/; <$fh> };
close $fh;
$src =~ s/\r\n?/\n/g;

# ---- JavaScript ---------------------------------------------------------------------------------------------------------------
# Si toglie: commenti, spazi a inizio e fine riga, righe vuote, spazi doppi. Gli a capo restano (niente rischi con l'inserimento automatico del «;»).
sub js_tokens {
	my ( $s, $keep_ws ) = @_;
	my $o    = '';
	my $prev = '';
	my $re_after = qr/^(?:|[(,=:\[!&|?{;+\-*%<>~^]|return|typeof|case|in|of|delete|void|throw|new|else|do)$/;
	my $sp = sub { $o .= ' ' if $keep_ws && $o ne '' && $o !~ /[ \n]\z/; };
	my $nl = sub { if ($keep_ws) { $o =~ s/ +\z//; $o .= "\n" if $o ne '' && $o !~ /\n\z/; } };
	pos($s) = 0;
	while ( pos($s) < length $s ) {
		if ( $s =~ /\G\/\*.*?\*\//gcs ) {
			my $c = $&;
			if ( $c =~ /\n/ ) { $nl->(); } else { $sp->(); }
			next;
		}
		if ( $s =~ /\G\/\/[^\n]*/gc ) { next; }
		if ( $s =~ /\G(?:'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*")/gcs ) { $o .= $&; $prev = 's'; next; }
		if ( $s =~ /\G`(?:[^`\\]|\\.)*`/gcs ) { $o .= $&; $prev = 's'; next; }
		if ( $s =~ /\G\n/gc ) { $nl->(); next; }
		if ( $s =~ /\G[ \t]+/gc ) { $sp->(); next; }
		if ( $s =~ /\G\// ) {
			if ( $prev =~ $re_after && $s =~ /\G(\/(?:[^\/\\\n\[]|\\.|\[(?:[^\]\\\n]|\\.)*\])+\/[a-z]*)/gcs ) {
				$o .= $1;
				$prev = 's';
				next;
			}
			$s =~ /\G\//gc;
			$o .= '/';
			$prev = '/';
			next;
		}
		if ( $s =~ /\G([A-Za-z0-9_\$]+)/gc ) { $o .= $1; $prev = $1; next; }
		$s =~ /\G(.)/gcs;
		$o .= $1;
		$prev = $1;
	}
	return $o;
}

# La stessa lettura, ma senza nessuno spazio: serve a controllare che dal sorgente al ridotto non sia cambiato niente di significativo.
sub js_signature {
	my ($s) = @_;
	my $o = '';
	my $prev = '';
	my $re_after = qr/^(?:|[(,=:\[!&|?{;+\-*%<>~^]|return|typeof|case|in|of|delete|void|throw|new|else|do)$/;
	pos($s) = 0;
	while ( pos($s) < length $s ) {
		next if $s =~ /\G\/\*.*?\*\//gcs;
		next if $s =~ /\G\/\/[^\n]*/gc;
		if ( $s =~ /\G(?:'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"|`(?:[^`\\]|\\.)*`)/gcs ) { $o .= $&; $prev = 's'; next; }
		next if $s =~ /\G\s+/gc;
		if ( $s =~ /\G\// ) {
			if ( $prev =~ $re_after && $s =~ /\G(\/(?:[^\/\\\n\[]|\\.|\[(?:[^\]\\\n]|\\.)*\])+\/[a-z]*)/gcs ) { $o .= $1; $prev = 's'; next; }
			$s =~ /\G\//gc;
			$o .= '/';
			$prev = '/';
			next;
		}
		if ( $s =~ /\G([A-Za-z0-9_\$]+)/gc ) { $o .= $1 . "\x01"; $prev = $1; next; } # i nomi restano separati tra loro
		$s =~ /\G(.)/gcs;
		$o .= $1;
		$prev = $1;
	}
	return $o;
}

# ---- CSS ----------------------------------------------------------------------------------------------------------------------
# Si toglie: commenti, a capo e spazi doppi, spazi attorno a «{ } ; ,» e dopo «:», l'ultimo «;» prima di «}». Stringhe e url(...) restano intatti.
sub css_min {
	my ($s) = @_;
	die "url() senza apici: non gestito\n" if $s =~ m{url\(\s*[^'")\s]}i;
	my $o   = '';
	my $sp  = 0;
	pos($s) = 0;
	my $emit = sub {
		my ( $t, $first ) = @_;
		if ( $sp && $o ne '' && $o !~ /[{};,:(]\z/ && $first !~ /^[{};,)]/ ) { $o .= ' '; }
		$sp = 0;
		$o .= $t;
	};
	while ( pos($s) < length $s ) {
		next if $s =~ /\G\/\*.*?\*\//gcs;
		if ( $s =~ /\G\s+/gc ) { $sp = 1; next; }
		if ( $s =~ /\G(?:'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*")/gcs ) { $emit->( "$&", '"' ); next; }
		if ( $s =~ /\G\}/gc ) { $o =~ s/;\z//; $sp = 0; $o .= '}'; next; }
		if ( $s =~ /\G([^\s{};,'"\/]+|\/)/gcs ) { $emit->( $1, substr( $1, 0, 1 ) ); next; }
		$s =~ /\G(.)/gcs;
		$emit->( $1, $1 );
	}
	return $o . "\n";
}

sub css_signature {
	my ($s) = @_;
	$s =~ s/\/\*.*?\*\///gs;
	$s =~ s/\s+//g;
	$s =~ s/;\}/}/g;
	return $s;
}

my ( $min, $ok );
if ( $kind eq 'js' ) {
	$min = js_tokens( $src, 1 );
	$min =~ s/^\n+//;
	$min =~ s/\n*\z/\n/;
	$ok = js_signature($src) eq js_signature($min) && js_tokens( $min, 1 ) eq $min;
} else {
	$min = css_min($src);
	$ok  = css_signature($src) eq css_signature($min) && css_min($min) eq $min;
	# Le stesse parentesi prima e dopo (fuori dalle stringhe, che qui non si distinguono: basta che i conti tornino).
	for my $c ( '{', '}', '(', ')' ) {
		( my $plain = $src ) =~ s{/\*.*?\*/}{}gs; # i commenti non contano
		my $n_src = () = $plain =~ m{\Q$c\E}g;
		my $n_min = () = $min =~ m{\Q$c\E}g;
		$ok = 0 if $n_src != $n_min;
	}
}
if ( ! $ok && $ENV{ASEM_MINIFY_DEBUG} ) {
	open( my $dh, '>:raw', $ENV{ASEM_MINIFY_DEBUG} ) or die;
	print {$dh} $min;
	close $dh;
}
die "controllo di coerenza non superato per $in\n" unless $ok;
die "il file ridotto non è più piccolo di $in\n" unless length $min < length $src;
open( my $oh, '>:raw', $out ) or die "non si scrive $out\n";
print {$oh} $min;
close $oh;
printf "%s: %d -> %d byte\n", $in, length $src, length $min;
