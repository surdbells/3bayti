# Phase C — Legacy Parity Audit (v3 go-live gap analysis)

**Reference:** legacy PHP backend (`surdbells/3bayti_backend`) — the ground-truth feature surface of the old system.
**Compared against:** new `apps/portal` (Angular 19) + v3 API (`apps/api`, 99 admin/vendor routes).
**Method:** every legacy `admin/` (39) and `vendors/` (49) endpoint mapped to its v3 portal status.

Legend: ✅ covered · ⚠️ partial / needs wiring · ❌ missing · 🔵 API-side gap (not portal work)

---

## A. ADMIN parity (39 legacy endpoints)

| Legacy endpoint | Capability | v3 API | Portal screen | Status |
|---|---|---|---|---|
| common/get-stores | list vendors | `GET /admin/vendors` | stores | ✅ |
| common/getSingleStore | store detail | `GET /admin/vendors/:id` | manage-store | ✅ |
| common/activate-store | approve/reactivate | `POST .../approve`,`/reactivate` | manage-store | ✅ |
| common/deactivate-store | suspend | `POST .../deactivate` (vendors) | manage-store | ✅ |
| common/delete-store | delete vendor | `DELETE /admin/vendors/:id` | stores | ⚠️ endpoint exists, no UI button |
| common/get-store-orders | store orders | `GET /admin/orders?vendor_id` | store-orders | ✅ (Phase A) |
| common/getStoreOrdersByStatus | store orders by status | `GET /admin/orders` (status filter) | store-orders | ✅ |
| common/get-customers | list customers | `GET /admin/users?role` | customers | ✅ (Phase A) |
| common/activate-customer | activate | `POST /admin/users/:id/activate` | customers | ✅ |
| common/deactivate-customer | deactivate | `POST /admin/users/:id/deactivate` | customers | ✅ |
| common/get-users | list staff | `GET /admin/users` | users | ✅ (Phase A) |
| common/register | create user | `POST /admin/users` | users | ✅ |
| common/password | reset user pw | `PATCH /admin/users/:id/password` | users | ✅ |
| common/products | all products | `GET /admin/products` | admin-products | ✅ (Phase A) |
| common/productsByVendorId | products by vendor | `GET /admin/products?vendor` | store-products | ✅ |
| common/productsByProcessingId | products in an order | `GET /admin/orders/:id` | admin-view-order | ✅ |
| common/processing | orders processing | `GET /admin/orders` | processing | ✅ (Phase A) |
| common/processingById | order detail | `GET /admin/orders/:id` | admin-view-order | ✅ |
| common/transactions | transactions | `GET /admin/transactions` | transactions | ✅ (Phase A) |
| common/sales | sales | `GET /admin/orders` | sales | ✅ (Phase A) |
| common/pluralById | sale detail | `GET /admin/orders/:id` | plural | ✅ |
| common/commissions | commissions | `GET /admin/commissions` | commissions | ✅ (Phase A) |
| common/logistics | logistics | `GET /admin/vendors` | logistics | ✅ (Phase A, repointed) |
| common/get-returns | returns | `GET /vendor/returns` (admin?) | returns | ⚠️ uses vendor scope; **no admin-wide returns endpoint** 🔵 |
| common/tickets | tickets | `GET /admin/tickets` | tickets | ✅ (Phase A) |
| common/ticket-messages | ticket thread | `GET /admin/tickets/:id/messages` | ticket-messages | ✅ |
| common/send-ticket-message | reply ticket | `POST /admin/tickets/:id/messages` | ticket-messages | ✅ |
| common/ticket-status | set status | `PATCH /admin/tickets/:id/status` | tickets | ✅ |
| common/ticket-priority | set priority | `PATCH /admin/tickets/:id/priority` | tickets | ✅ |
| common/dashboard-activity | dashboard feed | `GET /admin/analytics` | admin (dashboard) | ✅ |
| common/top-selling | top products | (in `/admin/analytics`?) | admin | ⚠️ verify dashboard shows it |
| collections/* (5 files) | collections CRUD | `/admin/collections` (GET/POST/DEL) | collections | ⚠️ **no PUT (update) v3 route** 🔵 |
| message-ticket | ticket msg (alt) | `POST /admin/tickets/:id/messages` | ticket-messages | ✅ |
| message-vendor | DM a vendor | `POST /admin/vendors/:id/messages` | manage-store | ✅ |
| send_notifications | **push broadcast to users** | ❌ none | ❌ none | ❌ **MISSING (admin push)** |

### Admin gaps
- **❌ Admin push/broadcast notifications** (`send_notifications`) — no v3 endpoint, no portal screen. Legacy admins could FCM-push to users. *Decision needed: in-scope for v3 go-live?*
- **⚠️ Delete-store** — `DELETE /admin/vendors/:id` exists but the stores screen has no delete action.
- **🔵 Admin-wide returns** — legacy `get-returns` was global; v3 only has vendor-scoped `/vendor/returns`. Admin returns oversight needs an API endpoint.
- **🔵 Collection update** — collections screen can create/delete but v3 has no `PUT /admin/collections/:id`; edit won't persist.
- **⚠️ top-selling** — confirm the admin dashboard surfaces it (may already be inside `/admin/analytics`).

---

## B. VENDOR parity (49 legacy endpoints)

| Legacy endpoint | Capability | v3 API | Portal screen | Status |
|---|---|---|---|---|
| dashboard / dashboard.php | vendor dashboard | `GET /vendor/analytics` | vendor-analytics | ✅ (Phase B charts) |
| common/dashboard-activity | activity feed | `GET /vendor/analytics` | vendor-analytics | ✅ |
| common/top-selling | top products | `GET /vendor/analytics` | vendor-analytics | ✅ |
| common/notifications | list notifs | `GET /vendor/notifications` | vendor-notifications | ✅ |
| common/mark_notifications | mark read | `POST /vendor/notifications/mark-read` | vendor-notifications | ✅ |
| common/compliance | get compliance | `GET /vendor/store` | vendor-compliance | ✅ |
| orders/get-orders | orders | `GET /vendor/orders` | vendor-orders | ✅ |
| orders/get-orders-byStatus | by status | `GET /vendor/orders?status` | vendor-orders | ✅ |
| orders/getOrderById | order detail | `GET /vendor/orders/:id` | vendor-orders (drawer) | ✅ |
| orders/update-order-status | item status | `PATCH /vendor/orders/.../status` | vendor-orders (drawer) | ✅ |
| orders/get-ready-orders | ready-to-ship | `GET /vendor/orders?status=...` | vendor-delivery | ✅ |
| orders/get-return-orders | returns list | `GET /vendor/returns` | vendor-returns | ✅ (Phase A) |
| orders/get-returns | returns (alt) | `GET /vendor/returns` | vendor-returns | ✅ |
| products/get-products | products | `GET /vendor/products` | vendor-products | ✅ |
| products/getProductById | product detail | `GET /vendor/products/:id` | vendor-products (drawer) | ✅ |
| products/create-product | create | `POST /vendor/products` | create-product / drawer | ✅ |
| products/update-product | update | `PUT /vendor/products/:id` | edit-product / drawer | ✅ |
| products/delete-product | delete | `DELETE /vendor/products/:id` | vendor-products | ✅ |
| products/get-products-reviews | reviews | `GET /vendor/reviews` | vendor-reviews | ✅ (Phase A) |
| labels/* (4 files) | label CRUD | `/vendor/labels` (GET/POST/PUT/DEL) | labels | ✅ |
| measurement/* (5 files) | measurement CRUD | `/vendor/measurements` (full) | measurements | ✅ (Phase A) |
| coupons/get-coupons | list | `GET /vendor/coupons` | coupon-list | ✅ (Phase A) |
| coupons/get-coupon-by-id | detail | `GET /vendor/coupons/:id` | edit-coupon | ✅ |
| coupons/create-coupon | create | `POST /vendor/coupons` | create-coupon | ⚠️ verify (was response_code) |
| coupons/update-coupon | update | `PUT /vendor/coupons/:id` | edit-coupon | ⚠️ verify |
| coupons/delete-coupon | delete | `DELETE /vendor/coupons/:id` | coupon-list | ✅ |
| coupons/toggle-coupon-status | toggle | `PATCH /vendor/coupons/:id/...` | coupon-list | ⚠️ verify route |
| coupons/coupon-analytics | analytics | `GET /vendor/coupons/:id/analytics` | coupon-analytics | ⚠️ verify renders |
| coupons/apply-coupon | apply (checkout) | (customer/checkout flow) | — | 🔵 not a portal screen |
| coupons/validate-coupon | validate (checkout) | (customer flow) | — | 🔵 not a portal screen |
| settings/vendor-store | get store | `GET /vendor/store` | vendor-store | ✅ |
| settings/update-vendor-store | update store | `PATCH /vendor/store` | vendor-store | ✅ |
| settings/vendor-store-payment | get payment | `GET /vendor/store/payment` | vendor-payment | ✅ |
| settings/update-vendor-payment | update payment | `PATCH /vendor/store/payment` | vendor-payment | ✅ |
| settings/vendor-store-tax | get tax | `GET /vendor/store/tax` | vendor-tax | ✅ |
| settings/update-vendor-tax | update tax | `PATCH /vendor/store/tax` | vendor-tax | ✅ |
| settings/vendor-store-notifications | get notif prefs | `GET /vendor/store/notifications` | vendor-store (notifs) | ✅ |
| settings/update-vendor-notifications | update prefs | `PATCH /vendor/store/notifications` | vendor-store | ✅ |
| settings/update-user-basic | update profile | (`/me` or profile) | profile | ✅ |
| settings/update-compliance | update compliance | `PATCH /vendor/compliance` | vendor-compliance | ⚠️ verify (was response_code) |
| settings/switch-store-status | open/close store | `PATCH /vendor/store/status` | vendor-store | ✅ |
| toggle_status / toggle-coupon | misc toggles | various | — | ✅ |

### Vendor gaps
- **⚠️ Coupon create/update/toggle/analytics** — the audit flagged these as `response_code`-gated; my earlier check showed coupon files no longer reference `response_code`, so they're likely **already fixed**, but the create/update/toggle/analytics success paths need a **live-data verification** (can't confirm from static read alone).
- **⚠️ vendor-compliance update** — still has a `response_code` fallback; happy path works, error path may not.
- Everything else: **full vendor parity.** ✅

---

## C. Cross-cutting (infra, already verified earlier)
- Route guards: ✅ (62 routes protected).
- Legacy `response_code` envelope: 7 files remain — **`reset.component` is genuinely broken** (password reset; no `data` fallback), measurements error-branches partial, the rest harmless fallbacks.

---

## D. Phase C proposed work (for approval)

Ordered by value. **Portal-only items I can do now; API-side items (🔵) need a backend decision.**

### C1 — Fix genuinely broken flows (portal-only) ⟵ recommend first
1. **`reset.component`** password-reset — replace legacy `response_code` checks with v3 `if (response?.data)` success/error handling.
2. **`measurements`** — convert the error-branch `response_code` checks (happy path already fixed in Phase A).
3. **Live-verify coupon create/update/toggle/analytics** against v3 shapes; fix any remaining envelope assumptions.
4. **vendor-compliance** update path — confirm/clean the `response_code` fallback.

### C2 — Wire up existing-but-unused capabilities (portal-only)
5. **Delete-store action** on the stores screen (`DELETE /admin/vendors/:id` already exists).
6. **Confirm top-selling** surfaces on the admin dashboard; add if missing.

### C3 — API-blocked parity (needs your backend decision) 🔵
7. **Admin push/broadcast notifications** (`send_notifications`) — no v3 endpoint. In scope for go-live?
8. **Admin-wide returns** oversight — needs an `/admin/returns` endpoint.
9. **Collection update** — needs `PUT /admin/collections/:id` (edit currently can't persist).

---

## E. Headline finding

The new portal already has **near-complete parity** — ~80 of 88 legacy capabilities are covered (most polished further in Phases A–B). The genuine gaps are small and specific: **one broken flow (password reset), a few `response_code` cleanups, two unused-but-available actions, and three API-side decisions.** This is *not* a large feature-build; it's a short close-out list.

---

## F. Phase C — COMPLETED (all committed + pushed to main)

| Sub-phase | Work | Commit | Result |
|---|---|---|---|
| **C1** | Password reset rewired to v3 2-step flow (`/auth/reset` → `/auth/reset/confirm`); measurements + vendor-compliance `response_code` cleanup; coupons verified already-healthy | `391c6ca` | ✅ broken reset flow fixed |
| **C2** | Top-selling products added to `/admin/analytics` API + rendered on admin dashboard; delete-store confirmed already wired | `2a7f642` | ✅ API suite green (1473) |
| **C3.1** | Collection update (`PUT /admin/collections/:id`) — found already wired end-to-end | — | ✅ no work needed |
| **C3.2** | Admin cross-vendor returns screen — API was already built; registered route key + filled the empty portal scaffold (enterprise table, status/date filters, Store column) + routed + sidenav link | `ee97a8a` | ✅ |
| **C3.3** | Admin push broadcast — new `POST /admin/notifications` (audience-targeted fan-out, dead-token pruning, 8 tests) + portal composer (audience picker, preview, delivery summary) + sidenav link | `4002844` | ✅ API suite green (1481) |

### Audit corrections discovered during implementation
Several items this audit flagged as gaps were already done in the codebase (the audit and the older `PORTAL_AUDIT_REPORT.md` overstated remaining work):
- **Coupon module** — already fully migrated to v3 (no `response_code`).
- **Delete-store** — already a confirm-gated action calling `DELETE /admin/vendors/:id`.
- **Collection update** — `PUT /admin/collections/:id` already wired end-to-end.
- **Admin returns API** — `GET /admin/returns` + approve/deny/refund controllers already built; only the portal screen was missing.

The only genuinely-new code Phase C required: the password-reset rewrite, the top-products query, the admin returns *portal screen*, and the admin push broadcast (API + portal).

### Deployment
Still pending for ALL session work (Phases A, B, C). Portal + API both need deploying:
- Portal: `cd apps/portal && pnpm install && pnpm run build && pnpm exec wrangler pages deploy dist/abayti/browser --project-name 3bayti-portal --commit-dirty=true --skip-caching`
- API: `cd /www/wwwroot/3bayti && git pull origin main && /etc/init.d/php-fpm-83 reload`
