# Stream X Scope Revision (May 18, 2026)

**Purpose:** Document the three product decisions made on May 18, 2026 that revise the remaining Stream X scope, plus the locked Tier 2 ordering for the residual phases.

**Status:** Decisions locked; Pass 1 of "finish Stream X" implementation in progress.

---

## 1. Product decisions

Three decisions taken during a planning conversation with the project owner:

### Decision 1 — Noon: live credentials from legacy

**Old assumption:** Stream X phases that touch Noon (notably X.5 — dispute eventType empirical capture) need an operator-driven manual trigger in the Noon sandbox.

**New decision:** The 3bayti deployment runs against **live Noon production credentials inherited from the legacy app**. Real production traffic delivers real Noon events. No sandbox triggers needed.

**Implication for X.5:** Phase reframed from "blocked on manual sandbox dispute trigger" to "observability hook + read-only audit script that surfaces any unknown dispute eventTypes from production data." See `m3.2.x.5-completion.md` for the implementation detail.

### Decision 2 — No saved payment methods

**Old assumption:** Tier 2 mapping included **M3.2.X.9 (Saved payment methods — Noon vault tokenization)** as a possible deferred phase: "needs Noon partnership conversation. If unblocked: ~3-5 days."

**New decision:** **Customers will not be allowed to save payment methods.** This is a deliberate product choice, not a deferral.

**Implication:**
- M3.2.X.9 is **scoped out of M3 entirely** (not "deferred to a later milestone")
- Stream X envelope reduces by ~3-5 days
- Customer-facing UX implication: every checkout flow enters Noon's hosted page fresh; no "saved cards" affordance on the checkout form
- The Order entity / PaymentTransaction entity have no need for any "saved method" reference column — no migration debt
- This decision can be revisited in M4+ if product priorities change, but the X.9 line is removed from M3 master plan §3 Tier 2 mapping

### Decision 3 — Self-delivery via partner portals

**Old assumption:** Tier 2 mapping included **M3.2.X.16 (Order tracking carrier integration — Aramex/DHL/Naqel tracking numbers)** as a backend phase: "5-7 days."

**New decision:** **Delivery is handled in-house by the operations team manually creating delivery orders on each partner's portal.** No carrier API integration is needed. Customers receive **email updates only** for delivery status, not in-app tracking numbers / tracking URLs.

**Implication:**
- M3.2.X.16 is **scoped out of M3 entirely**
- Stream X envelope reduces by ~5-7 days
- Order entity gets **no new columns** for tracking_number / tracking_url / carrier_id
- OrderSerializer's response shape is unchanged — no tracking block needed
- Customer-facing UX implication: order status page shows order lifecycle (`pending`, `paid`, `fulfilling`, `shipped`, `delivered`) but never a clickable carrier tracking link
- The existing M3.1.7-H email template machinery handles the email update channel — no new templates needed for this decision
- This decision can be revisited if 3bayti later integrates with carrier APIs, but the X.16 line is removed from M3 master plan §3 Tier 2 mapping

---

## 2. Updated Stream X scope

### Already shipped (8 sub-phases)
- M3.2.X.1 ✓ — best_sellers v3 endpoints
- M3.2.X.1.5 ✓ — Mobile NetworkService → NetworkAdapter
- M3.2.X.2 ✓ — Featured vendors
- M3.2.X.3 ✓ — Categories augmentation
- M3.2.X.4 ✓ — Notification logs
- M3.2.X.6 ✓ — Vendor lifecycle states
- M3.2.X.7 ✓ — Arabic email templates
- M3.2.X.8 ✓ — Promo Code engine

### Closing in Pass 1 (this commit)
- **M3.2.X.5** — Dispute eventType (observability hook + audit script). See `m3.2.x.5-completion.md`.

### Scoped out per Decisions 2 + 3
- ~~M3.2.X.9~~ — Saved payment methods (out per Decision 2)
- ~~M3.2.X.16~~ — Order tracking carrier integration (out per Decision 3)

### Remaining Tier 2 backend phases (locked ordering)

To be shipped in Pass 2 as 7 sequential phases, each its own structured plan with approval gate per project convention:

| Order | Phase | Effort | Rationale for position |
|---|---|---|---|
| 1 | **M3.2.X.18** — Returns request flow | 7-10 days | Tier 1 ecommerce baseline; biggest customer/regulatory surface; touches Order entity which everything else builds on |
| 2 | **M3.2.X.10** — Faceted search backend | 5-7 days | Unblocks Stream Y catalog page work (M3.2.Y.2) |
| 3 | **M3.2.X.14** — Vendor performance metrics | 3-5 days | Small; uses existing M3.1.7-D audit data |
| 4 | **M3.2.X.17** — Admin order timeline | 3-5 days | Small; uses existing audit data |
| 5 | **M3.2.X.11** — Abandoned cart recovery emails | 3-5 days | Uses M3.1.7-H email + M3.2.X.4 logging |
| 6 | **M3.2.X.15** — Multi-currency display | 3-5 days | Touches money fields across the codebase; later is safer |
| 7 | **M3.2.X.13** — Vendor analytics dashboard | 5-7 days | Biggest scope; can split between Stream X backend + M3.3 portal UI |
| 8 | **M3.2.X.12** — Recommendations engine | 5-7 days | Last; nice-to-have, biggest "moves" if priorities shift |

**Note:** The X-numbering is preserved as labels (X.10, X.11, etc.) for traceability against the original master-plan mapping. The above table is the work-order, not the numerical order.

### Closing Pass 3 — Stream X acceptance gate

After Pass 2's 8 phases ship, Stream X formally closes:
- All Stream X sub-phases shipped + closed (acceptance gate checkbox 1)
- No new regressions in mobile or backend baselines (acceptance gate checkbox 2)
- Operator pre-flight for deferred items completed — X.5 audit-script run on production confirms no unknown dispute strings, vendor approval workflow tested with admin (acceptance gate checkbox 3)

After all three checkboxes are ticked, Stream Y can start (per Q2 / Q-Streams strict X→Y→Z sequencing in the master plan).

---

## 3. Stream X envelope recalculation

| Item | Before May 18 | After May 18 |
|---|---|---|
| Sub-phases shipped | 8 | 8 (X.5 ships in Pass 1) |
| Sub-phases remaining | 11 (X.5 + X.9 + X.10-X.18) | 8 (X.10, X.11, X.12, X.13, X.14, X.15, X.17, X.18) |
| Estimated remaining effort | ~46-65 days | ~34-49 days |
| Reduction from scope-out | — | ~8-12 days (X.9 + X.16) |

### Master plan revisions in this Pass 1 commit

- §2 Stream X table: new M3.2.X.5 row, marked ✅ code complete with the new observability approach
- §3 Tier 2 mapping:
  - X.9 (saved payment) struck out with note: "scoped out per product decision May 18, 2026 — customers will not be allowed to save payment methods"
  - X.16 (order tracking carrier integration) struck out with note: "scoped out per product decision May 18, 2026 — self-delivery via partner portals; customers receive email updates only"
- Stream X effort note updated: "New Stream X effort: ~9-13 weeks (was 3-4)" amended to reflect the scope-out

### Operator playbook revisions in this Pass 1 commit

- §4.B (was "M3.2.X.5 — Dispute eventType empirical capture, blocks implementation entirely") rewritten with the new audit-script approach
- §6 cross-reference table extended with X.5 closure runbook

---

## 4. What was NOT decided

A few related questions were surfaced during the conversation but **deferred** explicitly:

- **Customer-visible tracking link from manual-creation partner portals.** Decision 3 confirms no carrier API integration; customers get email updates only. The question of "should admin be able to paste a tracking URL into the order admin page and have it surface on the customer's order detail screen" is **out of M3.2.X scope** — slot for a small M3.3 admin enhancement if needed.
- **Returns flow vs Noon refund mechanics.** M3.2.X.18 (returns) will need its own structured plan to disambiguate the customer-facing "request return" UX from the operational refund-issuing flow already partially in place via Noon. Plan that phase carefully when it starts.

---

## 5. Sign-off

- [x] Decisions 1, 2, 3 captured and locked
- [x] X.5 reframed and shipping in Pass 1
- [x] Master plan §2 + §3 revisions queued for Pass 1 commit
- [x] Operator playbook §4.B revision queued for Pass 1 commit
- [x] Tier 2 ordering locked for Pass 2

**Pass 1 status: ✅ ready to commit.**
