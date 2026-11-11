<?php

namespace App\Console\Commands;

use App\Services\Staff\StaffJwt;
use Illuminate\Console\Command;

class StaffKey extends Command
{
    protected $signature = 'staff:key {--force : Sostituisce la chiave esistente}';

    protected $description = 'Crea la chiave con cui il supporto firma le richieste al portale. Stampa solo kid e chiave pubblica: la privata resta nel file.';

    public function handle(): int
    {
        $file = (string) config('support.staff_sso.private_key_file');
        if (is_file($file) && ! $this->option('force')) {
            $this->error('La chiave esiste già: con --force la sostituisci (il portale va aggiornato con la nuova chiave pubblica).');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $kid = 'supporto-'.bin2hex(random_bytes(4));
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, json_encode(['kid' => $kid, 'd' => StaffJwt::b64(sodium_crypto_sign_secretkey($pair))]));
        @chmod($file, 0600);

        $this->line('kid: '.$kid);
        $this->line('chiave pubblica (x): '.StaffJwt::b64(sodium_crypto_sign_publickey($pair)));
        $this->comment('La chiave privata è nel file indicato in config e non va mai copiata altrove né inserita nel repository.');

        return self::SUCCESS;
    }
}
