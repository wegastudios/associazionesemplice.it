<?php

declare(strict_types=1);

namespace App\Modules\Soci\Import;

use App\Modules\Documenti\Contracts\Text;
use App\Modules\Soci\Contracts\MemberType;

/**
 * Un socio (non ospite) per tessera, email o nome e cognome, se ce n'è uno solo, in un elenco caricato una volta: gli
 * import cercano ospitanti e tutori in memoria, senza scorrere tutta l'anagrafica a ogni riga (H10).
 *
 * @phpstan-import-type Existing from PeopleSheet
 */
final class PeopleFinder
{
    /** @var array<string, array<string, true>> chiave («c:», «e:», «n:» + valore) => id delle schede */
    private array $index = [];

    /** @param  list<Existing>  $known */
    public function __construct(array $known)
    {
        foreach ($known as $p) {
            if (($p['type'] ?? '') === MemberType::Guest->value) {
                continue;
            }
            if ($p['card'] !== null) {
                $this->index['c:'.Text::lower($p['card'])][$p['id']] = true;
            }
            if ($p['email'] !== null) {
                $this->index['e:'.Text::lower($p['email'])][$p['id']] = true;
            }
            $this->index['n:'.Text::normalize($p['first'].$p['last'])][$p['id']] = true;
            $this->index['n:'.Text::normalize($p['last'].$p['first'])][$p['id']] = true;
        }
    }

    public function find(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $found = ($this->index['c:'.Text::lower(PeopleSheet::cleanCard($text))] ?? [])
            + ($this->index['e:'.Text::lower($text)] ?? [])
            + ($this->index['n:'.Text::normalize($text)] ?? []);

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }
}
