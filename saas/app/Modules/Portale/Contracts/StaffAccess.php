<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

/**
 * Accesso unico dello staff (docs/UTENTI_E_ACCESSI.md): il portale è l'unico posto dove si entra, il SaaS riceve un
 * token firmato e apre una sessione propria. Il modulo Piattaforma usa solo questa interfaccia.
 */
interface StaffAccess
{
    /** Livelli dell'area SaaS decisi dal portale. */
    public const LEVELS = ['admin', 'vendita', 'supporto'];

    /** L'accesso con il portale è acceso (SAAS_STAFF_SSO). Spento, resta l'accesso locale. */
    public function enabled(): bool;

    /** Dove mandare chi non ha sessione: l'accesso staff del portale, con ritorno alla sola area SaaS. */
    public function loginUrl(): string;

    /**
     * Controlla firma, destinatario, tipo, scadenza e contenuto del token `staff_sso`. Il monouso (`jti`) lo applica
     * chi apre la sessione.
     *
     * @throws StaffAccessDenied
     */
    public function verify(string $token): StaffGrant;

    /**
     * Chiede al portale se la sessione centrale è ancora valida: il livello attuale, o null se è revocata.
     *
     * @return string|null uno di LEVELS
     *
     * @throws StaffAccessDenied se il portale non risponde o la risposta non è valida
     */
    public function check(string $sid): ?string;
}
