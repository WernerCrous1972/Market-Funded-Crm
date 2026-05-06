#!/usr/bin/env node
/**
 * Market Funded CRM — MCP shim for Henry (OpenClaw).
 *
 * Translates MCP tool calls into authenticated HTTP requests against the
 * CRM's /api/henry/* endpoints. Phase 4a milestone 2.
 *
 * Tools exposed:
 *   - health           → GET  /api/henry/health
 *   - search_people    → GET  /api/henry/people/search
 *   - get_person       → GET  /api/henry/people/{id}
 *   - book_metrics     → GET  /api/henry/metrics/book
 *
 * Transport: stdio. OpenClaw spawns this process when Henry needs CRM tools.
 *
 * Required env (passed in by OpenClaw via the mcp.servers config block):
 *   MFU_CRM_BASE_URL   — e.g. http://localhost:8000
 *   MFU_CRM_API_TOKEN  — bearer token, must match the CRM's HENRY_API_TOKEN
 *
 * IMPORTANT: stdout is reserved for the MCP wire protocol. All logging goes
 * to stderr — never console.log. A stray stdout write corrupts the JSON-RPC
 * stream and the client disconnects without telling the user why.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const BASE_URL = (process.env.MFU_CRM_BASE_URL ?? "").replace(/\/$/, "");
const API_TOKEN = process.env.MFU_CRM_API_TOKEN ?? "";

if (!BASE_URL) {
  process.stderr.write("[mfu-crm-mcp] MFU_CRM_BASE_URL is not set; aborting\n");
  process.exit(1);
}
if (!API_TOKEN) {
  process.stderr.write("[mfu-crm-mcp] MFU_CRM_API_TOKEN is not set; aborting\n");
  process.exit(1);
}

const log = (msg) => process.stderr.write(`[mfu-crm-mcp] ${msg}\n`);

/**
 * Single helper: GET an absolute path on the CRM, return a JSON object.
 * Throws on network error or non-2xx — the caller wraps it in an MCP tool
 * error result so Henry sees a useful message.
 */
async function crmGet(path, params) {
  const url = new URL(`${BASE_URL}${path}`);
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
    }
  }

  let response;
  try {
    response = await fetch(url, {
      method: "GET",
      headers: {
        Authorization: `Bearer ${API_TOKEN}`,
        Accept: "application/json",
      },
      // 10s — generous; CRM endpoints are local
      signal: AbortSignal.timeout(10_000),
    });
  } catch (err) {
    throw new Error(`CRM unreachable at ${url.pathname}: ${err.message}`);
  }

  const text = await response.text();
  let body;
  try {
    body = text ? JSON.parse(text) : {};
  } catch {
    body = { raw: text };
  }

  if (!response.ok) {
    const detail = body?.error || body?.message || JSON.stringify(body).slice(0, 200);
    throw new Error(`CRM ${response.status} on ${url.pathname}: ${detail}`);
  }

  return body;
}

const ok = (data) => ({
  content: [{ type: "text", text: typeof data === "string" ? data : JSON.stringify(data, null, 2) }],
});

const err = (message) => ({
  isError: true,
  content: [{ type: "text", text: message }],
});

// ────────────────────────────────────────────────────────────────────────────

const server = new McpServer(
  { name: "market-funded-crm", version: "0.1.0" },
  {
    instructions:
      "Tools for querying the Market Funded CRM (Phase 4a, read-only). " +
      "Use search_people to find someone by name/email/phone, get_person for a full " +
      "summary, book_metrics for company-wide deposits/withdrawals/dormant counts, " +
      "and health for a quick liveness check.",
  },
);

server.registerTool(
  "health",
  {
    title: "CRM health check",
    description:
      "Returns ok status plus people_count / clients_count / leads_count. Use this when Werner asks if the CRM is alive, or as a sanity check before other queries.",
    inputSchema: {},
  },
  async () => {
    try {
      return ok(await crmGet("/api/henry/health"));
    } catch (e) {
      return err(e.message);
    }
  },
);

server.registerTool(
  "search_people",
  {
    title: "Search people",
    description:
      "Find people in the CRM by name, email, or phone (case-insensitive partial match). Returns up to `limit` rows with id, name, email, phone, contact_type, branch.",
    inputSchema: {
      q: z.string().min(1).describe("Search query — name fragment, email fragment, or phone digits"),
      limit: z.number().int().min(1).max(50).optional().describe("Max results (default 20, max 50)"),
    },
  },
  async ({ q, limit }) => {
    try {
      return ok(await crmGet("/api/henry/people/search", { q, limit }));
    } catch (e) {
      return err(e.message);
    }
  },
);

server.registerTool(
  "get_person",
  {
    title: "Get person summary",
    description:
      "Full summary for one person: identity, contact_type, lead_status, branch, account_manager, last_online_at, financial metrics (total/net deposits, withdrawals, dormancy days, segment flags), and the 10 most recent transactions.",
    inputSchema: {
      id: z.string().uuid().describe("Person UUID — use search_people first if you only have a name"),
    },
  },
  async ({ id }) => {
    try {
      return ok(await crmGet(`/api/henry/people/${id}`));
    } catch (e) {
      return err(e.message);
    }
  },
);

server.registerTool(
  "book_metrics",
  {
    title: "Book-wide metrics",
    description:
      "High-level metrics across the whole CRM: people totals (leads vs clients), deposits/withdrawals/challenge purchases for today and month-to-date in USD, and dormant client counts (14+ days, 30+ days). Use this for 'how is the book doing?' questions.",
    inputSchema: {},
  },
  async () => {
    try {
      return ok(await crmGet("/api/henry/metrics/book"));
    } catch (e) {
      return err(e.message);
    }
  },
);

// ────────────────────────────────────────────────────────────────────────────

const transport = new StdioServerTransport();
await server.connect(transport);
log(`connected — base=${BASE_URL}, tools=4`);
