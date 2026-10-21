<?php

namespace App\Console\Commands;

use App\Services\Hub\SiteService;
use App\Services\Hub\SupportSettings;
use App\Support\Actor;
use Illuminate\Console\Command;

class SiteCreate extends Command
{
    protected $signature = 'wega:site:create {name : Nome del portale}
        {portal_email : Email del portale (avvisata per le priorita 3 e 4)}
        {--url= : Indirizzo del sito di supporto (assente per un portale demo senza sito)}
        {--priorities=0,1,2 : Livelli di priorita consentiti, separati da virgola}
        {--no-reopen : Non consente al portale di riaprire i ticket chiusi}
        {--notify= : Altri indirizzi avvisati per le priorita 3 e 4, separati da virgola}';

    protected $description = 'Registra un sito di supporto nell\'hub; token e segreto HMAC sono mostrati una sola volta';

    public function handle(SiteService $sites): int
    {
        $priorities = array_map('intval', array_filter(explode(',', (string) $this->option('priorities')), fn ($v) => $v !== ''));
        if ($priorities === [] || array_diff($priorities, config('support.priority_levels')) !== []) {
            $this->error('Priorita non valide: indica livelli da 0 a 4 separati da virgola.');

            return self::FAILURE;
        }

        $result = $sites->create(
            (string) $this->argument('name'),
            (string) $this->argument('portal_email'),
            $priorities,
            $this->option('url') ?: null,
            SupportSettings::parseEmails((string) $this->option('notify')),
            ! $this->option('no-reopen'),
            Actor::console('wega:site:create'),
        );

        $this->warn('Credenziali mostrate UNA SOLA VOLTA: vanno nel .env del sito di supporto.');
        $this->line('Client ID:           '.$result['site']->client_id);
        $this->line('SUPPORT_HUB_TOKEN:   '.$result['token']);
        $this->line('Segreto HMAC:        '.$result['hmac_secret']);

        return self::SUCCESS;
    }
}
