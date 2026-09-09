# Rebrand the product name

Use this when you want display copy to say something other than Zao Dash. The script does not rename PHP classes, MCP paths, or slugs such as `ZaoDashServer` and `/mcp/zao-dash`.

## Run it

From the repository root, preview the change first:

```bash
php scripts/rebrand.php
```

That prints the files that would change and writes nothing. The default name is `Agency Dash`.

Write the changes only after the preview looks right:

```bash
php scripts/rebrand.php --write
php scripts/rebrand.php "Northstar OS" --write
```

Names cannot contain quotes, backslashes, backticks, or dollar signs. The root must contain `composer.json` and `scripts/rebrand.php`.

## What it changes

The script walks text files under the repository and replaces these strings, longest first:

- `Zao Dashboard`
- `Zao Dash`
- `ZAO DASH`

It skips `vendor/`, `node_modules/`, `.git/`, `storage/`, and frontend build output. The script does not rewrite `docs/rebrand.md` or `tests/Feature/Scripts/RebrandTest.php`, so the instructions and Pest fixtures keep the original search strings.

Run `php scripts/rebrand.php --write` on a fresh clone before you commit a fork. A second `--write` with the same name changes nothing.

## What it leaves alone

- Class and file names (`ZaoDashServer`)
- Route paths (`/mcp/zao-dash`)
- The GitHub organization and this repository URL
- Secrets and `.env` values other than the product-name strings above

If `APP_NAME` in `.env` or `.env.example` is quoted as `Zao Dash`, that value is updated. Other environment values stay as they are.
