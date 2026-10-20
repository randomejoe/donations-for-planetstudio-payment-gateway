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
