# market-funded-crm — MCP shim for Henry

Translates MCP tool calls into authenticated HTTP requests against the
Market Funded CRM's `/api/henry/*` endpoints. Phase 4a milestone 2.

## Tools

| Tool | Forwards to |
|---|---|
| `health` | `GET /api/henry/health` |
| `search_people` | `GET /api/henry/people/search?q=&limit=` |
| `get_person` | `GET /api/henry/people/{id}` |
| `book_metrics` | `GET /api/henry/metrics/book` |

`post_event` and `pause_autonomous` are NOT in this build — the
corresponding CRM endpoints land later in Phase 4a (milestones 3-4).

## Required env

| Var | Purpose |
|---|---|
| `MFU_CRM_BASE_URL` | e.g. `http://localhost:8000` (php artisan serve) |
| `MFU_CRM_API_TOKEN` | bearer token; must equal CRM's `HENRY_API_TOKEN` |

Both are set by OpenClaw via the `mcp.servers.market-funded-crm.env`
block in `~/.openclaw/openclaw.json` — you don't normally export them
in your shell.

## Local testing

```bash
npm install
MFU_CRM_BASE_URL=http://localhost:8000 \
MFU_CRM_API_TOKEN=<the token> \
npx @modelcontextprotocol/inspector node index.js
```

The Inspector opens a browser UI where you can list and call each tool
against the live CRM.

## stdio caveat

stdout is the MCP wire — never `console.log` from this script. All
diagnostics go to stderr (the `log()` helper).
