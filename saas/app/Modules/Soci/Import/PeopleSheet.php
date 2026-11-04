<?php

declare(strict_types=1);

namespace App\Modules\Soci\Import;

use App\Modules\Documenti\Contracts\SheetReader;
use App\Modules\Documenti\Contracts\Text;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Rules\Age;
use App\Modules\Soci\Rules\Phone;

/**
 * Lettura dei fogli di import soci e piano di importazione (nuovo / aggiorna / errore), dal PeopleCsv del plugin.
 * Codice puro: non scrive nel database.
 *
 * @phpstan-type Row array{line: int, sheet: string, card: string|null, type_text: string|null, first: string, last: string, email: string|null, phone: string|null, tax: string|null, addr: string|null, zip: string|null, city: string|null, prov: string|null, years: list<string>, prior: int|null, notes: string|null, since: string|null, host: string|null, birth: string|null, birth_text: string|null, guardian: string|null}
 * @phpstan-type Existing array{id: string, card: string|null, first: string, last: string, email: string|null, tax: string|null, phone?: string|null, type?: string, host_id?: string|null}
 * @phpstan-type Plan array{row: Row, type: string|null, type_given: bool, action: 'create'|'update'|'error', message: string|null, matched_id: string|null, host_text: string|null, host_id: string|null}
 */
final class PeopleSheet
{
    public const TEMPLATE = "Numero tessera;Tipo;Nome;Cognome;Email;Cellulare;Codice fiscale;Data di nascita;Tutore\r\n1;ordinario;Mario;Rossi;mario.rossi@example.com;3331234567;RSSMRA80A01H501U;01/01/1980;\r\n";

    /** Nomi riconosciuti per ogni colonna (intestazioni normalizzate). */
    public const COLS = [
        'card' => ['tessera', 'numerotessera', 'ntessera', 'nrtessera', 'numtessera', 'nrtess', 'numero', 'n', 'nr'],
        'type' => ['tipo', 'tiposocio', 'categoria', 'qualifica', 'figura'],
        'first' => ['nome', 'firstname', 'name'],
        'last' => ['cognome', 'surname', 'lastname'],
        'mail' => ['email', 'mail', 'emailaddress', 'indirizzoemail', 'postaelettronica'],
        'phone' => ['telefono', 'ntelefono', 'numerotelefono', 'ncellulare', 'tel', 'cellulare', 'cell', 'mobile', 'phone', 'telefonocellulare'],
        'tax' => ['codicefiscale', 'cf', 'codfisc', 'codicefisc', 'fiscalcode'],
        'addr' => ['indirizzo', 'via', 'residenza', 'address'],
        'zip' => ['cap', 'codicepostale', 'zip', 'postalcode'],
        'city' => ['comune', 'citta', 'localita', 'city'],
        'prov' => ['provincia', 'prov', 'province'],
        'prior' => ['voltevenuto', 'venute', 'venuto', 'voltegiavenuto', 'giavenuto', 'presenze', 'visite', 'partecipazioni', 'volte'],
        'notes' => ['note', 'eventipassati', 'eventi', 'storico', 'annotazioni'],
        'since' => ['annodiiscrizione', 'annoiscrizione', 'datadiiscrizione', 'dataiscrizione', 'iscrittodal', 'sociodal', 'dataingresso', 'ingresso', 'datadiingresso'],
        'host' => ['ospitedi', 'ospitante', 'socioospitante', 'invitatoda', 'ospiteda', 'tesseraospitante', 'host'],
        'birth' => ['datadinascita', 'datanascita', 'nascita', 'natoil', 'natail', 'nata', 'nato', 'dob', 'birthdate', 'dateofbirth'],
        'guardian' => ['tutore', 'genitore', 'tutoregenitore', 'genitoretutore', 'tutoreogenitore', 'genitoreotutore', 'tutori', 'genitori', 'guardian', 'parent'],
    ];

    /** Tipo di persona da come lo scrive un foglio. */
    public static function typeFromText(string $text): ?MemberType
    {
        return match (Text::normalize($text)) {
            'fondatore', 'sociofondatore', 'fondatrice' => MemberType::Founder,
            'ordinario', 'socioordinario', 'ordinaria', 'socio' => MemberType::Ordinary,
            'volontario', 'volontaria', 'sociovolontario', 'socioevolontario', 'socievolontari', 'sociaevolontaria' => MemberType::Volunteer,
            'ospite', 'ospitediunsocio' => MemberType::Guest,
            'genitore', 'tutore', 'genitoreotutore', 'genitoreotutorenonsocio' => MemberType::Guardian,
            default => null,
        };
    }

    public static function decode(string $bytes): string
    {
        return SheetReader::decode($bytes);
    }

    /** @param  list<string>  $h */
    public static function acceptsHeader(array $h): bool
    {
        return SheetReader::col($h, self::COLS['first']) >= 0 && SheetReader::col($h, self::COLS['last']) >= 0;
    }

    /** @return array{error: string}|array{rows: list<Row>} */
    public static function parse(string $text): array
    {
        $table = self::readTable($text);

        return $table === [] ? ['error' => 'Il file è vuoto.'] : self::parseTable($table);
    }

    /**
     * @param  list<list<string>>  $table  righe di celle (l'intestazione può non essere la prima)
     * @param  list<int>|null  $lines  numero di riga originale di ogni riga
     * @return array{error: string}|array{rows: list<Row>}
     */
    public static function parseTable(array $table, ?array $lines = null, string $sheet = ''): array
    {
        if ($table === []) {
            return ['error' => 'Il file è vuoto.'];
        }
        $hi = SheetReader::findHeader($table, self::acceptsHeader(...));
        if ($hi === null) {
            return ['error' => 'Nella prima riga servono almeno le colonne «Nome» e «Cognome». Trovate: '.mb_substr(implode(', ', $table[0]), 0, 200)];
        }
        $header = SheetReader::normalizeHeader($table[$hi]);
        $col = array_map(static fn (array $names): int => SheetReader::col($header, $names), self::COLS);
        // Colonne con un anno come titolo (2024, 2025…): un segno qualsiasi dice che il socio era iscritto quell'anno.
        $yearCols = [];
        foreach ($header as $i => $h) {
            if (preg_match('/^(19[5-9]\d|20\d\d)$/', $h) === 1) {
                $yearCols[$i] = $h;
            } elseif ($h === 'annocorrente') {
                $yearCols[$i] = '@current';
            } elseif (in_array($h, ['annoscorso', 'annoprecedente'], true)) {
                $yearCols[$i] = '@previous';
            }
        }
        $cell = static function (array $cells, int $i): ?string {
            $v = $i >= 0 && is_string($cells[$i] ?? null) ? trim($cells[$i]) : '';

            return $v === '' ? null : $v;
        };

        $rows = [];
        foreach ($table as $idx => $cells) {
            if ($idx <= $hi) {
                continue;
            }
            $card = $cell($cells, $col['card']);
            $tax = $cell($cells, $col['tax']);
            $years = [];
            foreach ($yearCols as $yi => $label) {
                if (self::isMarked($cell($cells, $yi))) {
                    $years[] = $label;
                }
            }
            $birthText = $cell($cells, $col['birth']);
            $rows[] = [
                'line' => $lines !== null ? ($lines[$idx] ?? $idx + 1) : $idx + 1,
                'sheet' => $sheet,
                'card' => $card === null ? null : self::cleanCard($card),
                'type_text' => $cell($cells, $col['type']),
                'first' => $cell($cells, $col['first']) ?? '',
                'last' => $cell($cells, $col['last']) ?? '',
                'email' => $cell($cells, $col['mail']),
                'phone' => $cell($cells, $col['phone']),
                'tax' => $tax === null ? null : strtoupper(str_replace(' ', '', $tax)),
                'addr' => $cell($cells, $col['addr']),
                'zip' => $cell($cells, $col['zip']),
                'city' => $cell($cells, $col['city']),
                'prov' => $cell($cells, $col['prov']),
                'years' => $years,
                'prior' => self::parseCount($cell($cells, $col['prior'])),
                'notes' => $cell($cells, $col['notes']),
                'since' => self::parseSince($cell($cells, $col['since'])),
                'host' => $cell($cells, $col['host']),
                'birth' => Age::parse((string) $birthText),
                'birth_text' => $birthText,
                'guardian' => $cell($cells, $col['guardian']),
            ];
        }

        return ['rows' => $rows];
    }

    /** La cella di un anno indica l'iscrizione? Vuota, «no», «0», «-» no; una crocetta, «sì», «1» o altro sì. */
    public static function isMarked(?string $v): bool
    {
        return $v !== null && ! in_array(Text::lower(trim($v)), ['', '0', '-', '–', '—', 'no', 'n', 'non iscritto', 'false'], true);
    }

    /** «3», «3 volte», «3,0» → 3; vuoto o illeggibile → null. */
    public static function parseCount(?string $v): ?int
    {
        return $v !== null && preg_match('/^\s*(\d{1,4})(?:[.,]0+)?\s*(?:volt[ae])?\s*$/iu', $v, $m) === 1 ? (int) $m[1] : null;
    }

    /** Anno di iscrizione: «2019» → 2019-01-01; una data (2019-03-05, 5/3/2019) resta tale; altro → null. */
    public static function parseSince(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        if (preg_match('/^(19[5-9]\d|20\d\d)$/', $v) === 1) {
            return $v.'-01-01';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    /** «123.0» (artefatto di Excel) → «123». */
    public static function cleanCard(string $raw): string
    {
        $raw = trim($raw);

        return preg_match('/^(\d+)[.,]0+$/', $raw, $m) === 1 ? $m[1] : $raw;
    }

    /** @return list<list<string>> */
    public static function readTable(string $text): array
    {
        return SheetReader::readTable($text);
    }

    /**
     * Decide per ogni riga se creare, aggiornare o rifiutare. Una persona già presente si riconosce per tessera, email,
     * cellulare (senza email), codice fiscale, poi nome e cognome se univoci. Gli ospiti si riconoscono per ospitante e nome.
     *
     * @param  list<Row>  $rows
     * @param  list<Existing>  $existing
     * @param  array{guest_host_required?: bool, guest_phone_required?: bool}  $opts
     * @return list<Plan>
     */
    public static function plan(array $rows, array $existing, MemberType $defaultType = MemberType::Ordinary, array $opts = []): array
    {
        $needHost = $opts['guest_host_required'] ?? true;
        $needPhone = $opts['guest_phone_required'] ?? true;
        $byCard = $byMail = $byTax = $byPhone = $byName = $guestsOf = $byNameAll = [];
        foreach ($existing as $e) {
            if (($e['type'] ?? '') === MemberType::Guest->value) {
                $guestsOf[(string) ($e['host_id'] ?? '')][Text::normalize($e['first'].$e['last'])] = $e;

                continue;
            }
            if (($e['card'] ?? '') !== '' && $e['card'] !== null) {
                $byCard[Text::lower($e['card'])] = $e;
            }
            if (($e['email'] ?? '') !== '' && $e['email'] !== null) {
                $byMail[Text::lower($e['email'])] = $e;
            }
            if (($e['tax'] ?? '') !== '' && $e['tax'] !== null) {
                $byTax[strtoupper($e['tax'])] = $e;
            }
            $phone = (string) ($e['phone'] ?? '');
            if ($phone !== '' && Phone::isValid($phone)) {
                $byPhone[Phone::key($phone)] = $e;
            }
            $byName[Text::normalize($e['first']).'|'.Text::normalize($e['last'])][] = $e;
            $byNameAll[Text::normalize($e['first'].$e['last'])][] = $e;
            $byNameAll[Text::normalize($e['last'].$e['first'])][] = $e;
        }
        $label = static fn (array $e): string => trim((is_string($e['first'] ?? null) ? $e['first'] : '').' '.(is_string($e['last'] ?? null) ? $e['last'] : ''));
        $err = static fn (array $r, string $msg): array => ['row' => $r, 'type' => null, 'type_given' => false, 'action' => 'error', 'message' => $msg, 'matched_id' => null, 'host_text' => null, 'host_id' => null];

        $cardsInFile = $mailsInFile = $taxInFile = $touched = $plans = $deferred = [];
        foreach ($rows as $r) {
            if (trim($r['first']) === '' || trim($r['last']) === '') {
                $plans[] = $err($r, 'Nome o cognome mancante');

                continue;
            }
            if ($r['birth_text'] !== null && $r['birth'] === null) {
                $plans[] = $err($r, 'Data di nascita non valida: «'.$r['birth_text'].'» (scrivila come 31/12/2010)');

                continue;
            }
            if ($r['guardian'] !== null && $r['birth'] === null) {
                $plans[] = $err($r, 'Per indicare il tutore serve la data di nascita');

                continue;
            }
            $type = $defaultType;
            if ($r['type_text'] !== null) {
                $type = self::typeFromText($r['type_text']);
                if ($type === null) {
                    $plans[] = $err($r, 'Tipo non riconosciuto: «'.$r['type_text'].'» (usa fondatore, ordinario, volontario, ospite, genitore)');

                    continue;
                }
            }
            if ($type === MemberType::Guest) {
                $deferred[count($plans)] = $r; // si risolve dopo i soci: l'ospitante può essere nello stesso file
                $plans[] = null;

                continue;
            }
            $email = $r['email'];
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $plans[] = $err($r, 'Email non valida: '.$email);

                continue;
            }
            // L'email non è obbligatoria, ma serve un modo per riconoscere la persona.
            if ($email === null && $r['card'] === null && ! Phone::isValid((string) $r['phone'])) {
                $plans[] = $err($r, 'Servono almeno l\'email, il cellulare o il numero di tessera');

                continue;
            }

            $card = $r['card'] === null ? null : Text::lower($r['card']);
            $mail = $email === null ? null : Text::lower($email);
            $tax = $r['tax'];
            if ($card !== null && isset($cardsInFile[$card])) {
                $plans[] = $err($r, 'Tessera '.$r['card'].' già usata alla riga '.$cardsInFile[$card].' del file');

                continue;
            }
            if ($mail !== null && isset($mailsInFile[$mail])) {
                $plans[] = $err($r, 'Email già presente alla riga '.$mailsInFile[$mail].' del file');

                continue;
            }
            if ($tax !== null && isset($taxInFile[$tax])) {
                $plans[] = $err($r, 'Codice fiscale già presente alla riga '.$taxInFile[$tax].' del file');

                continue;
            }

            $cardHolder = $card !== null ? ($byCard[$card] ?? null) : null;
            $mailHolder = $mail !== null ? ($byMail[$mail] ?? null) : null;
            $taxHolder = $tax !== null ? ($byTax[$tax] ?? null) : null;
            $phoneHolder = $mail === null && Phone::isValid((string) $r['phone']) ? ($byPhone[Phone::key((string) $r['phone'])] ?? null) : null;
            $nameKey = Text::normalize($r['first']).'|'.Text::normalize($r['last']);
            $byNameRows = $byName[$nameKey] ?? [];

            // La tessera identifica il socio: se è di un'altra persona non si importa.
            if ($cardHolder !== null && Text::normalize($cardHolder['first']).'|'.Text::normalize($cardHolder['last']) !== $nameKey
                && ($mailHolder === null || $mailHolder['id'] === $cardHolder['id'])) {
                $plans[] = $err($r, 'Tessera '.$r['card'].' già assegnata a '.$label($cardHolder));

                continue;
            }
            $holders = [];
            foreach ([$cardHolder, $mailHolder, $taxHolder, $phoneHolder] as $h) {
                if ($h !== null) {
                    $holders[$h['id']] = $h;
                }
            }
            if (count($holders) > 1) {
                $plans[] = $err($r, 'Tessera, email, cellulare e codice fiscale indicano persone diverse: '.implode(' / ', array_map($label, array_values($holders))));

                continue;
            }
            $match = $holders !== [] ? reset($holders) : null;
            $how = $cardHolder !== null ? 'per tessera' : ($mailHolder !== null ? 'per email' : ($phoneHolder !== null ? 'per cellulare' : 'per codice fiscale'));
            if ($match === null && count($byNameRows) === 1) {
                $match = $byNameRows[0];
                $how = 'per nome e cognome';
            }
            if ($match === null && count($byNameRows) > 1) {
                $plans[] = $err($r, 'Più persone con questo nome: servono tessera o codice fiscale per distinguerle');

                continue;
            }

            if ($match !== null) {
                if (isset($touched[$match['id']])) {
                    $plans[] = $err($r, 'La stessa persona è già aggiornata dalla riga '.$touched[$match['id']]);

                    continue;
                }
                $touched[$match['id']] = $r['line'];
                $msg = 'Già presente ('.$how.')';
                $act = 'update';
            } else {
                $msg = $card === null ? 'Senza tessera: assegnabile dopo' : null;
                $act = 'create';
            }
            if ($card !== null) {
                $cardsInFile[$card] = $r['line'];
            }
            if ($mail !== null) {
                $mailsInFile[$mail] = $r['line'];
            }
            if ($tax !== null) {
                $taxInFile[$tax] = $r['line'];
            }
            if ($r['years'] !== []) {
                $msg = ($msg === null ? '' : $msg.' · ').'Iscritto: '.implode(', ', str_replace(['@current', '@previous'], ['anno corrente', 'anno scorso'], $r['years']));
            }
            $plans[] = ['row' => $r, 'type' => $type->value, 'type_given' => $r['type_text'] !== null, 'action' => $act, 'message' => $msg, 'matched_id' => $match['id'] ?? null, 'host_text' => null, 'host_id' => null];
        }

        if ($deferred !== []) {
            // Soci già presenti e soci del file, per trovare l'ospitante di ogni ospite.
            $fileByCard = $fileByMail = $fileByName = [];
            foreach ($plans as $p) {
                if ($p === null || $p['action'] === 'error') {
                    continue;
                }
                $fr = $p['row'];
                if ($fr['card'] !== null) {
                    $fileByCard[Text::lower($fr['card'])][] = $fr['line'];
                }
                if ($fr['email'] !== null) {
                    $fileByMail[Text::lower($fr['email'])][] = $fr['line'];
                }
                $fileByName[Text::normalize($fr['first'].$fr['last'])][] = $fr['line'];
                $fileByName[Text::normalize($fr['last'].$fr['first'])][] = $fr['line'];
            }
            $guestsInFile = [];
            foreach ($deferred as $idx => $r) {
                $host = $r['host'] === null ? '' : trim($r['host']);
                if ($host === '' && $needHost) {
                    $plans[$idx] = $err($r, 'Ospite senza il socio ospitante (colonna «Ospite di»: tessera, email o nome e cognome)');

                    continue;
                }
                if ($r['email'] !== null && filter_var($r['email'], FILTER_VALIDATE_EMAIL) === false) {
                    $plans[$idx] = $err($r, 'Email non valida: '.$r['email']);

                    continue;
                }
                if (($needPhone || $r['phone'] !== null) && ! Phone::isValid((string) $r['phone'])) {
                    $plans[$idx] = $err($r, 'Cellulare mancante o non valido (per gli ospiti serve a riconoscerli ed evitare doppioni)');

                    continue;
                }
                $guestPlan = static fn (?array $match, ?string $hostId) => [
                    'row' => $r, 'type' => MemberType::Guest->value, 'type_given' => true, 'action' => $match !== null ? 'update' : 'create',
                    'message' => $match !== null ? 'Ospite già presente' : null, 'matched_id' => $match['id'] ?? null,
                    'host_text' => $host, 'host_id' => $hostId,
                ];
                if ($host === '') {
                    $gkey = 'senza|'.Text::normalize($r['first'].$r['last']);
                    if (isset($guestsInFile[$gkey])) {
                        $plans[$idx] = $err($r, 'Lo stesso ospite è già alla riga '.$guestsInFile[$gkey].' del file');

                        continue;
                    }
                    $guestsInFile[$gkey] = $r['line'];
                    $plans[$idx] = $guestPlan($guestsOf[''][Text::normalize($r['first'].$r['last'])] ?? null, null);

                    continue;
                }
                // L'ospitante: tessera, email oppure nome e cognome (in uno dei due ordini).
                $existingIds = [];
                $fileLines = [];
                if (str_contains($host, '@')) {
                    $hm = Text::lower($host);
                    if (isset($byMail[$hm])) {
                        $existingIds[$byMail[$hm]['id']] = true;
                    }
                    $fileLines = $fileByMail[$hm] ?? [];
                } else {
                    $hl = Text::lower(self::cleanCard($host));
                    if (isset($byCard[$hl])) {
                        $existingIds[$byCard[$hl]['id']] = true;
                    }
                    $fileLines = $fileByCard[$hl] ?? [];
                    if ($existingIds === [] && $fileLines === []) {
                        $hn = Text::normalize($host);
                        foreach ($byNameAll[$hn] ?? [] as $e) {
                            $existingIds[$e['id']] = true;
                        }
                        $fileLines = $fileByName[$hn] ?? [];
                    }
                }
                $found = $existingIds !== [] ? count($existingIds) : count(array_unique($fileLines));
                if ($found === 0) {
                    $plans[$idx] = $err($r, 'Socio ospitante non trovato: «'.$host.'»');

                    continue;
                }
                if ($found > 1) {
                    $plans[$idx] = $err($r, 'Socio ospitante ambiguo: «'.$host.'» (indica la tessera)');

                    continue;
                }
                $hostId = $existingIds !== [] ? (string) array_key_first($existingIds) : null;
                $gkey = ($hostId !== null ? 'id'.$hostId : 'riga'.$fileLines[0]).'|'.Text::normalize($r['first'].$r['last']);
                if (isset($guestsInFile[$gkey])) {
                    $plans[$idx] = $err($r, 'Lo stesso ospite è già alla riga '.$guestsInFile[$gkey].' del file');

                    continue;
                }
                $guestsInFile[$gkey] = $r['line'];
                $plans[$idx] = $guestPlan($hostId !== null ? ($guestsOf[$hostId][Text::normalize($r['first'].$r['last'])] ?? null) : null, $hostId);
            }
        }

        /** @var list<Plan> $plans */
        return $plans;
    }
}
