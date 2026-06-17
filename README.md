# Chaincast

Publish your WordPress posts to the **Hive** and **Steem** blockchains — automatically or on demand — through a pluggable, chain-agnostic connector architecture.

The signing layer (graphene serialization, canonical secp256k1 signing, RPC with failover) is implemented in pure PHP. No external signing service is involved.

## Features

- **Connectors for Hive and Steem**, built on a shared graphene base. The core is chain-agnostic; new chains are added as connectors without touching it.
- **Two signing modes:**
  - *Automatic* — posting key stored on the server, encrypted with AES-256-GCM (the encryption key lives in a `wp-config.php` constant, never in the DB). Signs and broadcasts on its own.
  - *Assisted* — signing happens in the browser via Hive Keychain / Steem Keychain. The key never reaches the server.
- **Per-post control** — publish/update from a button in the editor, or enable automatic publishing per chain.
- **Content pipeline** — HTML→Markdown, automatic permlink, `json_metadata` with a canonical URL for SEO.
- **Customizable attribution footer** with `{site}` / `{url}` placeholders (off by default).
- **Reward sharing** (Hive/Steem *beneficiaries*) — global or per post.
- **Per-post tags** and a configurable WordPress-category → chain-tag/community map (independent per chain).
- **Publish history** log per post and **idempotent state** (re-publishing updates the same on-chain post).
- **Background queue** via Action Scheduler; **credential validation** against the chain without broadcasting.

## Requirements

- PHP **8.1+**
- PHP **`gmp`** extension (hard requirement of the elliptic-curve library used for signing). Without it, server-side automatic signing is disabled; assisted Keychain mode still works.
- WordPress **6.0+**
- Composer dependencies installed (`vendor/` bundled in releases): `simplito/elliptic-php`, `stephenhill/base58`, `league/html-to-markdown`, `woocommerce/action-scheduler`.

## Installation

1. Copy the plugin folder to `wp-content/plugins/chaincast/` (a release ZIP already includes `vendor/`; from source, run `composer install` in the plugin folder).
2. Activate it in **Plugins**.
3. Configure connectors in **Settings → Chaincast**.
4. For automatic (server-side) mode, define the encryption key in `wp-config.php`:

   ```php
   define( 'CHAINCAST_KEY', 'your-base64-encoded-32-byte-key' );
   ```

5. Publish on demand from the **Chaincast** box in the post editor, or enable automatic publishing per chain.

## Security

- Use the **posting** key only (limited authority). Never the Active or Owner key.
- Stored posting keys are encrypted at rest with AES-256-GCM; the key is read from a `wp-config.php` constant, not the database.
- The plugin talks only to the public RPC nodes of the chains you enable.

## Development

- Run the test suite: `php vendor/bin/phpunit` (64 tests).
- Architecture and design decisions: see [`DESIGN.md`](DESIGN.md).
- The graphene serializer and the canonical signing loop are validated byte-for-byte against golden vectors (`tests/fixtures/`), generated from `mahdiyari/hive-php` used purely as a test oracle.

## Internationalization

Source strings are in English. A Spanish (`es_ES`) translation is bundled in [`languages/`](languages/) along with the `.pot` template. To recompile the `.mo` after editing a `.po`:

```sh
python languages/compile_mo.py
```

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE) (or <https://www.gnu.org/licenses/gpl-2.0.html>).
