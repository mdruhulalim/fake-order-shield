# Fake Order Shield — working rules

WordPress/WooCommerce plugin (PHP 7.4+, jQuery admin JS). Folder/slug stays `order-shield`; option `oshield_settings` and `wp_oshield_*` tables must not be renamed (live sites depend on them).

## License server (`server/fos-license-server/`)

Separate WordPress plugin that runs on fakeordershield.com: landing page (`[fos_landing]`), customer account (`[fos_account]`), bKash/Nagad orders approved by hand in **FOS Licenses**, and the API the plugin calls: `POST /wp-json/fos/v1/trial {domain}` and `POST /wp-json/fos/v1/license {license_key, domain}`. Not part of the plugin release ZIP. The plugin side is `includes/class-oshield-license.php`: the API URL and the server's Ed25519 public key are constants; every answer is signed (`license|KEY|domain|status|expires|ts`) and rejected if the signature is wrong or `ts` is over a day off. The secret key lives only in the `fos_ls_keypair` option on fakeordershield.com. To test locally, edit the two constants in a local copy only — never commit them.

Free trial = a 3-day trial **key** created from the customer's account (on sign-up with a domain, or the trial card); one per customer and per domain; the plugin has no keyless trial since 1.4.2. Paying for a package on a trial key turns that same key into a paid license. `/fos/v1/trial` is only kept for old 1.4.0/1.4.1 copies.

On fakeordershield.com: license keys are shown on the customer's account page (no email to customers); support number there is for plugin/license help only — never set it in clients' plugin settings.

## Mode: ponytail (always on)

Work in ponytail **full** mode for every task in this repo: smallest complete change, reuse existing helpers (`oshield_opt()`, `oshield_on()`, `OShield_Settings`, list tables), no new dependencies, no speculative options. Never cut validation, escaping/nonces, error handling or data safety. End each reply with what was skipped/not checked. Load the `ponytail` skill at the start of a session.

## Code map: graphify

At the start of a session install and build the graph (no API key needed):

```bash
uv tool install graphifyy || pip install --break-system-packages graphifyy
graphify update .
```

Use the graph before grepping: `graphify explain "OShield_Checkout"`, `graphify path "A" "B"`, `graphify query "how does auto block work?"`. Run `graphify update .` after code changes. `assets/vendor/` is excluded via `.graphifyignore`; `graphify-out/` is git-ignored.

## Checks before pushing

- `php -l` every changed PHP file; `node --check` changed JS.
- Escape output (`esc_html`, `esc_attr`, `esc_url`), sanitize input, nonce + capability check on every form/AJAX handler.

## Releasing

1. Bump `Version:` and `OSHIELD_VERSION` in `order-shield.php`, `Stable tag:` in `readme.txt`.
2. Add a `= x.y.z =` changelog entry in `readme.txt`.
3. Push to `main` — `.github/workflows/release.yml` creates the tag and a GitHub Release with the ZIP.
