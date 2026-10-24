# Releasing a new version

Everything here happens on `main`. `tests/version-test.php` checks that the
version and requirements below agree, so a step you miss fails `php tests/run.php`.

## 1. Bump the version

Use `X.Y.Z`. Change it in four places:

1. `Version:` in the header of `donations-for-planetstudio-payment-gateway.php`
2. `PSPGD_VERSION` in the same file (asset URLs carry it, so browsers fetch the new CSS and JS)
3. `Stable tag:` in `readme.txt`
4. A `= X.Y.Z =` entry at the top of `== Changelog ==` in `readme.txt`, saying what changed for site owners

Only bump `PSPGD_DB_VERSION` when `PSPGD_Installer::install()` changes a
table. Sites then run the installer on their next request.

If the WordPress or PHP requirements change, update `Requires at least` /
`Requires PHP` in the plugin header, `readme.txt` and the Requirements list
in `README.md`. When a new WordPress version has been tested, update
`Tested up to` in `readme.txt` and `README.md`.

## 2. Check

- Regenerate the translation template if any translatable string changed:
  `python3 tools/make-pot.py`
- `php tests/run.php` passes. Run it once with `PSPGD_PSPG_SOURCE` set to
  the latest PSPG, unpacked, so the PSPG contract test runs too (see
  `tests/README.md`). The release notes name the PSPG version it was
  tested against.
- Commit, then push `main` (step 4) and wait for CI to go green: tests on
  every supported PHP version, and Plugin Check with no errors.
- Build the zip (step 3), install it on a test site with the plugin's test
  mode on, and make a one-time and a recurring test donation.

## 3. Build the zip

```sh
sh tools/build-zip.sh
```

It packs the last commit, not the working tree, into
`dist/donations-for-planetstudio-payment-gateway-X.Y.Z.zip`. It warns if
anything is uncommitted. Files marked `export-ignore` in `.gitattributes`
(tests, tools, `.github`, the READMEs for GitHub) are left out.

## 4. Tag, push and publish

Push by name, never with `--all` or `--mirror`:

```sh
git tag -a vX.Y.Z -m "Donations for PlanetStudio Payment Gateway X.Y.Z"
git push origin main
git push origin vX.Y.Z
gh release create vX.Y.Z dist/donations-for-planetstudio-payment-gateway-X.Y.Z.zip --title X.Y.Z --notes-file notes.md
```

The release notes follow the 1.0.0 release: what changed, then the install
note (use the attached zip, not the "Source code" archives) and the
requirements.

## 5. WordPress.org

Once the plugin is approved, each release also goes to the plugin's
Subversion repository, `https://plugins.svn.wordpress.org/donations-for-planetstudio-payment-gateway`.
`svn` is not on macOS by default: `brew install subversion`.

```sh
svn checkout https://plugins.svn.wordpress.org/donations-for-planetstudio-payment-gateway wporg-svn
cd wporg-svn
rm -rf trunk/* && unzip -q ../dist/donations-for-planetstudio-payment-gateway-X.Y.Z.zip -d /tmp/pspgd && cp -R /tmp/pspgd/donations-for-planetstudio-payment-gateway/. trunk/
cp ../.wordpress-org/* assets/
svn add --force trunk assets && svn status | awk '/^!/ {print $2}' | xargs -r svn rm
svn cp trunk tags/X.Y.Z
svn ci -m "Release X.Y.Z" --username joedavtian
```

`trunk/` is exactly the release zip's contents. WordPress.org serves the
version named by `Stable tag:` in `trunk/readme.txt` from `tags/X.Y.Z/`, so
the tag must exist before that readme says so.

`assets/` holds the listing's icon, banner and screenshots from
`.wordpress-org/` (left out of the zip). `screenshot-N.png` matches caption
N under `== Screenshots ==` in `readme.txt`. `sh tools/wporg-assets/make.sh`
redraws the icon and banner; how the screenshots were taken is in
`tools/wporg-assets/README.md`.
