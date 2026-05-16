# Mobile Adapter Migration — Remaining Debt Registry

**Created:** Saturday, May 16, 2026 (M3.2.X.1.5-H closure)
**Purpose:** Catalog of gaps surfaced during M3.2.X.1.5 that should be addressed in future phases.

## Why this registry exists

M3.2.X.1.5 migrated ~101 user-facing `networkService.post_request` call
sites to `MobileNetworkAdapter`. The migration was mechanical (drop-in
signature swap), but during the work several **structural gaps in the
api-client routing layer** surfaced. These don't block the migration —
the adapter handles them by falling through to legacy — but they should
be closed in future phases for full v3 coverage.

## Gap categories

### Category 1: Endpoints with no feature-flag entry (adapter falls through to legacy)

These mobile endpoints have no entry in `packages/api-client/src/feature-flags.ts`. The adapter correctly falls through to `NetworkService` (legacy behavior identical to today). They should each be added with `target='old'` initially when v3 endpoints are designed.

| Legacy path | Mobile usage | Suggested target phase |
|---|---|---|
| `customer/decreaseItem` | cart.page (DecreaseItem) | M3.2.Z (cart consolidation) |
| `customer/settings/measurement/read-measurement` | measurements.page, product.page | M3.2.Z |
| `customer/settings/measurement/update-measurement` | measurements.page, product.page | M3.2.Z |
| `customer/addWishlist` | account, best-sellers, new-arrivals, category, vertican, search, cart, wishlist | M3.2.Z wishlist phase |
| `customer/removeFromWishlist` | wishlist | M3.2.Z wishlist phase |
| `customer/readWishlistLabel` | many catalog pages | M3.2.Z wishlist phase |
| `customer/addWishlistLabel` | (mentioned in code; no callers found) | M3.2.Z wishlist phase |
| `customer/follow_vendor` | vendors.page | M3.2.Z vendor follow phase |
| `customer/unfollow_vendor` | vendors.page | M3.2.Z vendor follow phase |
| `customer/add_review` | reviews surfaces | M3.2.Z reviews phase |
| `customer/deleteReview` | reviews | M3.2.Z |
| `customer/make_helpful` | reviews | M3.2.Z |
| `customer/createTicket` | tickets | M3.2.Z support/tickets phase |
| `customer/orderDetails` | orders | M3.2.Z order detail v3 |
| `customer/customerOrder` | orders | M3.2.Z |
| `customer/finalizePayment` | checkout (if used) | M3.2.X / M3.3 payment phase |
| `customer/generateTransactionReceipt` | checkout (if used) | M3.2.X / M3.3 payment phase |
| `customer/UpdateLocation` | settings/profile | M3.2.Z |
| `customer/create_style` | styles/create.page | M3.2.Z styles phase |

**Total: ~19 endpoints without flag entries.**

### Category 2: Chat service endpoints (no v3 endpoints exist yet)

All 10 chat endpoints used in `service/chat.service.ts` have no feature-flag entries because no v3 chat endpoints have been designed yet. The adapter falls through to legacy for all of them.

| Legacy path | Method | Target phase |
|---|---|---|
| `customer/chat/get_vendors` | POST | M4 chat v3 design |
| `customer/chat/get_vendor_orders` | POST | M4 |
| `customer/chat/get_or_create_conversation` | POST | M4 |
| `customer/chat/get_vendor_conversations` | POST | M4 |
| `customer/chat/get_messages` | POST | M4 |
| `customer/chat/send_message` | POST | M4 |
| `customer/chat/mark_read` | POST | M4 |
| `customer/chat/upload_image` | POST (multipart) | M4 |
| `customer/chat/get_unread_count` | POST | M4 |
| `customer/chat/get_prompts` | POST | M4 |

Chat v3 design is deferred to M4 (Tier 3 features per master plan).

### Category 3: Adapter test coverage

The migration added ~101 new consumers of `MobileNetworkAdapter`. The adapter's existing test coverage (`apps/mobile/src/app/core/http/mobile-network-adapter.spec.ts`) was designed for the original ~10 consumers from M3.1.x. It does NOT explicitly test:

- Path-param substitution for `/v3/vendors/by-legacy-id/:id` (read_vendor pattern)
- Fall-through behavior for endpoints without feature-flag entries (cart.page::decreaseItem, etc.)
- The `shape='v2'` envelope passthrough for `/v3/checkout/initiate`
- Concurrent calls from the same consumer (cart.page hits multiple endpoints in rapid succession)

**Suggested phase:** M3.2.X.1.6 (adapter test hardening) or roll into Stream Z mobile cleanup.

### Category 4: Constructor signature consistency

Some pages now have BOTH `networkService` AND `networkAdapter` injected:
- account.page: 6 calls now on adapter, but networkService may still be needed for non-catalog calls
- All ~30 page files migrated have both DIs

Once a page's ALL calls are confirmed to be on the adapter (and fallthrough works for everything not flagged), the `networkService` DI can be removed. This is mechanical cleanup but not urgent.

**Suggested phase:** Stream Z mobile cleanup; combine with mutation typing refactor.

### Category 5: `response: any` cast pattern

Every migrated subscribe callback adds `(response: any)` to satisfy strict + noImplicitAny. The adapter signature is `Observable<unknown>`; legacy call sites inspect `response.response_code`, `response.status`, `response.data`, `response.message`.

The right fix is to type the adapter return as `Observable<LegacyEnvelope<T>>` where `LegacyEnvelope` is a documented interface for `{ response_code, status, data?, message? }`. Then each call site can either:
- Be typed at the API client layer
- Continue to use `any` until per-endpoint types are designed

**Suggested phase:** M3.2.Z mobile cleanup (typed-envelope refactor; ~3-5 days).

### Category 6: Stale comments in feature-flags.ts

During M3.2.X.1.5-E pre-flight inspection, the comment around `POST /checkout/initiate` said *"flipped back to 'old' pending mobile rewrite"* but the entry showed `target='new'`. Either the comment is stale OR the flag was re-flipped without comment update.

**Action:** Audit feature-flags.ts comments vs current target values; reconcile. Likely a 1-hour cleanup task.

**Suggested phase:** M3.2.X.1-C-FLIP commit (operator-driven; can fold in alongside the best_sellers flip).

### Category 7: Adapter internal usage

`mobile-network-adapter.ts` itself uses `networkService.post_request` and `networkService.get_request` as the fallthrough targets when no flag matches. These are CORRECT and should NOT be migrated — they're the adapter's escape hatch.

The grep results showing 2 `networkService.post_request` occurrences in `mobile-network-adapter.ts` reflect this. These are not debt; they're architecture.

## Total debt summary

| Category | Items | Estimated effort to clear |
|---|---|---|
| 1. Endpoints without flag entries | 19 | 2-3 days (design + add entries + tests) |
| 2. Chat service v3 design | 10 endpoints | M4 scope (substantial; backend design needed) |
| 3. Adapter test hardening | 4 test gaps | 1 day |
| 4. Constructor DI cleanup | ~30 files | 1-2 days (mechanical) |
| 5. Typed envelope refactor | All call sites | 3-5 days |
| 6. Feature-flags comment audit | 1-hour task | Roll into M3.2.X.1-C-FLIP |
| 7. Adapter internal usage | N/A — not debt | (architectural) |

**Total estimated remaining work: ~7-12 days across multiple phases.**

## Recommended next action

None of this blocks M3.2.X.1.5 closure or M3.2.X.2 onwards. The recommended sequence:

1. **M3.2.X.1-C-FLIP** (operator-driven) — fold in Category 6 cleanup
2. **M3.2.X.2** through **M3.2.X.7** — proceed with Stream X feature phases
3. Address Category 1 (endpoint flag entries) as each Stream X phase touches the relevant endpoints — e.g., M3.2.X.6 vendor lifecycle adds vendor follow flag entries
4. **M3.2.Z mobile cleanup** — bundle categories 3, 4, 5 into a dedicated Mobile Stream Z phase (~5-8 days)
5. **M4** — Category 2 (chat v3 design)

## How to update this registry

Each time a category item is closed (entry added, test written, refactor shipped), strike it through here and add a commit reference. Aim to keep this registry green and current — when it's empty, the migration is fully done.

Format:

```markdown
| ~~Legacy path~~ | ~~Mobile usage~~ | Closed in M3.2.X.6.2 (commit abc123) |
```
