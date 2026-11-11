<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accesso unico dello staff (docs/UTENTI_E_ACCESSI.md): il ruolo «sales» è il livello «vendita» del portale (catalogo,
 * prezzi, promo; non gli utenti), e i token `staff_sso` sono monouso: l'identificativo (`jti`) si ricorda fino alla
 * scadenza, così lo stesso token non apre due sessioni. Aggiunta di una tabella e di un valore: la versione
 * precedente continua a funzionare con lo schema nuovo (REGOLE_DI_LAVORO, migrazioni in due tempi).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.platform_staff DROP CONSTRAINT platform_staff_role_check');
        DB::statement("ALTER TABLE core.platform_staff ADD CONSTRAINT platform_staff_role_check CHECK (role IN ('admin', 'sales', 'readonly'))");

        DB::statement(<<<'SQL'
            CREATE TABLE core.platform_sso_tokens (
                jti        text        PRIMARY KEY CHECK (length(jti) BETWEEN 1 AND 100),
                expires_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.platform_sso_tokens', ['SELECT', 'INSERT', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.platform_sso_tokens');
        DB::statement('ALTER TABLE core.platform_staff DROP CONSTRAINT platform_staff_role_check');
        DB::statement("ALTER TABLE core.platform_staff ADD CONSTRAINT platform_staff_role_check CHECK (role IN ('admin', 'readonly'))");
    }
};
