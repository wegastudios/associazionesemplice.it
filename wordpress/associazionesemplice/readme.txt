=== Associazione Semplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.47
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Members, events and courses, and a cash book for small Italian non-profit associations (APS and other third-sector entities).

== Description ==

Associazione Semplice helps a small association keep members, activities and accounts in order directly from its own WordPress site. Optional features are off at first: a guided setup asks a few questions and turns on only what is needed.

The plugin is built around Italian third-sector rules (APS, ETS, registers, tax rules), so its interface and texts are in Italian. An English text pack is included and can be selected in the settings, and further language packs can be uploaded as CSV, Excel or JSON files; the plugin does not use the WordPress translation files.

* **Member register**: member records, one membership fee (with an option for founding members), QR membership cards, guests, import from CSV or Excel files, export of the register.
* **Courses and events**: registrations, limited seats with a waiting list, a payment grace period for bank transfer and cash, participant lists, a calendar.
* **Cash book**: income and expenses in a single cash account, bank transfer details for members, export of the cash book.
* **Registers and privacy**: minutes book, privacy notice, consents, anonymisation, personal data download.
* **Members' area** on the site, with notices, the rules to accept, the card and the events and courses, through shortcodes, blocks and Elementor widgets.
* **Roles**: president, vice president, treasurer, councillors and secretary, with separate permissions.
* **Donations with PayPal**: a form that takes the donor to PayPal's own donation page.
* **Backup and reset**: download a full copy of the data; reset the data to start over.
* Works with WP All Import and Elementor if you use them.

Advanced features (online payments, several accounts and funds, accounting and reports, mass emails, app and wallet cards, receipts, VAT) are in the separate Associazione Semplice Pro add-on.

== External services ==

The plugin works fully on its own and contacts no external service by default. The optional features that involve a third party are described below.

= PayPal donations (optional) =
If you turn on donations, your website shows a form whose button opens PayPal's donation page (www.paypal.com/donate) in the visitor's browser, with the PayPal account, the purpose and the chosen amount you set. Nothing is sent from your server to PayPal. Terms: https://www.paypal.com/legalhub - Privacy: https://www.paypal.com/privacy

= Google Places search (optional) =
If you turn on place search and enter your own Google Maps API key, the "Location" fields of courses and events in the administration screens load Google's script (maps.googleapis.com) and show place suggestions while you type. The text typed in the field is sent to Google from the administrator's browser; no member data is sent. Off by default. Terms: https://cloud.google.com/maps-platform/terms - Privacy: https://policies.google.com/privacy

== Installation ==

1. Upload the `associazionesemplice` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it from the Plugins screen.
3. Follow the guided setup that opens on first activation (it can be started again from the Tools menu).

== Frequently Asked Questions ==

= What happens to the data if I delete the plugin? =
By default it stays. In the settings you can choose to delete it permanently when the plugin is deleted.

= Can I import my existing member list? =
Yes, from a CSV or Excel file, with a preview before confirming.

= Where do the donations go? =
Straight to the PayPal account you enter in the settings. They are recorded in the cash book by hand.

== Changelog ==

= 1.2.47 =
* Fiscal year start month separate from the social year (used by the Pro Fiscale plan); hooks for the Pro budget watch.

= 1.2.46 =
* Sport list: added Chess, Dynamic sport shooting and Equestrian tourism.

= 1.2.45 =
* Activities with a sport (CONI list) and free discipline; affiliation bodies list extended to all CONI-recognised promotion bodies.

= 1.2.44 =
* Course fee variants: describe variants (name and price) on the course and assign them at enrolment with a free note.

= 1.2.43 =
* Single lessons of a course can be cancelled or moved with a notice to enrolled people; quarterly course payment.

= 1.2.42 =
* Photo/video release, membership type, receipts for minors addressed to the operating parent, 'Incomplete cards' alerts.

= 1.2.41 =
* Members register with application and approval dates, birthplace, residence and last fee; up to two guardians with one operating on the site; smoother enrolment flow; 'Re-run' link in the activity list.

= 1.2.40 =
* Internal cleanup of the payment filter wrapper (Plugin Check dynamic hook name notice).

= 1.2.39 =
* Code cleanup for the WordPress.org review: all output is escaped through an allow-list, no inline event handlers, request data sanitised on entry.

= 1.2.38 =
* Guardians: at 18 the guardian's permissions switch off, even if the young person has no login yet.

= 1.2.37 =
* Security hardening: visitor IP behind Cloudflare, login redirect, receipt temp files, guest statements, staff notices, user linking, salt check.

= 1.2.36 =
* Fix: after a factory reset the setup wizard reuses the existing pages instead of creating duplicates; clearer card validity wording.

= 1.2.35 =
* Security: the license level comes from the signed proof, unknown levels or states no longer unlock features.

= 1.2.34 =
* Security: backups no longer carry the license key or QR/calendar salts and clean up their temporary file; staff cannot move the sign-in email of treasurers or board members; secret-ballot results stay hidden until the vote closes; votes cannot be reopened after they close; custom terms are stripped of HTML.

= 1.2.33 =
* Setup wizard: choosing 'anyone can ask to join' also turns on the online membership application.

= 1.2.32 =
* Fix: wide fields no longer overflow on narrow screens; singular wording in notices.

= 1.2.31 =
* Internal extension points needed by Pro 1.1.28.

= 1.2.30 =
* Member discounts ("Convenzioni"): partner offers shown to members with a valid card, optional public list, notify button, expiring card and CSV export.
* Membership fee can be paid in instalments: shows what is missing and grants the card on full payment (configurable).

= 1.2.29 =
* Internal changes needed by Pro 1.1.25 (reminders, exports, private file download).

= 1.2.28 =
* Action log: kept for 18 months by default (adjustable), daily cleanup, size status and a Free space button.

= 1.2.27 =
* Polls and secret ballots, inventory and loans, private document archive.

= 1.2.26 =
* Payment methods: costs of collecting (fixed and/or percentage) per method, with an optional pass-on to the payer (off by default) and the SumUp method in the settings and ledger.

= 1.2.25 =
* Online membership applications (shortcode, approved or refused by the secretariat), minors with parents or guardians who are not members, membership valid from payment, invitation for parents.

= 1.2.24 =
* Small screens: wide admin tables scroll by themselves, price lists lay out in a grid, help text about the email is correct, member area sections fit narrow phones; event lists and seat counts are no longer served stale by page caches.

= 1.2.23 =
* Review fixes for WordPress.org: scripts and styles are enqueued, form data is cleaned on arrival, attachments are saved with the WordPress upload functions, and the old APSemplice migration is removed.

= 1.2.22 =
* New hook before adding or moving event dates so add-ons can refuse overlaps.

= 1.2.21 =
* New check point before saving an activity and set-up time fields (before and after) for activities; existing events must be linked to a resource the first time they are edited.

= 1.2.20 =
* Dashboard widgets in the WordPress admin home: items to work on and this week's events and courses, with shortcuts to the main functions.

= 1.2.19 =
* Calendar years (opening and closing) are now in the free plugin too, with a dashboard reminder from September; the association/volunteer guest option only shows with the volunteer share.

= 1.2.18 =
* Price lists for events (individual, group, packages of 4) and secretariat bookings as association guest or volunteer guest, with the automatic discount and without feeding the volunteer fund.

= 1.2.17 =
* Outgoing mail log and test email (with a warning on the dashboard when messages do not leave); the first-access limit only counts messages actually sent; copy button for the WhatsApp message; new Cash > Book page to book events and courses by date or by item; the cash form shows when a payment is a deposit.

= 1.2.16 =
* Funds: a new method lets add-ons set aside the share of a payment made with prepaid credits.

= 1.2.15 =
* New hook point so that add-ons can set aside a share of a payment into a fund.

= 1.2.14 =
* Texts reviewed: clearer messages, author shown as Associazione Semplice; the readme no longer describes the license service (it now belongs to the Pro add-on).

= 1.2.13 =
* Member book: generate the membership cards of all members as A4 sheets with several cards per page (empty positions stay as blank templates), ready to print and cut.

= 1.2.12 =
* Add a new member from the cash form without leaving the page; the card can show member type, board and staff roles and a free text, and can be downloaded as a PDF for printing; Appearance, Bank and Donations keep the settings tabs.

= 1.2.11 =
* The free plugin no longer contains any code of the Pro add-on: if you use the Pro add-on, update both together.
* Run events and courses again: a new "Riproponi" box on every event and course creates a new edition in a new date or social year with the same data. Import events from The Events Calendar, courses included (Tools > From The Events Calendar).

= 1.2.10 =
* Cleaner structure: the license and the updates of the Pro add-on are now handled by the add-on itself.

= 1.2.9 =
* Bookable resources phase 2 (waiting list, rates, hold times) and prepaid credits charged live by QR in the member area; schema 39.

= 1.2.8 =
* The account activation link now lasts 24 hours by default (Limits: 'Validity of the activation link', 1 to 180 days); the message and the member card say so.

= 1.2.7 =
* Security: the first-access limits can read the visitor's address behind a proxy (new limit), requests from unknown people have their own cap and cannot push real ones out, the old email is told when a change is approved; an existing website user with a visitor-only role who is linked to a member gets a new random password; the update-notice download link must belong to the portal.

= 1.2.6 =
* Bookable resources (courts, rooms, boat berths, parking spaces, lockers): new tables and the members' resources view; the management pages come with the Pro add-on.

= 1.2.5 =
* License domain: the complete Public Suffix List (the same copy as the portal) decides which domain a license covers, so customers sharing a host such as altervista.org no longer share a license.

= 1.2.4 =
* License security: The license signing keys are built into the plugin and a valid license needs the signed proof; the plan level comes from the signed part; the Pro features are suspended when the proof is missing, tampered with or too old (new limits: days offline, days before the first check).

= 1.2.3 =
* One free package for everyone (WordPress.org and the portal): the direct updater and the update notice now belong to the Pro add-on; the free plugin no longer contacts the portal for versions.

= 1.2.2 =
* Official brand: new admin menu icon, logo in the dashboard and brand colours in the plugin screens.

= 1.2.1 =
* Direct updates from WordPress (Dashboard → Updates) for the package downloaded from the portal; the WordPress.org package does not contain the updater.

= 1.2 =
* Donations: every collection you create gets its own shortcode (with a Copy button) and a ready PayPal button; clear notice that donations only work when PayPal is configured.

= 1.1.20 =
* Members list: sort by card number (numeric, people without a card last), name and member type; click the column header again to reverse.

= 1.1.19 =
* Import: membership years from year columns (2024, 2025… or ANNO CORRENTE / ANNO SCORSO), 'Anno di iscrizione', 'N. Telefono'; guests get a 'Venute' counter (added to the registered participations) and notes for past events. New limits let you register guests without a mobile number or a host (for importing a history); both rules stay on by default.

= 1.1.18 =
* Member import: columns titled with a year (2023, 2024…) record the actual membership years; 'N. Telefono' is recognised as the phone column.

= 1.1.17 =
* Settings → License: 'Check now' also looks for new plugin versions (skipping the wait between checks) and shows the latest known versions and the date of the last check.

= 1.1.16 =
* Dashboard: links to your associazionesemplice.it account area and to support.

= 1.1.15 =
* License check now goes through the Associazione Semplice website (no credential in the plugin); a license covers the registered domain and all its subdomains and subfolders (staging and test sites included).

= 1.1.14 =
* Update notice: with a license key entered, the plugin asks the Associazione Semplice website for the latest versions and shows a dismissible notice when a newer one exists (can be turned off or tuned in Settings, no data is sent).

= 1.1.13 =
* Member record: Detach email and Detach phone number buttons (office and administrators only); the member keeps their history but loses the access or the number.

= 1.1.12 =
* Moved the English language pack to the Pro plugin (translations belong to text customisation, a Pro feature).

= 1.1.11 =
* License: optional free license key tied to the domain, checked daily with the license server (plan, expiry, signed response); license settings panel with a free key link; the free plugin never loses a basic feature because of a license. Pro Lite and Pro Full levels.

= 1.1.10 =
* Role names are now simply Member and Office (Socio, Segreteria); existing roles are renamed automatically.

= 1.1.9 =
* Renamed to Associazione Semplice (database prefix asem_); installations using the old name are converted automatically on first run.

= 1.1.8 =
* Privacy notice (GDPR) generated from the organisation data: responsible person (president, otherwise the secretariat member), data shared with third parties only for the events attended, no commercial transfer. Available as a site page and as a PDF.

= 1.1.7 =
* Text customisation, languages, entity type and terms are now part of Associazione Semplice Pro; the free edition shows the original texts.

= 1.1.6 =
* Card preview in Appearance: it now has the card border and colours and a real sample QR code.

= 1.1.5 =
* Card editor: the card can be saved after dragging fields (positions with decimals were rejected by the browser), and changing anything selects the custom card.
* Event management: no misleading message for those who manage events without being their referent.

= 1.1.4 =
* Custom member card: choose the size in centimetres (landscape, portrait, square or your own), start from the card generated by the site or from your own image, and place the logo too. The preview shows the saved card. Fixed the image picker button and added a "Save card" button.

= 1.1.3 =
* Member area with a small side menu: "My space", plus Secretariat, Course management and Event management for those who have the role.
* Printable card: no "valid card" badge (only "expired" when needed); the QR instructions are not printed.

= 1.1.2 =
* Card editor: the empty preview keeps the card proportions, so the fields no longer overlap before an image is chosen.
* The administration menu ends with Settings again; the administrative pages are reached from the Settings tabs.

= 1.1.1 =
* First release.
