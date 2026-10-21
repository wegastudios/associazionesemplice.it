<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AdminCreate extends Command
{
    protected $signature = 'wega:admin:create {email : Email di accesso} {name=Amministratore : Nome}
        {--reset : Se l\'utente esiste, genera una nuova password}';

    protected $description = 'Crea (o promuove) un amministratore del pannello e genera una password, mostrata una sola volta';

    public function handle(): int
    {
        $email = Str::lower($this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $this->option('reset') && $user->isAdmin()) {
            $this->error('Amministratore gia esistente. Usa --reset per generare una nuova password.');

            return self::FAILURE;
        }

        $password = Str::password(24, symbols: false);

        $user ??= new User(['name' => $this->argument('name'), 'email' => $email]);
        $user->password = $password;
        $user->forceFill(['global_role' => 'admin'])->save();

        $this->warn('Password mostrata UNA SOLA VOLTA: conservala nel gestore di password.');
        $this->line('Email:    '.$email);
        $this->line('Password: '.$password);
        $this->comment('MFA non ancora disponibile: da attivare prima della produzione.');

        return self::SUCCESS;
    }
}
