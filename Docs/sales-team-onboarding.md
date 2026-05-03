# Sales Team Onboarding — Market Funded CRM

This document is the standard operating procedure for adding a new sales agent to the Market Funded CRM. It assumes the agent already exists in Match-Trader as an `accountManager` — if they don't, stop and add them in MTR first, then return here.

The whole process should take under 10 minutes per agent.

---

## Before you start

You need:

- The agent's **exact** account_manager name string as it appears in MTR. Capitalisation, spaces, accents, everything. This is the most important field — get it wrong and the agent will see zero clients on day one.
- The agent's email address (work email, not personal).
- A list of branches the agent will work — usually multiple. Confirm with the agent before creating the user.
- A temporary password to share with the agent.

To find the exact MTR name string: open any client record in the CRM where this agent is already the assigned account manager, and copy the `account_manager` field value verbatim. That's the source of truth.

If the agent has no clients in MTR yet, ask the QuickTrade admin who manages MTR what the agent's exact account_manager name is. Don't guess.

---

## Step 1 — Create the user in CRM

1. Log in to https://crm.market-funded.com as super admin.
2. Sidebar → **Users & Permissions** → **New user** (top right).
3. Fill in:
   - **Name:** must match the MTR account_manager string exactly. This is non-negotiable. Examples: if MTR has "John D Smith" then the CRM user's name must be "John D Smith" — not "John Smith", not "John D. Smith".
   - **Email:** the agent's work email.
   - **Role:** SALES_AGENT.
   - **Password:** temporary password the agent will change on first login.
4. **Permission Template:** select **Sales Agent (assigned only)**. This pre-fills the toggles. Do not modify the toggles unless you have a specific reason — the template captures what a standard sales agent gets.
5. **Branch Access:** tick every branch the agent works. If unsure, tick all included branches (Market Funded + QuickTrade) and refine later.
6. Click **Save**.

The user is now created with: branch-scoped visibility, can only see clients where they're the assigned account manager, can create notes/tasks, can send WhatsApp/email, cannot edit client records, cannot reassign clients, cannot export.

---

## Step 2 — Verify the account manager link

This is the step everyone forgets. Without it, the agent logs in to an empty CRM.

1. Open a terminal on your Mac and SSH to production:
   ```
   ssh -i ~/.ssh/mfu_production deployer@144.126.225.3
   cd /var/www/market-funded-crm
   php artisan tinker
   ```

2. At the `>>>` prompt, run:
   ```php
   App\Models\Person::where('account_manager', 'EXACT_MTR_NAME_STRING')->whereNotNull('account_manager_user_id')->count();
   ```
   Replace `EXACT_MTR_NAME_STRING` with the agent's MTR name. The count is the number of clients already linked to this user.

3. If the count is `0` (it usually will be on day one — the link only forms after a sync runs):
   ```php
   php artisan mtr:sync --accounts-only
   ```
   Run from the bash prompt (exit tinker with `exit` first). Wait for it to complete. This re-syncs accounts and populates `account_manager_user_id` for any client whose `account_manager` string matches the new user's name.

4. Re-run the count query in tinker. It should now match the number of clients this agent has in MTR. If it's still 0, the name strings don't match. Stop and verify the MTR name letter by letter against the CRM user's name.

5. Type `exit` twice to leave tinker and SSH.

---

## Step 3 — Smoke test the agent's view

Before handing over credentials, log in as the new agent yourself in an incognito window. Confirm:

- [ ] Login works.
- [ ] Sidebar shows: Trading Accounts, Transactions, Reports (limited), Email Templates, Email Campaigns. Does NOT show: Users & Permissions.
- [ ] Dashboard loads. No "View branch financials" widgets visible. "Recent Activity" widget shows only the agent's clients' activity.
- [ ] **People** list shows ONLY the clients where the agent is the assigned account manager. This is the critical check. If they see clients they shouldn't, stop and re-check Step 2.
- [ ] Open one of their clients. Person detail page loads. Financial summary visible (per-client deposits/withdrawals). Health score visible. No edit button on the contact form.
- [ ] Try direct URL to a client they're NOT assigned to: `https://crm.market-funded.com/admin/people/{some-other-uuid}`. Expected: 403 forbidden.

If any check fails, fix before handing over credentials.

---

## Step 4 — Hand over

Send the agent:

- Login URL: https://crm.market-funded.com
- Their email and temporary password
- Instruction to change password on first login: profile menu top-right → Edit profile → New password
- A link to their starting page: the People list, which will already be filtered to their assigned clients

Tell them what they CAN do:
- View their clients, deposit/withdrawal history, and health scores
- Add notes and tasks (they can create but not edit/delete — that's by design)
- Send WhatsApp messages (when WhatsApp is live) and individual emails

Tell them what they CANNOT do, and who to ask if they need to:
- Edit client records (status changes etc.) → ask Werner
- Be reassigned to a client → ask Werner
- Export client lists → not allowed, ask Werner if there's a specific reason
- See clients outside their branches → not allowed

---

## Common problems and fixes

**Agent sees zero clients after login**
Either the name string doesn't match MTR, or the sync hasn't run since user creation. Check Step 2 again. If name matches and sync ran, verify their branch access in Users & Permissions.

**Agent's name in MTR has changed**
The link is by name string at sync time, not a permanent foreign key. If MTR's account_manager string changes for an existing agent (e.g. a typo correction in MTR), update the CRM user's `name` field to match exactly, then run `php artisan mtr:sync --accounts-only`. The link rebuilds.

**Agent needs a different template later**
Don't switch templates on an existing user. Templates only pre-fill at creation. Toggle the specific permissions on/off in their user record directly. Every change is logged in their Permission History tab.

**Agent leaves the company**
Do not delete the user. Instead: revoke all branch access (uncheck every branch in their user record), and disable login. Keep the user record so audit trails on notes/tasks they created remain attributable.

---

## Reference: what the Sales Agent (assigned only) template grants

| Capability | Granted |
|---|---|
| Super admin bypass | No |
| Assigned-only mode | Yes |
| View per-client financials | Yes |
| View branch-level financials | No |
| View health scores | Yes |
| Create notes | Yes |
| Send WhatsApp | Yes |
| Send individual email | Yes |
| Create email campaigns | No |
| Edit client records | No |
| Assign/reassign clients | No |
| Create tasks | Yes |
| Assign tasks to others | No |
| Bulk export to CSV | No |

Note edit and delete permissions on notes/tasks are ADMIN-role-only and never granted via toggles. This is intentional — agents can add corrective notes but cannot rewrite history.
