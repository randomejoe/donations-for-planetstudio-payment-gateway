# Donations for PlanetStudio Payment Gateway

A WordPress plugin for a donation form, fundraising campaigns and monthly or weekly recurring donations on saved cards, paid through [PlanetStudio Payment Gateway](https://www.planetstudio.am) (PSPG) and whichever bank you have configured there. It keeps its own donor, donation and recurring-donation records, so it does not need GiveWP or WooCommerce.

This is an independent add-on. It is not made or supported by PlanetStudio.

## Requirements

* WordPress 6.5 or later (tested up to 7.1)
* PHP 7.4 or later
* PlanetStudio Payment Gateway, active, with your bank's credentials set up
* Recurring donations need a bank on the ArCa network in PSPG. Other banks take one-time donations.

## Installing

1. Download `donations-for-planetstudio-payment-gateway-<version>.zip` from [Releases](../../releases). Use that zip, not GitHub's "Source code" download, which unpacks to a folder with the version in its name, so each update would install as a separate plugin.
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose the zip and activate it.
3. Go to **Donations > Form Settings**, choose the bank and currencies, and switch test mode on.
4. Put `[pspgd_donation_form]` on a page, or create a campaign under **Donations > Campaigns**.
5. Make a test donation with test mode on, then a real one with test mode off, before announcing the page.

[readme.txt](readme.txt) is the full documentation: every setting, the shortcode attributes, the actions and filters for customising it, the external services it calls, what it stores about donors, and the FAQ.

## What it does

* **Donation form.** Three steps (amount, details, review), one-time, monthly and weekly giving, suggested amounts and limits per currency. Donors pay in the currency they choose. Honeypot, fill-time check, per-address throttle and optional Cloudflare Turnstile.
* **Campaigns.** Goal, end date, progress bar across currencies, optional supporters list. When a campaign closes, its recurring donations end, the bank is asked to release the saved cards and the donors are emailed.
* **Recurring donations.** Charged on the donor's sign-up anniversary, with retries after 3, 7 and 14 days before cancelling. Every email carries a cancel link.
* **Totals you can trust.** A donation counts as paid only when the bank says paid, for the order and amount it was registered under. Payments the bank never reported back are re-checked every 15 minutes. Refunds and voids made in PSPG mark the donation refunded.
* **Emails** in the donor's own language, editable per language. English and Armenian are built in.
* **Admin.** Donation log with filters, search and CSV export; recurring donations list; a `pspgd_manage_donations` capability for staff; test mode; WordPress privacy export and erase.

## Development

The tests need PHP on the command line and no WordPress installation:

```sh
php tests/run.php
```

`tests/bootstrap.php` provides stand-ins for the WordPress functions the plugin calls. See [tests/README.md](tests/README.md) for what the suite covers.

To build the release zip:

```sh
sh tools/build-zip.sh
```

It packs the last commit, not the working tree, into `dist/`, and leaves out everything marked `export-ignore` in `.gitattributes` (tests, tools, this README). `python3 tools/make-pot.py` regenerates `languages/donations-for-planetstudio-payment-gateway.pot` after changing translatable strings.

[RELEASING.md](RELEASING.md) has the steps for a new version.

## License

GPLv3. See [LICENSE](LICENSE).
