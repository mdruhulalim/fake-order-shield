# Fake Order Shield — working rules

WordPress/WooCommerce plugin (PHP 7.4+, jQuery admin JS). Folder/slug stays `order-shield`; option `oshield_settings` and `wp_oshield_*` tables must not be renamed (live sites depend on them).

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
