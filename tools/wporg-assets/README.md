# WordPress.org listing assets

The files in `.wordpress-org/` go into the `assets/` folder of the plugin's
WordPress.org Subversion repository (see `RELEASING.md`), not into the zip.

## Icon and banner

`icon.html` and `banner.html` are drawn in HTML and SVG with the plugin's
default colours (#1d4ed8, #93c5fd) and the Manrope font, which is under the
SIL Open Font Licence and loaded from Google Fonts. Redraw them with:

```sh
sh tools/wporg-assets/make.sh
```

## Screenshots

Taken from the WordPress 7.1 test site (`wp-test/`) with headless Chrome, at
1280 or 1440 pixels wide and in light mode
(`--blink-settings=preferredColorScheme=1`), then cropped:

1. a campaign page with the progress bar, story, form and supporters;
2. the payment result screen (`PSPGD_PSPG::return_url()` for a paid donation, with `pspgd_test_payment=1`);
3. a receipt, captured from `PSPGD_Emails::send_receipt()` through the `pre_wp_mail` filter and opened as a file;
4. the donation log, `admin.php?page=pspgd`;
5. the recurring list, `admin.php?page=pspgd-recurring`;
6. the campaign editor, with WordPress's welcome guide switched off;
7. the top of Form Settings, `admin.php?page=pspgd-form-settings`.

For them the site was temporarily renamed "Riverside Community Fund", given
made-up donors (`@example.org`), donations and monthly donations, a campaign
("Winter coats for 300 children", 2,000,000 AMD goal), three currencies and
English and Armenian, with test mode off. The admin screens were reached
through a temporary must-use plugin that signed a headless browser in with a
one-off key. All of it was removed afterwards.
