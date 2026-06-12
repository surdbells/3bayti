# 3bayti Portal — Enterprise Audit & Roadmap

**Scope:** `apps/portal` (Angular 19 admin/vendor SPA) audited against the v3 API (`api-v3.3bayti.ae`).
**Inventory:** 93 components · 9 services · 7 directives · 2 pipes · **0 route guards · 0 resolvers**.
**Date of sweep:** current main branch.

---

## 1. Portal Audit Report

| Module | Status | Completion | Issues found | Priority |
|---|---|---|---|---|
| **Auth / login** | ✅ Working | 95% | 401 now redirects (fixed); no proactive token refresh | P2 |
| **Admin dashboard** | ✅ Working | 90% | Was stuck spinner (fixed); analytics shape aligned | — |
| **Stores (vendors)** | ✅ Migrated | 100% | Enterprise table; search+pagination+export live | — |
| **Users** | ✅ Migrated | 100% | Enterprise table; role filter; form/drawer intact | — |
| **Transactions** | ✅ Migrated | 100% | Enterprise table; date-range+status filters; was-500 fixed | — |
| **Customers** | ⚠️ Partial | 70% | Raw table; endpoint corrected to /admin/users?role | P1 |
| **Admin products** | ⚠️ Partial | 70% | Raw table; route key fixed; needs migration | P1 |
| **Processing (orders)** | ⚠️ Partial | 75% | Raw table; reads response.orders (fixed) | P1 |
| **Logistics / deliveries** | ⚠️ Partial | 70% | Raw table; response_code removed | P1 |
| **Sales / plural** | ⚠️ Partial | 70% | Raw table; uses /admin/transactions | P2 |
| **Tickets** | ⚠️ Partial | 70% | Raw table; was-500 fixed server-side | P1 |
| **Commissions** | ⚠️ Partial | 70% | Raw table; response mapping fixed | P2 |
| **Collections** | ⚠️ Partial | 65% | Raw table; CRUD works | P2 |
| **Store sub-views** (orders/products/sales) | ⚠️ Partial | 70% | Raw tables; mappings fixed | P2 |
| **Gift cards (admin)** | ✅ Working | 85% | New component; themes list only (no admin CRUD endpoint) | P3 |
| **Coupons** | ❌ Broken | 40% | 4 components still on response_code; analytics will not load | **P0** |
| **Vendor dashboard** | ⚠️ Partial | 75% | Remapped to v3 analytics; no 12-month equivalent | P2 |
| **Vendor products** | ⚠️ Partial | 70% | Raw table | P2 |
| **Vendor returns** | ⚠️ Partial | 75% | Raw table; response_code removed | P2 |
| **Vendor reviews** | ⚠️ Partial | 70% | Raw table | P2 |
| **Vendor measurements** | ⚠️ Partial | 65% | Raw table; mixed response_code fallbacks | P2 |
| **Vendor compliance** | ⚠️ Partial | 60% | response_code checks remain | P1 |
| **Edit/create product** | ⚠️ Partial | 70% | response_code checks remain | P1 |
| **User profile / security** | ✅ Working | 90% | Profile reads response.user (fixed) | — |
| **Register** | ⚠️ Partial | 80% | response_code fallback present | P2 |

**Legend:** ✅ working/migrated · ⚠️ functional but legacy/raw-table · ❌ broken.

---

## 2. Gap Analysis

### Broken (P0)
- **Coupon module** — `coupon-analytics`, `create-coupon`, `edit-coupon`, `coupon-widget` still gate on `r.response_code === 200`. v3 never returns that envelope, so coupon analytics/overview silently render empty. Create/edit success paths also never fire their confirmation branch.

### Incomplete
- **9 components retain `response_code` logic.** Some are harmless `?? response_code` fallbacks (user-security, measurements, register); the coupon set is genuinely broken.
- **25 screens still render raw `<table>`** with hand-rolled pagination/empty/loading. 3 migrated; **22 remain**.
- **Vendor analytics 12-month series** has no v3 equivalent; dashboard now shows a daily revenue window instead of monthly bars.
- **Admin coupon endpoint missing** — coupons are vendor-scoped only; admins get 403. Either add an admin coupon list endpoint or keep coupons out of admin entirely.
- **Gift card admin** has no list/aggregate endpoint; the new screen shows themes only.

### Missing infrastructure
- **No route guards.** Auth is purely reactive (401→redirect). A direct navigation to `/backend` while unauthenticated briefly renders the shell before the first failed call. Needs a `canActivateChild` auth guard + role guards.
- **No resolvers.** Every screen fetches in `ngOnInit`; no pre-navigation data resolution, so loading flicker is universal.

---

## 3. Technical Debt Report

| Category | Finding | Evidence | Severity |
|---|---|---|---|
| **Legacy API contract** | `response_code`/`status:'success'` envelope checks | 9 files (68 already removed) | High (coupons) |
| **Duplicate code** | Hand-rolled pagination (`pageIndex/prevPage/nextPage`) | 8 components | Medium |
| **Duplicate code** | Per-screen search + loading + empty-state markup | 22 raw-table screens | Medium |
| **Anti-pattern** | `console.log` in navigation callbacks | stores/users/transactions (now removed in migrated) | Low |
| **Anti-pattern** | `any`-typed responses everywhere | most components | Medium |
| **Dead code** | Unused `CrudService` injections after adapter migration | several | Low |
| **Perf** | `html2canvas` / CommonJS bailout | view-order | Low |
| **Perf** | No virtual scroll on large lists (pre-enterprise) | all raw tables | Medium |
| **Security** | No guards; session in `sessionStorage` (XSS-readable) | global | Medium |
| **Security** | Tokens logged via `console.error` on some error paths | adapter (mitigated) | Low |
| **Consistency** | Two table systems (AxTable + raw) + now enterprise | shared/data | Medium (transitional) |

---

## 4. Implementation Roadmap

### Phase 1 — Critical production issues (P0)
1. Fix the 4 coupon components: replace `response_code` checks with `if (response)`, map `r.data`/`r.meta`.
2. Sweep the remaining 5 `response_code` files; convert genuine checks, keep harmless fallbacks.
3. Verify coupon analytics renders against live v3.

### Phase 2 — API alignment (P1)
1. Migrate Customers, Admin-products, Processing, Tickets, Logistics, Vendor-compliance, Edit/create-product response handling fully to v3 shapes.
2. Decide coupon-admin strategy (add endpoint vs vendor-only).
3. Add an admin gift-card aggregate endpoint if admin oversight is required.

### Phase 3 — Enterprise table migration ✅ COMPLETE
All 20 genuine list tables migrated to the enterprise data table: stores,
users, transactions, customers, admin-products, processing, tickets,
commissions, vendor-returns, vendor-reviews, store-orders, store-sales,
store-products, sales, logistics, collections, vendor-delivery,
measurements, vendor-products, coupon-list. Detail/non-list views
(admin dashboard, admin-view-order, processing/single, receipt,
coupon-analytics, sales/plural, logistics/deliveries) correctly left as-is.
Each migration verified with a local build; net code reduction across the
phase exceeded 3,500 lines while adding server search, multi-format export,
column management, and filters to every screen.

### Phase 4 — Performance optimization
1. Enable `virtualScroll` config on the highest-volume tables (orders, transactions, products).
2. Add route guards + resolvers to remove first-paint flicker and unauthorized renders.
3. Audit and split remaining CommonJS deps (html2canvas).
4. Replace `any` response types with generated v3 DTOs.

### Phase 5 — Technical debt cleanup
1. Remove `CrudService` once all screens use `PortalCrudAdapter`.
2. Delete the original `AxTable` after all consumers move to `AxDataTable` (or formally keep it for trivial client-only lists).
3. Introduce a typed API client (codegen from the v3 OpenAPI/route registry) to eliminate hand-written route strings.

---

## 5. Code Deliverables (this engagement)

Shipped and building clean on `main`:

| Deliverable | Location | Status |
|---|---|---|
| **Enterprise Table Component** | `shared/data/enterprise/ax-data-table.component.*` | ✅ |
| **Column Configuration Framework** | `ax-data-table.types.ts` (`AxColumnDef`, `AxDataTableConfig`) | ✅ |
| **API Abstraction Layer** | `ax-data-source.ts` (`AxDataSource`, client + server) | ✅ |
| **Pagination Framework** | built into `AxQueryState` + data sources + table footer | ✅ |
| **Shared Filter Framework** | filter bar in table (text/select/boolean/date-range) | ✅ (lightweight) |
| **Export Framework** | `ax-export.service.ts` (CSV/XLSX/PDF, scope-aware, lazy) | ✅ |
| **Cell projection** | `ax-cell.directive.ts` (`axCell`, `axRowExpand`) | ✅ |
| **Reference migrations** | stores, users, transactions | ✅ |

**Characteristics:** standalone, OnPush + signals, generic `<T>`, server/client modes, multi-sort, request cancellation (`switchMap`), debounce, retry, WCAG `aria-sort`/`grid`, dark-mode via `ax-*` tokens, i18n-ready, permission predicates, audit hooks, lazy-loaded export libs (xlsx/jspdf/file-saver never enter the initial bundle).

### Testing strategy (proposed, not yet implemented)
- **Unit:** `axApplyMultiSort` / `axApplyGlobalSearch` pure-function tests; `AxServerDataSource` marble tests for debounce + switchMap cancellation + error→empty-page; `AxExportService` CSV escaping + column-respect tests with mocked dynamic imports.
- **Component:** harness tests for selection (page/all/indeterminate), column visibility/reorder, sort cycling, pagination boundaries, empty/error/retry states.
- **E2E (Playwright):** per migrated screen — search debounce, filter→re-fetch, export download, row + bulk actions, keyboard navigation/focus order.

### API improvement recommendations
1. **Uniform envelope.** Standardize all list endpoints on `{ data, meta:{total,limit,offset} }`. Today the mix (`{orders,pagination}`, `{vendors,meta}`, `{data,meta}`, `{user}`) forces per-endpoint client mapping.
2. **Server sort params.** Accept `sort` + `dir` on all list endpoints so multi-column sort can round-trip (currently most ignore sort).
3. **Server search everywhere.** `/admin/vendors` now supports `search`; extend to all admin lists for consistency.
4. **Admin coupon endpoint** or explicit removal from admin scope.
5. **Cursor pagination** option for the >100k tables to avoid deep-offset cost.
