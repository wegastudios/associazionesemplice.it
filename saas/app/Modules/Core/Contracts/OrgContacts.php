<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Contatti della segreteria (email e telefono, in «ente.email» e «ente.phone»). Sono obbligatori: senza, l'associazione
 * non comunica con i soci in nessun modo (email, ricevute, rimborsi, accesso all'area soci) e il gestionale lo ricorda.
 */
final readonly class OrgContacts
{
    public function __construct(private OrgSettings $settings) {}

    public function email(): ?string
    {
        return $this->text('ente.email');
    }

    public function phone(): ?string
    {
        return $this->text('ente.phone');
    }

    /** Ci sono tutti e due? Solo allora si può scrivere ai soci. */
    public function complete(): bool
    {
        return $this->email() !== null && $this->phone() !== null;
    }

    /** @return array{email: string, phone: string}|null i contatti, o null se ne manca uno */
    public function all(): ?array
    {
        $email = $this->email();
        $phone = $this->phone();

        return $email !== null && $phone !== null ? ['email' => $email, 'phone' => $phone] : null;
    }

    /** Riga da mettere in fondo alle email e alle pagine dei soci. */
    public static function line(string $email, string $phone): string
    {
        return 'Segreteria: '.$email.' · tel. '.$phone;
    }

    private function text(string $key): ?string
    {
        $v = $this->settings->get($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
