# Focused regression tests

Run every test from the plugin folder with:

```sh
php tests/run.php
```

No WordPress installation is needed: `tests/bootstrap.php` provides small
stand-ins for the WordPress functions the plugin calls. Each test file can
also be run on its own.

The suite covers the payment-critical boundaries -- recurring retries and
anniversaries, reconciliation of payments the bank never reported, the
abandonment purge, test-mode isolation, campaign totals across currencies,
campaign closing, email composition in the donor's language, form hardening
-- plus `customisation-test.php`, which checks that the bank, currencies,
languages, colours and logo all come from settings and that no shipped file
carries another organisation's name or design.

`version-test.php` checks that the version and requirements agree across the
plugin header, `PSPGD_VERSION`, `readme.txt` and `README.md`.

`pspg-compat-test.php` checks that a PSPG missing a part this plugin calls
is named in an admin notice. `pspg-contract-test.php` reads the PSPG hooks,
functions, classes and methods this plugin uses from its own code and looks
for each in a real copy of PSPG. It runs only when `PSPGD_PSPG_SOURCE` names
an unpacked PSPG plugin folder; CI points it at the latest WordPress.org
release:

```sh
PSPGD_PSPG_SOURCE=/path/to/planetstudio-payment-gateway php tests/run.php
```
