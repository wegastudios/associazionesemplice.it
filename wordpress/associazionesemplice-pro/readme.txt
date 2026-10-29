=== Associazione Semplice Pro ===
Contributors: wegastudios
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.37
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced features for Associazione Semplice: online payments, accounts and funds, accounting and reports, insurance and attendance registers, mass communications, app and wallet cards.

== Description ==

Associazione Semplice Pro adds to Associazione Semplice (required) the features that go beyond the basics: online payments with Stripe and PayPal, several accounts and funds, accounting and reports, insurance and attendance registers, mass emails, installable app with notifications, Apple and Google Wallet cards, receipts in PDF, several member levels, VAT and customisation of every text and language.

== External services ==

With a license key entered in Associazione Semplice Pro, once a day (and when you save the key or press "Check now") the plugin sends the key and your site's domain name to the Associazione Semplice website (https://associazionesemplice.it) to confirm that the key is valid for that domain and to receive the plan and its expiry date. No member data, no content and no other information is sent. The same website is asked for new versions of the plugin and, when you update, for the update package (this can be turned off in Settings → Limits and thresholds). Online payments use Stripe and/or PayPal, only if you set them up: the payer is sent to their payment page and the plugin asks them to confirm the payment.

== Third-party data ==

The plugin includes a copy of the Public Suffix List (https://publicsuffix.org, Mozilla Public License 2.0) to tell which domain a license covers.

== Changelog ==

= 1.1.37 =
* Affiliations: all CONI-recognised promotion bodies, disciplines field, sport and discipline columns in the file for the bodies' portals, card type defaults to the membership type.

= 1.1.36 =
* Attendance register with a second calendar view (dates across, people down); lessons cancelled or moved are taken into account.

= 1.1.35 =
* Receipts for minors are addressed to the operating parent, with all parents' tax codes and traceable-payment note for ages 5-18.

= 1.1.34 =
* Compatible with free 1.2.39 (resource entry pages use the new escaped output).

= 1.1.33 =
* License: the plan level is decided only by the portal (signed proof); a renamed plan on the licence server no longer matters.

= 1.1.32 =
* Security hardening shared with the free plugin.

= 1.1.31 =
* Fix: saving the license key no longer ends with an unexpected error; license grace period cannot be extended by a future date.

= 1.1.30 =
* Integrations list mentions all online payment methods; singular wording in notices.

= 1.1.29 =
* Fix: the Conventions tab is visible again in Registers when Pro is active.

= 1.1.28 =
* SMS (Brevo, Twilio), newsletter sync (Brevo, Mailchimp, MailPoet), SMS/newsletter consents, Satispay and Klarna via Stripe.

= 1.1.27 =
* Assemblies and proxies, budget, volunteer expense refunds.

= 1.1.26 =
* Resources: booking reminders, ledger category per resource, occupancy report, member assignment requests, QR entry.

= 1.1.25 =
* Medical certificates register (private files, expiries, reminders) and affiliations with member cards for sports and cultural bodies.

= 1.1.24 =
* Collection fees now default to the providers' published standard rates (Stripe 1.5% + 0.25, PayPal 3.40% + 0.35, SumUp 2.5%); editable in payment settings.

= 1.1.23 =
* SumUp as a payment method (hosted checkout); collecting costs per method, optional pass-on to the payer and automatic estimated expense in the cash book. Requires Associazione Semplice 1.2.26.

= 1.1.22 =
* Online payment of the membership application fee (Stripe, PayPal); if the application is refused the payment stays to be refunded. Requires Associazione Semplice 1.2.25.

= 1.1.21 =
* Same review fixes as the main plugin (scripts and styles enqueued, form data cleaned). Requires Associazione Semplice 1.2.23.

= 1.1.20 =
* Resources: new overlaps are refused (links to a resource, new or moved dates, set-up included); existing overlaps are only reported on the dashboard and must be fixed by hand. Requires Associazione Semplice 1.2.22.

= 1.1.19 =
* Resources: set-up time before and after courses and events (added together with the resource's own); an event must say which resource it uses. Requires Associazione Semplice 1.2.21.

= 1.1.18 =
* Works with the free plugin 1.2.19 (compatibility level 4): calendar years now live in the free plugin.

= 1.1.17 =
* Cash > Book also shows the resources available on a day, and the free days of a resource.

= 1.1.16 =
* Prepaid credits: an activity paid with credits now sets aside the volunteer's share in their fund, like a payment in money.

= 1.1.15 =
* Resources: each resource can set aside a share of every payment (fixed amount or percentage) into a fund, for resources managed by third parties.

= 1.1.14 =
* Texts reviewed: clearer license, limits and payment messages.

= 1.1.13 =
* Reminder to open the next calendar year from September (dismissible each month, always visible in December).

= 1.1.12 =
* Update list shows the plugin icon and banner.

= 1.1.11 =
* Works with Associazione Semplice 1.2.11 or later: update both together. Nothing changes in how you use it; your data stays as it is.

= 1.1.10 =
* The license check and the update notices now live in this add-on; requires Associazione Semplice 1.2.10 or later.

= 1.1.9 =
* Prepaid credits: packages, live QR charge, credit ledger and admin page.

= 1.1.8 =
* Bookable resources for the Pro Lite and Pro Full plans: management pages, bookings and guests.

= 1.1.7 =
* Safer updates: the plugin checks that the downloaded package really comes from Associazione Semplice and refuses older versions.

= 1.1.6 =
* Direct updates from WordPress (Dashboard → Updates) for the Pro add-on, served by the Associazione Semplice portal.

= 1.1.5 =
* Includes the English language pack, previously shipped in the free plugin.

= 1.1.4 =
* Pro features follow your plan: Pro Lite (without accounting) or Pro Full.

= 1.1.3 =
* Renamed to Associazione Semplice Pro (follows the main plugin rename).

= 1.1.2 =
* Customisation of every text, additional languages, entity type and terms (moved here from the free edition).

= 1.1.1 =
* First release.
