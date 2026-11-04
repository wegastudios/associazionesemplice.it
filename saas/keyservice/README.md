# Servizio chiavi

Custodisce la chiave principale del SaaS e cifra o decifra le chiavi dati delle associazioni. Gira in un **account
cPanel separato** da quello del SaaS, così un dump del database o dei file del SaaS, da solo, non apre nessun dato
cifrato.

- `public/index.php`: unico file nella cartella pubblica (document root del sottodominio, solo HTTPS).
- `src/`: protocollo (firma Ed25519, ora, nonce), cifratura delle chiavi; nessuna dipendenza esterna, PHP 8.3 con sodium.
- `config.ini` (da `config.ini.example`, permessi 600) e `storage/` (registro e nonce) stanno fuori dalla cartella
  pubblica.

Il SaaS usa la stessa classe `KeyWrap` per il ripiego locale (`SAAS_KEY_VAULT=local`). I test sono in
`tests/Unit/KeyService`. Dettagli in [../docs/DATABASE.md](../docs/DATABASE.md) §5 e
[../docs/SVILUPPO.md](../docs/SVILUPPO.md).
