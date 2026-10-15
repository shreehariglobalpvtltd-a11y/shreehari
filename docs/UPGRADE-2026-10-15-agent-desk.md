# SmartBook desk: agent code at the counter (15 Oct 2026, release p8)

Owner's master prompt (15 Oct 2026): *"Implement Customer, Agent, and Counter
modes; allow Agent Code entry in Agent Mode and especially Counter Mode, with
agent selection/search, automatic commission attribution, passenger records,
and synchronized accounting. Keep Agent/Counter/Admin access restricted to the
`.network` portal."*

## Audit first (what was already live, release p7 / shg-v217)

| Prompt item | State before this release |
|---|---|
| One-page booking, ticket on the page, PNG download, WhatsApp share | Live (SmartBook v5, p2–p7) |
| Customer / Agent / Counter / Admin modes, mode from the session only | Live (`SmartBook::sessionMode`, `admin/smartbook.php`) |
| Staff only on `.network`, no code field on the customer page | Live (`index.php` strips `<!--staff-->`, D1/D13) |
| Route / fare / date / seat / gender defaults, manual seats, extra seats | Live (`SmartBook::defaults`, AutoBerth, `DefaultRules`) |
| Cash / Online, idempotent book, payment verification by the office | Live (`SmartBook::book`, `smartpay.php`, cash book) |
| QuickBot, Seat AI, 72-berth layout, WhatsApp, reports | Untouched |
| **Agent code at the counter desk** | **Gap.** The staff card never sent one, so every counter / admin SmartBook sale fell to the company code (`attributeDeskSale` with a blank code). The old counter mode (`14-counter.js`) had a mandatory agent picker; the new card did not. |

## What this release adds

- **Agent picker on the staff card** (`admin/smartbook.php` splices `#sbAgent`
  above the passengers row; the customer template is untouched). A searchable
  list (`<datalist>`) of every active agent with an SHG number — type a code,
  a number, a name or a city; Enter / pick resolves it and moves on to the
  mobile. **Other / company** credits the company's own code on purpose.
- **Agent login = locked code.** An agent session sees its own SHG code with a
  lock; nothing is typed (D13 unchanged). An admin opening *Agent mode* picks
  like a desk.
- **Desk memory.** The pick is kept per clerk in `localStorage`
  `shg_ctr_agent_<id>` — the same key counter mode and QuickBot use — so one
  pick serves the shift and survives *Book another*.
- **Server validates every sale** (`api/smartbook.php` → `AgentWallet::deskAgentCode`):
  a typo or a suspended agent is refused with the field named, never silently
  credited to the company. `smartbook_agent_required` (default on, switch on
  the SmartBook rules page → Booking limits) makes the pick compulsory.
- **Attribution + accounting** through the existing engine: `create()` →
  `attributeDeskSale` → `reassignSeller`. The commission row moves to the
  picked agent; the cash stays with the clerk who took it (`cash_due` on the
  clerk); the ticket / 80 mm receipt print the agent (`Ticket::issuedBy`).
- **The card says who got it**: "🤝 Credited to SHG-0007 · Ram Sharma · ₹200
  commission" on the ticket card and in the toast (staff answers only; a
  customer's answer carries no agent field, as before).
- One agent list for every door: `AgentWallet::sellableAgents()` feeds
  `index.php` (counter mode / QuickBot), the staff card and the new
  `action: 'agents'` of the API.
- The idempotency fingerprint now includes the credited agent: the same key
  with another agent is refused as a different request.

## Files

`includes/agentwallet.php` (sellableAgents, deskAgentCode), `includes/smartbook.php`,
`api/smartbook.php`, `admin/smartbook.php`, `admin/smart-rules.php`, `index.php`,
`assets/js/27-smartbook.js`, `assets/css/smartbook.css`, `sw.js` (shg-v218, assets
`20261015g`), `app.template.html` (stamps only).

## Migrations

None. `smartbook_agent_required` is read with a default of **on** and is
written by the rules page on first save.

## Tests

- `tests/smartbook-engine-test.php` section H: counter Cash + SHG-0007 →
  confirmed, credited to the agent, commission row on the agent, cash on the
  clerk, payment cash/verified, passenger + ticket written, same key = same
  PNR, same key + another agent refused, OTHER → company code, a customer's
  payload code credits nobody.
- `tests/smartbook-ui-test.php`: the field lives in the staff page only, the
  locked code for agents, the shared desk-memory key, the payload / validation
  / refusal wiring, the rules switch, the shared agent list.
- Browser (local): counter mode → typed "ram" → SHG-0007 · Ram Sharma → Cash →
  booking `sold_by_admin_id` = agent, ledger commission ₹200 on the agent,
  cash_due ₹2,200 on the clerk, 80 mm receipt opened.

## Rollback

`deploy/deploy-prod.sh` keeps the touched files + a DB dump under
`/root/backups/pre-deploy-release-20261015-p8-<ts>/` with `ROLLBACK.txt`. No
schema change, so the previous files alone restore p7.
