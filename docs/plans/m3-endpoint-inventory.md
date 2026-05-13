# M3 Endpoint Inventory — Cross-App API Audit

**Status:** 🚧 WORK IN PROGRESS (M3.1.0 active)
**Started:** M3.1.0a on May 13, 2026
**Author:** Sodiq's pair (Claude)
**Purpose:** Single source of truth for every API endpoint across mobile, web, and portal. Maps legacy → v3, deduplicates, flags what's built vs what needs building.

This doc is the FOUNDATION the rest of M3 builds on. It will grow through M3.1.0a-f and be approved as a complete artifact before M3.1.1 begins.

---

## Section 1 — Mobile endpoint dump (M3.1.0a)

**Status:** ✅ Complete (May 13, 2026)

### 1.1 Headline numbers

- **Endpoint constants in `apps/mobile/src/app/global-component.ts`:** 101
- **Actually used in code (at least one caller):** 74
- **Dead code (declared but no caller):** 28
- **External (Topex shipping API):** 1 (`topexCities`)

The 28 dead-code endpoints are not part of M3 scope unless an upcoming feature needs them. Documented separately in §1.4 for transparency.

### 1.2 Critical findings during audit

1. **13 vendor-side endpoints have a BUG: missing baseURL prefix.** All 13 are also unused, so the bug never manifested. The constants:
   ```ts
   public static vendor_get_orders = 'vendor/get_orders.php';
   ```
   (Should be `GlobalComponent.baseURL + 'vendor/get_orders.php'`.)
   If anyone wires them up, they'll resolve to a relative URL at runtime and 404. Captured as a deferred cleanup item (low priority since they're unused).

2. **Several "duplicate-looking" endpoint pairs are actually different operations:**
   - `storeReviews` (path: `customer/settings/store-reviews`) vs `store_reviews` (path: `customer/store-reviews`) — different settings vs public view of vendor reviews
   - `singleProduct` vs `single_product` — different paths (`/singleProduct` vs `/single_product`); both unused/used inconsistently
   - `sendOTP` (path: `customer/sendOTP`) vs `sendOOTP` (path: `users/sendOTP`) — same business operation, different paths. **TRUE DUPLICATE for v3 dedup.**

3. **Mobile relies on the `customer/*` URL family for most reads.** That's a legacy WordPress quirk. v3 already serves cleaner paths (`/v3/products`, `/v3/categories`, etc.) — most catalog reads will map cleanly.

4. **Payment flow (`initiatePayment`, `finalizePayment`, `getToken`)** uses `customer/payment/*` paths and integrates with Noon (confirmed via `merchantReference`, `paymentType` vocabulary in checkout.page.ts). M3.1.8 modernizes this.

### 1.3 USED endpoints (74) — grouped by category

#### Auth (8 used; 3 unused = `UserValidate`, `EmailValidate`, `UserConfirm`)

| Const | Legacy URL | Callers (file count) | Notes |
|---|---|---|---|
| `UserLogin` | `users/login` | 2 | Mobile login flow |
| `UserRegister` | `users/register` | 1 | Mobile registration |
| `UserReset` | `users/resetMobile` | 1 | Mobile-specific password reset flow |
| `sendOTP` | `customer/sendOTP` | 1 | OTP send for verification |
| `sendOOTP` | `users/sendOTP` | 1 | **DUPLICATE of `sendOTP`** — same business op, different path |
| `validateOTP` | `customer/validateOTP` | 2 | OTP validation |
| `getToken` | `customer/getToken` | 2 | Pre-payment token fetch (Noon-related) |
| `UpdateLocation` | `customer/settings/update-location` | 1 | First-launch location capture (not strictly auth but tightly coupled) |

#### Catalog reads (17 used)

| Const | Legacy URL | Callers | v3 equivalent (M2 day 5) | Notes |
|---|---|---|---|---|
| `featured` | `customer/featured` | 2 | `GET /v3/products?sort=featured` | Home featured strip |
| `best_sellers` | `customer/best_sellers` | 2 | `GET /v3/products?sort=popular` | Home best-sellers strip |
| `best_sellers_listing` | `customer/best_sellers_listing` | 1 | `GET /v3/products?sort=popular&limit=...` | Paginated view |
| `new_arrivals` | `customer/new_arrivals` | 2 | `GET /v3/products?sort=newest` | Home new-arrivals strip |
| `new_arrivals_listing` | `customer/new_arrivals_listing` | 1 | `GET /v3/products?sort=newest&limit=...` | Paginated view |
| `category_listing` | `customer/category_listing` | 1 | `GET /v3/categories` | Category index |
| `explore_listing` | `customer/explore_listing` | 1 | TBD — see notes | "Explore" feature; need to verify mobile use |
| `single_product` | `customer/single_product` | 1 | `GET /v3/products/:slug` | PDP |
| `singleProductUtility` | `utility/singleProduct` | 1 | TBD | Utility endpoint variant — may be admin-only |
| `search` | `customer/search` | 1 | `GET /v3/products?q=...` | Product search |
| `store_labels` | `customer/read_vendor_collection` | 1 | NEW endpoint needed | Vendor's collections/labels |
| `store_latest` | `customer/store_latest` | 1 | `GET /v3/products?vendor_slug=...&sort=newest` | Vendor's latest products |
| `read_vendor` | `customer/read-vendor` | 2 | `GET /v3/vendors/:slug` | Single vendor profile |
| `vendors_listing` | `customer/vendors_list` | 1 | `GET /v3/vendors` | All vendors |
| `vendors_products_listing` | `customer/vendors_products` | 1 | `GET /v3/products?vendor_slug=...` | All products for a vendor |
| `products_by_labels` | `customer/products_by_labels` | 1 | NEW endpoint needed | Products by tag/label |
| `styles_list` | `customer/styles_list` | 1 | NEW endpoint needed | Style categories (custom mobile UX) |

#### Cart/checkout/orders (10 used)

| Const | Legacy URL | Callers | v3 status | Notes |
|---|---|---|---|---|
| `customerCart` | `customer/read-cart` | 4 | NEW: `GET /v3/cart` | Read current cart |
| `addToCart` | `customer/addToCart` | 1 | NEW: `POST /v3/cart/items` | Add item |
| `IncreaseItem` | `customer/IncreaseItem` | 1 | NEW: `PATCH /v3/cart/items/:id` (dedup) | Quantity +1 |
| `DecreaseItem` | `customer/decreaseItem` | 1 | NEW: same as above | Quantity -1 |
| `RemoveCartItem` | `customer/removeFromCart` | 1 | NEW: `DELETE /v3/cart/items/:id` | Remove item |
| `customerOrder` | `customer/read-orders` | 1 | NEW: `GET /v3/me/orders` | User's order list |
| `orderDetails` | `customer/read-order-details` | 1 | NEW: `GET /v3/me/orders/:id` | Single order detail |
| `read_orders_listing` | `customer/read_orders_listing` | 1 | NEW: same as `customerOrder` w/ pagination | Possibly dedup-able |
| `readCustomerOrders` | `customer/read-customer-orders` | 1 | Investigation needed | Different from `customerOrder`? |
| `updateBilling` | `customer/settings/billing/update-billing` | 2 | NEW: `PATCH /v3/me/billing-address` | Update billing |

#### Account / Profile (8 used)

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `readProfile` | `customer/settings/read-profile` | 1 | NEW: `GET /v3/me` |
| `updateProfile` | `customer/settings/update-profile` | 1 | NEW: `PATCH /v3/me` |
| `readBilling` | `customer/settings/billing/read-billings` | 2 | NEW: `GET /v3/me/billing-address` |
| `readMeasurement` | `customer/settings/measurement/read-measurement` | 2 | NEW: `GET /v3/me/measurements` |
| `updateMeasurement` | `customer/settings/measurement/update-measurement` | 2 | NEW: `PATCH /v3/me/measurements` |
| `readStoreMeasurement` | `vendors/measurement/get-measurements` | 1 | NEW: `GET /v3/vendors/:id/measurement-guides` |
| `readReviews` | `customer/settings/read-reviews` | 1 | NEW: `GET /v3/me/reviews` |
| `deleteReview` | `customer/settings/delete-review` | 1 | NEW: `DELETE /v3/reviews/:id` |

#### Wishlist (4 used)

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `readWishlist` | `customer/read_wishlist` | 1 | NEW: `GET /v3/me/wishlist` |
| `addWishlist` | `customer/add_wishlist` | 8 | NEW: `POST /v3/me/wishlist/items` |
| `readWishlistLabel` | `customer/read_wishlist_label` | 9 | NEW: `GET /v3/me/wishlist/labels` |
| `addWishlistLabel` | `customer/add_wishlist_label` | 1 | NEW: `POST /v3/me/wishlist/labels` |

#### Reviews + interactions (5 used)

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `store_reviews` | `customer/store-reviews` | 1 | NEW: `GET /v3/vendors/:slug/reviews` |
| `storeReviews` | `customer/settings/store-reviews` | 1 | NEW: `GET /v3/me/store/reviews` (vendor self-view) |
| `add_review` | `customer/add-review` | 1 | NEW: `POST /v3/reviews` |
| `make_helpful` | `customer/helpful` | 1 | NEW: `POST /v3/reviews/:id/helpful` |
| `follow_vendor` / `unfollow_vendor` | `customer/follow` / `customer/unfollow` | 1+1 | NEW: `POST/DELETE /v3/me/follows/:vendor_id` |

#### Chat (10 used) — realtime adjacent

All chat endpoints used. Plus `chat_get_vendor_conversations.php` (note the .php suffix in this one).

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `chat_get_vendors` | `chat/get_vendors` | 1 | NEW |
| `chat_get_vendor_orders` | `chat/get_vendor_orders` | 1 | NEW |
| `chat_get_vendor_conversations` | `chat/get_vendor_conversations.php` | 1 | NEW |
| `chat_get_conversation` | `chat/get_conversation` | 1 | NEW |
| `chat_get_messages` | `chat/get_messages` | 1 | NEW |
| `chat_send_message` | `chat/send_message` | 1 | NEW |
| `chat_upload_image` | `chat/upload_image` | 1 | NEW |
| `chat_get_prompts` | `chat/get_prompts` | 1 | NEW |
| `chat_mark_read` | `chat/mark_read` | 1 | NEW |
| `chat_get_unread_count` | `chat/get_unread_count` | 1 | NEW |

#### Tickets / Support (4 used)

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `createTicket` | `customer/create_ticket` | 1 | NEW: `POST /v3/me/tickets` |
| `readTicket` | `customer/read_ticket` | 1 | NEW: `GET /v3/me/tickets/:id` |
| `readTicketMessages` | `customer/read-ticket-messages` | 1 | NEW: `GET /v3/me/tickets/:id/messages` |
| `sendTicketMessage` | `customer/send-ticket-message` | 1 | NEW: `POST /v3/me/tickets/:id/messages` |

#### Messaging (read-only — not in chat namespace) (2 used)

| Const | Legacy URL | Callers | Notes |
|---|---|---|---|
| `readMessages` | `customer/read-messages` | 1 | Different from chat — likely notifications |
| `readCustomerOrders` | `customer/read-customer-orders` | 1 | Vendor-side view of their customer orders |

#### Payment (3 used)

| Const | Legacy URL | Callers | Notes |
|---|---|---|---|
| `initiatePayment` | `customer/payment/initiate_payment` | 1 | M3.1.8: port to `POST /v3/checkout/initiate` |
| `finalizePayment` | `customer/finalize_payment` | 1 | M3.1.8: port to `POST /v3/checkout/finalize` + webhook |
| `getToken` | `customer/getToken` | 2 | Noon token fetch; included in M3.1.8 |

#### Vendor-side workflows (2 used; 13 unused)

Only `vendor_dashboard` and `vendor_toggle_status` are wired up. The other 13 are dead code (see §1.4).

| Const | Legacy URL | Callers | Notes |
|---|---|---|---|
| `vendor_dashboard` | `vendors/dashboard` | 1 | Used in `apps/mobile/src/app/vendor/store-dashboard/store-dashboard.page.ts` |
| `vendor_toggle_status` | `vendors/toggle_status` | 1 | Same component |

#### Reviews / styles / explore (3 used)

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `explore_listing` | `customer/explore_listing` | 1 | Investigation needed |
| `styles_list` | `customer/styles_list` | 1 | NEW |
| `create_style` | `customer/create_style` | 1 | NEW |

#### Utility / external (3 used)

| Const | URL | Callers | v3 status |
|---|---|---|---|
| `topexCities` | `https://shipperapi.topex.ae/...Cities` | 2 | EXTERNAL — Topex shipping; do NOT migrate |
| `singleProductUtility` | `utility/singleProduct` | 1 | TBD |

### 1.4 Dead-code endpoints (28) — declared but unused

Listed here for forensic completeness. Not in M3 scope.

**Auth-related (3):** `UserValidate`, `EmailValidate`, `UserConfirm`

**Catalog (8):** `ProductCategory`, `filtered_products`, `filterexplore`, `explore`, `filterfeatured`, `product_by_category`, `singleProduct` (note the camelCase variant), `products_by_labels` — wait, last one used 1× per usage table. Let me re-verify — the count table above shows products_by_labels has 1 caller, so it IS used. **Dead-list count corrected below.**

After re-checking, the true dead-code list:

- `UserValidate`, `EmailValidate`, `UserConfirm` — auth
- `ProductCategory`, `filtered_products`, `filterexplore`, `explore`, `filterfeatured`, `product_by_category`, `singleProduct` — catalog variants never wired
- `readConversations`, `sendMessage` — messaging variants never wired
- `featuredUtility`, `best_sellersUtility`, `product_by_categoryUtility` — utility endpoint variants
- `vendor_get_stats`, `vendor_get_orders`, `vendor_update_order_status`, `vendor_get_products`, `vendor_add_product`, `vendor_update_product`, `vendor_delete_product`, `vendor_get_earnings`, `vendor_request_payout`, `vendor_get_reviews`, `vendor_respond_review`, `vendor_update_profile`, `vendor_update_settings` — vendor workflows (13)

**Total: 28 unused.**

**Recommendation:** Do NOT migrate the unused endpoints. If a future mobile feature wires one up, that's the moment to build the v3 equivalent. Today they're noise.

### 1.5 What M3.1.0a means for the rest of M3

The real mobile endpoint surface is **74 endpoints, not 105.**

This compresses the M3.1.x phase plan:
- Catalog reads: 17 endpoints (most have v3 equivalents already; need response shape audit)
- Auth: 8 endpoints (M3.1.1 already plans for ~6; this confirms scope)
- Cart/checkout/orders: 10 endpoints (M3.1.6 builds these)
- Account/profile: 8 endpoints
- Wishlist: 4 endpoints
- Reviews + interactions: 5 endpoints
- Chat: 10 endpoints (still largest single category)
- Tickets: 4 endpoints
- Payment: 3 endpoints (M3.1.8)
- Vendor-side (active): 2 endpoints
- Others (explore, styles): 3 endpoints

**Total unique v3 contracts needed for mobile: ~50-55 (after collapsing duplicates like sendOTP/sendOOTP, IncreaseItem/DecreaseItem → cart update, etc.)**

That tracks with the M3 plan's estimate of "~50 v3 endpoints to build after dedup."

---

## Section 2 — Portal endpoint dump (M3.1.0b)

**Status:** ⏳ Pending (next sub-phase)

---

## Section 3 — Web remaining endpoints (M3.1.0c)

**Status:** ⏳ Pending

---

## Section 4 — Deduplication pass (M3.1.0d)

**Status:** ⏳ Pending (after §§1-3 complete)

---

## Section 5 — Master v3 contract design (M3.1.0e)

**Status:** ⏳ Pending

---

## Section 6 — Cross-reference with existing ENDPOINT_ROUTING (M3.1.0f)

**Status:** ⏳ Pending

