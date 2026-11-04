<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contabilità in partita singola (DATABASE.md §6): conti, categorie, esercizi, ricevute, prima nota.
 *
 * La prima nota si protegge nel database, non solo nel codice:
 * - il ruolo dell'applicazione ha solo INSERT e SELECT su movimenti e ricevute;
 * - lo storno passa da app.void_transaction() (SECURITY DEFINER), che scrive solo voided_at, void_reason e voided_by;
 * - un trigger rifiuta movimenti fuori da un esercizio aperto o su un conto chiuso, e qualunque altra modifica;
 * - i saldi (app.account_balances) li aggiornano i trigger, nella stessa transazione: l'applicazione li legge soltanto.
 *
 * Le funzioni girano con il ruolo proprietario, che è soggetto alla Row-Level Security forzata: anche loro vedono
 * solo l'associazione impostata in app.org_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = OrgSchema::appRole();

        DB::statement(<<<'SQL'
            CREATE TABLE app.accounts (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                id            uuid        NOT NULL,
                name          text        NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                type          text        NOT NULL CHECK (type IN ('cash', 'bank', 'pos', 'other')),
                opening_cents bigint      NOT NULL DEFAULT 0,
                sort_order    integer     NOT NULL DEFAULT 0,
                closed_at     timestamptz,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id)
            )
        SQL);
        OrgSchema::isolate('app.accounts');
        OrgSchema::grant('app.accounts', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.account_balances (
                org_id        uuid        NOT NULL,
                account_id    uuid        NOT NULL,
                balance_cents bigint      NOT NULL,
                updated_at    timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, account_id),
                FOREIGN KEY (org_id, account_id) REFERENCES app.accounts(org_id, id)
            )
        SQL);
        OrgSchema::isolate('app.account_balances');
        OrgSchema::grant('app.account_balances', ['SELECT']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.categories (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                id           uuid        NOT NULL,
                name         text        NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
                kind         text        NOT NULL CHECK (kind IN ('membership', 'activity_fee', 'donation', 'other_income',
                                                                  'member_reimbursement', 'activity_cost', 'general_cost', 'adjustment')),
                fiscal_group text        CHECK (length(fiscal_group) <= 160),
                sort_order   integer     NOT NULL DEFAULT 0,
                deleted_at   timestamptz,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id)
            )
        SQL);
        OrgSchema::isolate('app.categories');
        OrgSchema::grant('app.categories', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.fiscal_years (
                org_id     uuid        NOT NULL REFERENCES app.orgs(id),
                year       smallint    NOT NULL CHECK (year BETWEEN 2000 AND 2100),
                starts_on  date        NOT NULL,
                ends_on    date        NOT NULL,
                status     text        NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
                closed_at  timestamptz,
                closed_by  uuid,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, year),
                CHECK (ends_on > starts_on AND ends_on < starts_on + interval '13 months'),
                CHECK ((status = 'closed') = (closed_at IS NOT NULL))
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX fiscal_years_starts ON app.fiscal_years (org_id, starts_on)');
        OrgSchema::isolate('app.fiscal_years');
        OrgSchema::grant('app.fiscal_years', ['SELECT', 'INSERT']);
        // Chiudere e riaprire: solo queste colonne.
        DB::statement("GRANT UPDATE (status, closed_at, closed_by) ON app.fiscal_years TO {$app}");

        DB::statement(<<<'SQL'
            CREATE TABLE app.counters (
                org_id     uuid     NOT NULL REFERENCES app.orgs(id),
                kind       text     NOT NULL CHECK (kind ~ '^[a-z_]{1,30}$'),
                year       smallint NOT NULL,
                last_value integer  NOT NULL CHECK (last_value >= 0),
                PRIMARY KEY (org_id, kind, year)
            )
        SQL);
        OrgSchema::isolate('app.counters');
        OrgSchema::grant('app.counters', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.receipts (
                org_id          uuid        NOT NULL REFERENCES app.orgs(id),
                id              uuid        NOT NULL,
                year            smallint    NOT NULL,
                number          integer     NOT NULL CHECK (number > 0),
                issued_on       date        NOT NULL,
                payer_person_id uuid,
                total_cents     bigint      NOT NULL CHECK (total_cents > 0),
                voided_at       timestamptz,
                void_reason     text,
                created_by      uuid,
                created_at      timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, year, number),
                FOREIGN KEY (org_id, payer_person_id) REFERENCES app.people(org_id, id),
                CHECK ((voided_at IS NULL) = (void_reason IS NULL))
            )
        SQL);
        DB::statement('CREATE INDEX receipts_payer ON app.receipts (org_id, payer_person_id)');
        OrgSchema::isolate('app.receipts');
        OrgSchema::grant('app.receipts', ['SELECT', 'INSERT']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.transactions (
                org_id           uuid        NOT NULL REFERENCES app.orgs(id),
                id               uuid        NOT NULL,
                tx_date          date        NOT NULL,
                fiscal_year      smallint    NOT NULL,
                type             text        NOT NULL CHECK (type IN ('income', 'expense', 'transfer_in', 'transfer_out')),
                amount_cents     bigint      NOT NULL CHECK (amount_cents > 0 AND amount_cents <= 100000000000),
                account_id       uuid        NOT NULL,
                method           text        NOT NULL CHECK (method IN ('cash', 'bank_transfer', 'pos', 'check', 'stripe', 'paypal', 'sumup', 'satispay', 'other')),
                category_id      uuid        NOT NULL,
                person_id        uuid,
                receipt_id       uuid,
                transfer_id      uuid,
                competence_month char(7)     CHECK (competence_month ~ '^\d{4}-(0[1-9]|1[0-2])$'),
                description      text        NOT NULL DEFAULT '' CHECK (length(description) <= 255),
                document_ref     text        CHECK (length(document_ref) <= 80),
                vat_rate         smallint    CHECK (vat_rate BETWEEN 0 AND 30),
                vat_cents        bigint      NOT NULL DEFAULT 0 CHECK (vat_cents >= 0 AND vat_cents <= amount_cents),
                voided_at        timestamptz,
                void_reason      text        CHECK (length(void_reason) <= 255),
                voided_by        uuid,
                created_by       uuid,
                created_at       timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, account_id)  REFERENCES app.accounts(org_id, id),
                FOREIGN KEY (org_id, category_id) REFERENCES app.categories(org_id, id),
                FOREIGN KEY (org_id, person_id)   REFERENCES app.people(org_id, id),
                FOREIGN KEY (org_id, receipt_id)  REFERENCES app.receipts(org_id, id),
                FOREIGN KEY (org_id, fiscal_year) REFERENCES app.fiscal_years(org_id, year),
                CHECK ((type IN ('transfer_in', 'transfer_out')) = (transfer_id IS NOT NULL)),
                CHECK (receipt_id IS NULL OR type = 'income'),
                CHECK ((voided_at IS NULL) = (void_reason IS NULL))
            )
        SQL);
        DB::statement('CREATE INDEX transactions_account ON app.transactions (org_id, account_id, tx_date)');
        DB::statement('CREATE INDEX transactions_category ON app.transactions (org_id, category_id)');
        DB::statement('CREATE INDEX transactions_person ON app.transactions (org_id, person_id) WHERE person_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_receipt ON app.transactions (org_id, receipt_id) WHERE receipt_id IS NOT NULL');
        DB::statement('CREATE INDEX transactions_year ON app.transactions (org_id, fiscal_year, tx_date)');
        DB::statement('CREATE INDEX transactions_transfer ON app.transactions (org_id, transfer_id) WHERE transfer_id IS NOT NULL');
        OrgSchema::isolate('app.transactions');
        OrgSchema::grant('app.transactions', ['SELECT', 'INSERT']);

        $this->functions($app);
    }

    private function functions(string $app): void
    {
        // Saldo iniziale e sue correzioni.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.account_balance_sync() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    INSERT INTO app.account_balances (org_id, account_id, balance_cents) VALUES (NEW.org_id, NEW.id, NEW.opening_cents);
                ELSIF NEW.opening_cents <> OLD.opening_cents THEN
                    UPDATE app.account_balances SET balance_cents = balance_cents + (NEW.opening_cents - OLD.opening_cents), updated_at = now()
                    WHERE org_id = NEW.org_id AND account_id = NEW.id;
                END IF;
                RETURN NULL;
            END
            $$;
            CREATE TRIGGER accounts_balance AFTER INSERT OR UPDATE OF opening_cents ON app.accounts
                FOR EACH ROW EXECUTE FUNCTION app.account_balance_sync();
        SQL);

        // Prima di inserire un movimento: esercizio aperto e data al suo interno, conto aperto, nessuno storno già fatto.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.transaction_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                fy app.fiscal_years%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'I movimenti della prima nota non si cancellano: si stornano.';
                END IF;

                SELECT * INTO fy FROM app.fiscal_years WHERE org_id = NEW.org_id AND year = NEW.fiscal_year;
                IF NOT FOUND OR NEW.tx_date < fy.starts_on OR NEW.tx_date > fy.ends_on THEN
                    RAISE EXCEPTION 'La data del movimento non appartiene all''esercizio indicato.';
                END IF;
                IF fy.status <> 'open' THEN
                    RAISE EXCEPTION 'L''esercizio % è chiuso: riaprilo per registrare o stornare movimenti.', fy.year;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Un movimento nuovo non può essere già stornato.';
                    END IF;
                    IF EXISTS (SELECT 1 FROM app.accounts WHERE org_id = NEW.org_id AND id = NEW.account_id AND closed_at IS NOT NULL) THEN
                        RAISE EXCEPTION 'Il conto è chiuso: riaprilo per registrare movimenti.';
                    END IF;
                    RETURN NEW;
                END IF;

                -- UPDATE: solo lo storno, una volta sola, e nient'altro.
                IF OLD.voided_at IS NOT NULL OR NEW.voided_at IS NULL
                   OR (to_jsonb(NEW) - 'voided_at' - 'void_reason' - 'voided_by') <> (to_jsonb(OLD) - 'voided_at' - 'void_reason' - 'voided_by') THEN
                    RAISE EXCEPTION 'I movimenti della prima nota non si modificano: si stornano.';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER transactions_guard BEFORE INSERT OR UPDATE OR DELETE ON app.transactions
                FOR EACH ROW EXECUTE FUNCTION app.transaction_guard();
        SQL);

        // Saldi: ogni movimento li aggiorna, ogni storno li riporta indietro.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.transaction_balance() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                delta bigint;
            BEGIN
                delta := CASE WHEN NEW.type IN ('income', 'transfer_in') THEN NEW.amount_cents ELSE -NEW.amount_cents END;
                IF TG_OP = 'UPDATE' THEN
                    delta := -delta;
                END IF;
                UPDATE app.account_balances SET balance_cents = balance_cents + delta, updated_at = now()
                WHERE org_id = NEW.org_id AND account_id = NEW.account_id;
                RETURN NULL;
            END
            $$;
            CREATE TRIGGER transactions_balance AFTER INSERT OR UPDATE OF voided_at ON app.transactions
                FOR EACH ROW EXECUTE FUNCTION app.transaction_balance();
        SQL);

        // Storno: tutto il gruppo insieme (le due righe di un giroconto, tutte le voci di una ricevuta e la ricevuta).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.void_transaction(p_id uuid, p_reason text, p_by uuid) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                org uuid := NULLIF(current_setting('app.org_id', true), '')::uuid;
                tx app.transactions%ROWTYPE;
                reason text := left(coalesce(NULLIF(btrim(p_reason), ''), 'Stornato'), 255);
                n integer;
            BEGIN
                SELECT * INTO tx FROM app.transactions WHERE org_id = org AND id = p_id FOR UPDATE;
                IF NOT FOUND OR tx.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Movimento inesistente o già stornato.';
                END IF;

                UPDATE app.transactions SET voided_at = now(), void_reason = reason, voided_by = p_by
                WHERE org_id = org AND voided_at IS NULL AND (
                    id = p_id
                    OR (tx.transfer_id IS NOT NULL AND transfer_id = tx.transfer_id)
                    OR (tx.receipt_id IS NOT NULL AND receipt_id = tx.receipt_id)
                );
                GET DIAGNOSTICS n = ROW_COUNT;

                IF tx.receipt_id IS NOT NULL THEN
                    UPDATE app.receipts SET voided_at = now(), void_reason = reason WHERE org_id = org AND id = tx.receipt_id;
                END IF;

                RETURN n;
            END
            $$;
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION app.void_transaction(uuid, text, uuid) FROM PUBLIC');
        DB::statement("GRANT EXECUTE ON FUNCTION app.void_transaction(uuid, text, uuid) TO {$app}");

        foreach (['account_balance_sync()', 'transaction_guard()', 'transaction_balance()'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION app.{$function} FROM PUBLIC");
        }
    }

    public function down(): void
    {
        foreach (['transactions', 'receipts', 'counters', 'fiscal_years', 'categories', 'account_balances', 'accounts'] as $table) {
            DB::statement("DROP TABLE app.{$table}");
        }
        foreach (['void_transaction(uuid, text, uuid)', 'transaction_balance()', 'transaction_guard()', 'account_balance_sync()'] as $function) {
            DB::statement("DROP FUNCTION app.{$function}");
        }
    }
};
