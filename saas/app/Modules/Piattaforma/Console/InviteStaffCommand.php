<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Contracts\QueuedMail;
use App\Modules\Piattaforma\Identity\StaffInvites;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Invita una persona nello staff: le manda per email il link dove sceglie la password (il link non si stampa mai, a meno
 * di --show-link). Ruoli: --role=area:livello, ripetibile (wordpress:admin, saas:admin, supporto:agente…).
 */
final class InviteStaffCommand extends Command
{
    protected $signature = 'saas:staff:invite {email} {name} {--role=* : area:livello, per esempio saas:admin} {--show-link : Stampa il link invece di mandare l\'email}';

    protected $description = 'Invita una persona nello staff (accesso unico): riceve il link per scegliere la password';

    public function handle(StaffInvites $invites): int
    {
        $roles = [];
        foreach ((array) $this->option('role') as $pair) {
            [$area, $level] = array_pad(explode(':', is_string($pair) ? $pair : '', 2), 2, '');
            $roles[$area] = $level;
        }

        try {
            $code = $invites->create((string) $this->argument('email'), (string) $this->argument('name'), $roles);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $base = config('saas.staff_issuer.invite_base');
        $link = rtrim(is_string($base) && $base !== '' ? $base : url('/saas'), '/').'/staff/invito/'.$code;

        if ($this->option('show-link') === true) {
            $this->line($link);

            return self::SUCCESS;
        }

        $to = mb_strtolower(trim((string) $this->argument('email')));
        // Subito se c'è quota, altrimenti in coda (cifrato) finché l'invito vale.
        try {
            $now = MailQueue::sendNowOrQueue(QueuedMail::raw($to, 'Il tuo invito nello staff di Associazione Semplice',
                "Ciao,\n\nsei stato invitato nello staff di Associazione Semplice. Scegli la tua password da questo link (vale ".StaffInvites::VALID_DAYS." giorni e si usa una volta sola):\n\n{$link}\n\nSe non te lo aspettavi, ignora questa email.\n",
                MailQueue::URGENT, now()->addDays(StaffInvites::VALID_DAYS)));
        } catch (Throwable $e) {
            $this->error('Invio non riuscito: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($now ? "Invito mandato a {$to}." : "Invito in coda per {$to}: la posta in uscita è in pausa o al limite dell'ora, parte da solo appena possibile.");

        return self::SUCCESS;
    }
}
