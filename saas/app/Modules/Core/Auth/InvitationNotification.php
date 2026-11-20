<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use App\Modules\Core\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Invito a un'associazione. Chi non ha ancora un utente riceve il link per scegliere la password; chi ce l'ha
 * già entra con le sue credenziali. Nessun dato dei soci nel messaggio.
 */
final class InvitationNotification extends Notification
{
    public function __construct(
        public readonly string $orgName,
        #[SensitiveParameter] public readonly ?string $token,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("{$this->orgName} · Invito a lavorare nel gestionale")
            ->greeting("Ciao {$notifiable->name},")
            ->line("ti hanno invitato a lavorare nel gestionale di {$this->orgName}.");

        if ($this->token === null) {
            return $message
                ->line('Accedi con la tua email e la tua password di sempre.')
                ->action('Accedi', AppLink::route('login'));
        }

        return $message
            ->line('Per iniziare scegli la tua password. Il link vale 60 minuti; se scade, usa «Password dimenticata».')
            ->action('Scegli la password', AppLink::route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('Al primo accesso dovrai attivare la verifica in due passaggi.');
    }
}
