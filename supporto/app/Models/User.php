<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Email di recupero password in italiano. */
    public function sendPasswordResetNotification($token): void
    {
        ResetPassword::toMailUsing(fn ($notifiable, $token) => (new MailMessage)
            ->subject('Recupero password · Associazione Semplice Supporto')
            ->greeting('Ciao,')
            ->line('Hai chiesto di scegliere una nuova password per il pannello di supporto di Associazione Semplice.')
            ->action('Scegli la nuova password', route('password.reset', ['token' => $token, 'email' => $notifiable->email]))
            ->line('Il link vale 60 minuti. Se non sei stato tu, ignora questa email: la password non cambia.')
            ->salutation('Associazione Semplice'));

        $this->notify(new ResetPassword($token));
    }

    public function isAdmin(): bool
    {
        return $this->global_role === 'admin';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'sso_managed' => 'boolean',
        ];
    }
}
