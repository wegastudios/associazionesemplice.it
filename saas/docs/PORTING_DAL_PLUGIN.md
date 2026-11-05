# SaaS: cosa si porta dal plugin

Il plugin è il punto di partenza per le regole, non per il codice di interfaccia. I file sono in
`wordpress/associazionesemplice/includes/`, i test in `wordpress/associazionesemplice/tests/unit/`.

## Come si porta una classe

| Tipo | Come riconoscerlo | Cosa fare |
|---|---|---|
| **Regole pure** | nessuna chiamata a WordPress o al database | si copia nel modulo con i suoi test; cambiano solo namespace e tipi. Si toglie la riga `defined( 'ABSPATH' )` |
| **Servizi** | usano `$wpdb`, opzioni, hook | si riscrivono su Eloquent e sul nuovo schema, tenendo la logica e i casi di test |
| **Da sostituire** | legati a WordPress o a WooCommerce | non si portano: Laravel o il portale fanno lo stesso lavoro |

Le classi elencate come regole pure sono state controllate il 2026-11-04 cercando chiamate a WordPress e a `Db::`: non
ne hanno. Alcune però usano altre classi del plugin (`Rules` e `Fiscal`, per esempio, si appoggiano a `MemberType`,
`Settings`, `Limits` o `Terms`): quelle dipendenze vanno sciolte durante il porting. L'assegnazione a un modulo e i test corrispondenti si basano sui nomi dei file: vanno confermati aprendo ogni
classe. Le regole sono copiate e non condivise ([ARCHITETTURA.md](ARCHITETTURA.md) §2): le correzioni fiscali vanno
riportate nei due progetti.

## Fase 1

| Modulo | Regole pure | Servizi da riscrivere | Test da riusare |
|---|---|---|---|
| **Contabilità** | `Money`, `Fiscal`, `PaymentCalc`, `Pricing`, `FundShare`, `BudgetRules`, `BalanceWatchRules`, `CreditRules`, `RefundRules`, `AttachmentRules`, `SocialYear` | `LedgerService`, `LedgerImport`, `Receipts`, `Statement`, `ReportService`, `FundService`, `FundYears`, `FundReconciliation`, `FiscalYears`, `ExpenseFunds`, `Budget`, `BalanceWatch`, `Bank`, `Discrepancies`, `YearEndCheck`, `Credits`, `Refunds`, `Attachments` | `FiscalTest`, `FundShareTest`, `FundYearsTest`, `BalanceWatchRulesTest`, `CreditRulesTest`, `AttachmentRulesTest`, `LedgerImportTest`, `ReceiptMinorTest`, `BankLimitsTest` |
| **Soci** | `Rules`, `Age`, `TaxCode`, `Phone`, `Iban`, `ApplicationRules`, `ConsentRules`, `CertificateRules`, `ExpiryRules`, `DocumentRules` | `PeopleService`, `MemberQuery`, `MemberBook`, `MemberType`, `Levels`, `Guardians`, `Applications`, `Consents`, `Certificates`, `Documents`, `ImportService`, `PeopleCsv` | `PeopleRulesTest`, `AgeTest`, `PhoneTest`, `ApplicationRulesTest`, `GuardiansRulesTest`, `MemberTypeGuardianTest`, `PeopleBirthGuardianTest`, `PeopleCsvYearsTest`, `PeopleGuestsImportTest`, `IncompleteCardsTest` |
| **Attività** | `CancelPolicy`, `Schedule`, `Ics` | `ActivityService`, `ActivityKind`, `ActivityCopy`, `Attendance`, `Calendar`, `LessonExceptions`, `Waitlist`, `PriceLists` | `ActivityTest`, `ActivityCopyTest`, `CancelPolicyTest`, `ScheduleTest`, `IcsTest`, `LessonExceptionsTest` |
| **Core** | — | `Access`, `AccessRequests`, `Audit`, `MembershipRoles`, `BoardRole`, `Visibility`, `Terms`, `Texts` | `AccessTest`, `AccessRolesTest`, `AccessBookTest`, `VisibilityTest`, `TermsTest`, `TextsTest` |
| **Import ed export** | `Xlsx`, `SheetReader` | `ImportService`, `LedgerImport`, `Backup` (diventa esportazione completa) | `XlsxTest` |
| **Documenti** | `Pdf`, `QrCode` | `CardLayout`, `CardToken` | `PdfTest`, `QrCodeTest`, `CardLayoutTest`, `CardTokenTest` |
| **Area soci** | `SatispayRules`, `KlarnaRules` | `PaymentService`, `PaymentItems`, `Gateways`, `StripeApi`, `StripeWebhook`, `PayPalApi`, `SatispayApi`, `SumUpApi`, `OfflinePayments`, `Pwa`, `Push`, `WebPush`, `ActivationToken`, `Rest/`, `Frontend/` | `OnlinePaymentsTest`, `PaymentsConfigTest`, `PaymentFeesSumUpTest`, `PaymentQuarterlyTest`, `WebPushTest`, `ActivationTokenTest` |
| **Risorse** | `ResourceRules`, `LoanRules` | `Resources`, `ResourceGate`, `Holds`, `Inventory` | `ResourceRulesTest`, `ResourceGateTest` |

Le **tre tipologie di risorsa** (oraria, giornaliera, stagionale) e le versioni Web e Interna sono nuove: vanno
confrontate con `ResourceRules` prima di progettare il modulo.

## Fasi successive

| Modulo | Regole pure | Servizi da riscrivere |
|---|---|---|
| Comunicazioni | `SmsRules`, `NewsletterRules` | `Broadcasts`, `Notices`, `Reminders`, `StaffReminders`, `Sms`, `BrevoSms`, `TwilioSms`, `Newsletter`, `BrevoNewsletter`, `MailchimpNewsletter` |
| Vita associativa | `AssemblyRules`, `PollRules` | `Assemblies`, `Polls`, `Minutes` |
| Sport | `AffiliationRules` | `Affiliations`, `SportCatalog`, `Insurance` |
| Altri | `ConventionRules` | `Conventions`, `FivePerMille`, `Wallet`, `ApplePass`, `GoogleWallet`, `DoorSales`, `Donations` |

I test dei blocchi sono in `BlockBRulesTest`, `BlockCRulesTest`, `BlockDRulesTest`, `BlockERulesTest`, `BlockFRulesTest`.

## Da non portare

| Cosa | Perché |
|---|---|
| `Install`, `ProSchema`, `Db` | sostituiti dalle migrazioni e dallo schema in [DATABASE.md](DATABASE.md); le tabelle restano il riferimento dei dati |
| `Plugin`, `Modules`, `Edition`, `ProBoot`, `ProBlock*`, `Admin/`, `Settings`, `Html`, `Flash`, `Pages`, `Areas` | struttura e interfaccia WordPress |
| `License`, `LicenseClient`, `LicensePolicy`, `LicenseRules`, `Updater`, `UpdateCheck` | nel SaaS valgono i diritti firmati dal portale |
| `WooBridge`, `WooLinks`, `WpAllImport`, `TribeImport`, `MailPoetNewsletter` | integrazioni con plugin WordPress |
| `Secrets`, `ClientIp` | si usa la cifratura del modulo dedicato; si riscrivono su Laravel |

## Da costruire da zero

Separazione tra associazioni, servizio chiavi, cifratura dei campi, generatore di siti, diritti dal portale, area
Studio per i commercialisti, sconti e conteggi per i pacchetti, fattura elettronica, compensi sportivi.

## Regole del plugin da applicare quando si costruisce il modulo (plugin gratuito 1.3.13, Pro 1.3.3, API 17)

Questi modi di funzionare sono già nel plugin e confermati dal titolare. Nel SaaS i moduli interessati non esistono
ancora: la regola si applica quando il modulo viene scritto, non dopo.

| Dove | Regola |
|---|---|
| Area soci, rimborsi spese | Chiedere un rimborso è permesso solo ai soci volontari. Il pulsante compare solo a loro e la richiesta di un altro tipo di socio è rifiutata anche lato server. |
| Contabilità, conti | Ogni conto ha un costo di incasso: quota fissa `fee_fixed_cents` (massimo 2000, cioè 20 €) e percentuale `fee_bp` in punti base (massimo 2500, cioè 25%). A ogni incasso di segreteria o sul posto si registra una spesa «costo generale» pari a fisso + importo × `fee_bp` / 10000. Gli incassi online restano con le tariffe del gateway. |
| Risorse, opzioni di pagamento | Con la modalità «pagamento online in anticipo» e nessun pagamento online attivo l'opzione è `desk`: la risorsa si prenota e si salda in segreteria, invece di non avere alcuna opzione. |
| Attività, segreteria | I pacchetti pagati con ingressi residui sono mostrati in segreteria (panoramica dei pacchetti e pacchetti della persona). |
| Sito, menu | Il menu non riceve la voce «Area riservata» se la pagina è già in un qualsiasi menu. Verifica richiesta: una voce nel menu desktop e una nel menu mobile. |
| Eventi, calendario | Vista settimana, mese e giorno. |
