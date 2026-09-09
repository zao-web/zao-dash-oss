# Connect a local assistant

This page is the first-run path for the HTTP MCP server. It assumes you already copied `.env.example` to `.env` and ran `composer setup`.

## What you will have at the end

A Sanctum token for an internal user, and a client pointed at `http://localhost:8000/mcp/zao-dash`.

## Start the app

1. Copy `.env.example` to `.env` if you have not already.
2. Run `composer setup`.
3. Run `composer dev`.
4. Open `APP_URL` (default `http://localhost:8000`) and sign in as the seeded owner if you ran `php artisan db:seed` (`owner@example.com` / `password`). Change that password before any shared deploy.

Empty integration keys stay disabled. You do not need Slack, GitHub, or a model key to sign in.

## Mint an MCP token

Internal MCP servers require a Sanctum token for a user with an internal role (`owner`, `admin`, or `staff`). A client-portal token is rejected.

```bash
php artisan mcp:token --user=owner@example.com
```

The command prints the token once and a shell export line. Store it in your shell or in the client config. Do not commit it. Do not paste it into this repository.

The variable name the checked-in `.mcp.json` expects is `ZAO_DASH_MCP_TOKEN`. That name is for the MCP client process. It is not an application setting in `.env.example`.

## Point Cursor at the server

`.mcp.json` in this repository already targets the local HTTP server through `mcp-remote`:

```json
{
  "mcpServers": {
    "zao-dash": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "http://localhost:8000/mcp/zao-dash",
        "--header",
        "Authorization: Bearer ${ZAO_DASH_MCP_TOKEN}"
      ]
    }
  }
}
```

1. Export `ZAO_DASH_MCP_TOKEN` in the environment that launches Cursor.
2. Keep `composer dev` running so `http://localhost:8000` answers.
3. Reload MCP servers in Cursor.
4. Call a read tool such as `list-clients` or `get-integration-status`.

`laravel-boost` in the same file is a local Artisan MCP process. It talks to the local database, not to a production host.

## Point Claude Desktop or Claude Code at the server

Use the same HTTP endpoint. A typical Claude Desktop entry:

```json
{
  "mcpServers": {
    "zao-dash": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "http://localhost:8000/mcp/zao-dash",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}
```

Replace `YOUR_TOKEN` in the client config on your machine. Do not commit that file if it contains the token.

`php artisan mcp:start` waits for JSON-RPC on stdin. Use it only for a stdio client. Do not use it as a stand-in for the HTTP server.

## Point another local model client at the server

Any MCP client that can send HTTP with a bearer token can use the same URL. The server is Laravel MCP over HTTP, registered in `routes/ai.php`.

| Path | Who can call it |
| --- | --- |
| `/mcp/zao-dash` | Internal user token |
| `/mcp/zao-finance` | Internal user token. Client profitability and invoice velocity only. |
| `/mcp/zao-web` | Internal user token |
| `/mcp/zao-comms` | Internal user token |
| `/mcp/zao-client` | Client-scoped site token, not an internal user token |

## What to configure and what to leave empty

Configure on first run:

- `APP_KEY` (created by `composer setup`)
- `APP_URL`
- Database settings if you are not using the SQLite default

Leave empty until you use the feature:

- Model keys (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `GROK_API_KEY`)
- Slack, Google, GitHub, Harvest, QuickBooks, PayPal, and SpinupWP keys
- `AGENT_INTERNAL_TOKEN`, until website-builder or agent callbacks need it

Leave the stubbed household keys empty. `PLAID_*`, `TELLER_*`, `WISE_*`, and `TAX_AGENCY_*` are listed so older notes still parse. Those HTTP routes and MCP tools are not registered in this snapshot.

The full name list is in [Environment variables](environment.md).

## Common pitfalls

- A 403 from MCP usually means the token belongs to a client user, or the user is not internal. Mint the token for `owner@example.com` or another internal user.
- `mcp:start` looks hung because it is waiting on stdin. Use the HTTP URL for Cursor and Claude Desktop.
- OAuth redirect URIs in `.env.example` use `http://localhost:8000`. If `APP_URL` or the dev port differs, update the matching `*_REDIRECT_URI` before you start an OAuth flow.
- `composer dev` must be running before the MCP client connects. A client pointed at a stopped app reports a connection error, not an auth error.
- Do not commit `.env`. The catalog and this page never include secret values.
- Rebranding display copy does not change `/mcp/zao-dash`. Keep the client URL on that path unless you change the route yourself.
