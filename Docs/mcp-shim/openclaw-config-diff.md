# OpenClaw config update — add market-funded-crm MCP server

**Target file:** `~/.openclaw/openclaw.json`
**Reason for caution:** that file got "clobbered" 60+ times in late April. Apply this carefully — back it up first, edit, validate, then save.

## Backup first

```bash
cp ~/.openclaw/openclaw.json ~/.openclaw/openclaw.json.before-mfu-mcp
```

## What to change

Locate the existing block:

```json
"mcp": {
  "servers": {
    "twenty-crm": { ... }
  }
}
```

Add a sibling entry to `twenty-crm`:

```json
"mcp": {
  "servers": {
    "twenty-crm": { ... unchanged ... },
    "market-funded-crm": {
      "transport": "stdio",
      "command": "node",
      "args": ["/Users/wernercrous/openclaw/mcp-servers/market-funded-crm/index.js"],
      "env": {
        "MFU_CRM_BASE_URL": "http://localhost:8000",
        "MFU_CRM_API_TOKEN": "ea6ca61dc87701e1b0cec8501f0ca7d0e91c170b2ddb955c397ffb9fcea9127b"
      }
    }
  }
}
```

Don't forget the comma after `twenty-crm`'s closing brace.

## Validate

```bash
python3 -c "import json; json.load(open('/Users/wernercrous/.openclaw/openclaw.json')); print('JSON OK')"
```

If that prints `JSON OK`, you're safe to restart Henry's gateway. If not, restore from the backup and try again.

## Restart

Henry restarts his gateway/agent however he normally does (probably an `openclaw` CLI command or a launchd reload). After restart, ask Henry: "Do you have the market-funded-crm MCP tools loaded?" — he should list `health`, `search_people`, `get_person`, `book_metrics`.

## Demo prompt for Henry once loaded

Try one of:

- "Henry, what's our book looking like today?"  → expect `book_metrics` call
- "Henry, find Werner Breytenbach for me"  → expect `search_people` then `get_person`
- "Henry, is the CRM responsive?"  → expect `health`

## Caveats

- **`php artisan serve` must be running** on port 8000 — if it's not, every tool call returns "CRM unreachable". Henry will say so cleanly; no silent failures.
- The two endpoints `post_event` and `pause_autonomous` aren't in this build — they ship with milestones 3 and 4 of Phase 4a.
- Token rotation: if you ever change `HENRY_API_TOKEN` in the CRM `.env`, also update it in this config block, then restart Henry's gateway.
