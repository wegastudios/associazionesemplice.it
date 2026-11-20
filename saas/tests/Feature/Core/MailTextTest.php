<?php

declare(strict_types=1);

use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Contracts\MailText;

// Dati di prova inventati.

it('toglie dal testo libero le sequenze che nelle email diventano collegamenti', function (): void {
    $plain = MailText::plain('[Apri](https://esempio.test) <b>Circolo</b> www.esempio.test mario@esempio.test');

    expect($plain)->not->toContain('[')->and($plain)->not->toContain(']')->and($plain)->not->toContain('<')->and($plain)->not->toContain('://')
        ->and($plain)->not->toMatch('/www\./i')->and($plain)->not->toContain('mario@')
        ->and(MailText::plain('Polisportiva Alfa (Roma)'))->toBe('Polisportiva Alfa (Roma)');
});

it('nell\'invito agli operatori il saluto è neutro e il nome dell\'associazione non diventa un link', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $user->name = '[clicca qui](https://esempio.test)';

    $mail = (new InvitationNotification('[Apri](https://esempio.test) Circolo www.esempio.test', 'token-di-prova'))->toMail($user);
    $html = (string) $mail->render();

    expect($mail->greeting)->toBe('Ciao,')->and($mail->subject)->not->toContain('[')
        ->and($html)->not->toContain('clicca qui')->and($html)->not->toMatch('/href="[^"]*esempio\.test/i');
});
