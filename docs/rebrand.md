# Rebrand the product name

Use this when you want display copy to say something other than Zao Dash. The script does not rename PHP classes, MCP paths, or slugs such as `ZaoDashServer` and `/mcp/zao-dash`.

## Run it

From the repository root:

```bash
php scripts/rebrand.php
```

That replaces `Zao Dash` and `Zao Dashboard` with `Agency Dash`.

Pass another name as the first argument or with `--name`:

```bash
php scripts/rebrand.php "Northstar OS"
php scripts/rebrand.php --name="Northstar OS" --dry-run
```

`--dry-run` prints the files it would change and does not write them.

## What it changes

The script walks text files under the repository and replaces these strings, longest first:

- `Zao Dashboard`
- `Zao Dash`
- `ZAO DASH`

It skips `vendor/`, `node_modules/`, `.git/`, `storage/`, and frontend build output. The script does not rewrite `docs/rebrand.md`, so these instructions keep the original search strings.

Run it on a fresh clone before you commit a fork. A second run with the same name changes nothing.

## What it leaves alone

- Class and file names (`ZaoDashServer`)
- Route paths (`/mcp/zao-dash`)
- The GitHub organization and this repository URL
- Secrets and `.env` values other than the product-name strings above

If `APP_NAME` in `.env` or `.env.example` is quoted as `Zao Dash`, that value is updated. Other environment values stay as they are.
