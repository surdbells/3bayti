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


---

## Section 2 — Portal endpoint dump (M3.1.0b)

**Status:** ✅ Complete (May 13, 2026)

### 2.1 Headline numbers

- **Endpoint constants in `apps/portal/src/app/global-component.ts`:** 96
- **Actually used in code:** 86
- **Dead code:** 10
- **Caller file count:** 61 files (audited separately Day 8)

Portal is much cleaner than mobile (10 dead vs mobile's 28). The codebase is more actively maintained.

### 2.2 Critical findings

1. **Three-namespace structure** is clean and intentional:
   - `admin/*` (36 endpoints) — platform admin operations: collections, customer/store activation, transactions, dashboard stats, customer/store/user listings
   - `vendors/*` (47 endpoints) — vendor self-service: products, orders, labels, coupons, measurements, settings, compliance
   - `users/*` (8 endpoints) — shared auth (overlaps with mobile)
   - `utility/*` (5 endpoints) — shared lookups (categories, collections, stores)

   This is much cleaner than mobile's mostly-`customer/*` flat structure. v3 should preserve the three-namespace separation.

2. **Auth overlap with mobile is EXACT for login + register:**
   - Both apps' `UserLogin` → `users/login` ✓ duplicate
   - Both apps' `UserRegister` → `users/register` ✓ duplicate
   - These collapse to a single v3 contract (already implemented).

3. **Auth DIFFERS for reset flow:**
   - Mobile's `UserReset` → `users/resetMobile` (mobile-specific endpoint)
   - Portal's `UserReset` → `users/reset` (different endpoint!)
   - This is NOT a duplicate. v3 needs to either support both flows or design one that handles both.

4. **Naming collision: `readMeasurement` means different things in mobile vs portal:**
   - Mobile's `readMeasurement` → `customer/settings/measurement/read-measurement` (customer's OWN body measurements)
   - Portal's `readMeasurement` → `vendors/measurement/get-measurements` (vendor's measurement GUIDES for their products)
   - These are different business operations sharing a name. v3 should clarify with distinct paths:
     - `GET /v3/me/measurements` — personal body measurements
     - `GET /v3/vendors/:id/measurement-guides` — vendor's published measurement guides

5. **Mobile's `readStoreMeasurement` IS A DUPLICATE of portal's `readMeasurement`** (same URL: `vendors/measurement/get-measurements`). v3 collapses these.

6. **Portal has JWT-aware utilities** (`decodeJWT`, `encodeBase64`, `decodeBase64` in GlobalComponent). Suggests portal is already prepared for token-based auth, which v3 uses. Mobile uses plain bearer tokens; portal may already handle JWT format natively.

7. **No payment endpoints in portal** (makes sense — admin views payments, doesn't initiate them). M3.1.8 Noon work is mobile + web only.

8. **Coupons system is portal-only** (10 endpoints) — not visible from mobile. v3 needs coupon management endpoints but only portal will call them.

### 2.3 USED endpoints (86) — grouped by category

#### Admin operations (admin/*, 36 endpoints)

**Common ops (28 endpoints, all admin/common/*):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `getStoreOrders` | `admin/common/get-store-orders` | 1 | NEW: `GET /v3/admin/stores/:id/orders` |
| `getStoreOrdersByStatus` | `admin/common/getStoreOrdersByStatus` | 1 | NEW: `GET /v3/admin/stores/:id/orders?status=...` (dedup-able) |
| `getAdminProducts` | `admin/common/products` | 1 | NEW: `GET /v3/admin/products` |
| `messageVendor` | `admin/message-vendor` | 1 | NEW: `POST /v3/admin/vendors/:id/messages` |
| `sales` | `admin/common/sales` | 2 | NEW: `GET /v3/admin/sales` |
| `processing` | `admin/common/processing` | 1 | NEW: `GET /v3/admin/orders?status=processing` |
| `processingById` | `admin/common/processingById` | 2 | NEW: `GET /v3/admin/orders/:id/processing` |
| `pluralById` | `admin/common/pluralById` | 2 | NEW: investigate — name is unclear |
| `productsByProcessingId` | `admin/common/productsByProcessingId` | 1 | NEW: `GET /v3/admin/orders/:id/products` |
| `productsByVendorId` | `admin/common/productsByVendorId` | 2 | NEW: `GET /v3/admin/vendors/:id/products` |
| `logistics` | `admin/common/logistics` | 1 | NEW: `GET /v3/admin/logistics` |
| `commissions` | `admin/common/commissions` | 1 | NEW: `GET /v3/admin/commissions` |
| `transactions` | `admin/common/transactions` | 1 | NEW: `GET /v3/admin/transactions` |
| `tickets` | `admin/common/tickets` | 1 | NEW: `GET /v3/admin/tickets` |
| `ticketsMessages` | `admin/common/ticket-messages` | 1 | NEW: `GET /v3/admin/tickets/:id/messages` |
| `sendTicketMessage` | `admin/common/send-ticket-message` | 1 | NEW: `POST /v3/admin/tickets/:id/messages` |
| `ticketsStatus` | `admin/common/ticket-status` | 1 | NEW: `PATCH /v3/admin/tickets/:id/status` |
| `ticketsPriority` | `admin/common/ticket-priority` | 1 | NEW: `PATCH /v3/admin/tickets/:id/priority` |
| `AdminUserRegister` | `admin/common/register` | 1 | NEW: `POST /v3/admin/users` (admin creates new admin user) |
| `AdminUserPassword` | `admin/common/password` | 1 | NEW: `PATCH /v3/admin/users/:id/password` |
| `activateCustomer` | `admin/common/activate-customer` | 2 | NEW: `POST /v3/admin/customers/:id/activate` |
| `deactivateCustomer` | `admin/common/deactivate-customer` | 2 | NEW: `POST /v3/admin/customers/:id/deactivate` |
| `activateStore` | `admin/common/activate-store` | 1 | NEW: `POST /v3/admin/stores/:id/activate` |
| `deactivateStore` | `admin/common/deactivate-store` | 1 | NEW: `POST /v3/admin/stores/:id/deactivate` |
| `deleteStore` | `admin/common/delete-store` | 1 | NEW: `DELETE /v3/admin/stores/:id` |
| `getSingleStore` | `admin/common/getSingleStore` | 1 | NEW: `GET /v3/admin/stores/:id` |
| `getAdminStats` | `admin/common/dashboard-activity` | 1 | NEW: `GET /v3/admin/dashboard` |
| `getCustomers` | `admin/common/get-customers` | 1 | NEW: `GET /v3/admin/customers` |
| `getStores` | `admin/common/get-stores` | 1 | NEW: `GET /v3/admin/stores` |
| `getUsers` | `admin/common/get-users` | 1 | NEW: `GET /v3/admin/users` |

**Collections (4 endpoints):**

| Const | Legacy URL | v3 status |
|---|---|---|
| `createCollection` | `admin/collections/create-collection` | NEW: `POST /v3/admin/collections` |
| `updateCollection` | `admin/collections/update-collection` | NEW: `PATCH /v3/admin/collections/:id` |
| `getCollection` | `admin/collections/get-collection` | NEW: `GET /v3/admin/collections/:id` |
| `readCollection` | `admin/collections/read-collection` | NEW: `GET /v3/admin/collections` (collapse with getCollection?) |

#### Vendor operations (vendors/*, 47 endpoints)

**Labels (4):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `createLabel` | `vendors/labels/create-label` | 4 | NEW: `POST /v3/me/store/labels` |
| `updateLabel` | `vendors/labels/update-label` | 1 | NEW: `PATCH /v3/me/store/labels/:id` |
| `deleteLabel` | `vendors/labels/delete-label` | 1 | NEW: `DELETE /v3/me/store/labels/:id` |
| `readLabel` | `vendors/labels/read-label` | 4 | NEW: `GET /v3/me/store/labels` |

**Orders (6 used; 1 unused):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `getVendorOrders` | `vendors/orders/get-orders` | 1 | NEW: `GET /v3/me/store/orders` |
| `getVendorOrdersByStatus` | `vendors/orders/get-orders-byStatus` | 1 | NEW: same w/ ?status= |
| `getVendorReturnOrders` | `vendors/orders/get-return-orders` | 1 | NEW: `GET /v3/me/store/orders?status=return` |
| `getVendorDeliveryOrders` | `vendors/orders/get-ready-orders` | 1 | NEW: `GET /v3/me/store/orders?status=ready` |
| `getOrderItems` (UNUSED) | `vendors/orders/get-order-items` | 0 | Defer |
| `updateOrderStatus` | `vendors/orders/update-order-status` | 3 | NEW: `PATCH /v3/me/store/orders/:id/status` |
| `getOrderById` | `vendors/orders/getOrderById` | 3 | NEW: `GET /v3/me/store/orders/:id` |

**Products (6):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `getProductById` | `vendors/products/getProductById` | 3 | NEW: `GET /v3/me/store/products/:id` |
| `deleteProductById` | `vendors/products/delete-product` | 1 | NEW: `DELETE /v3/me/store/products/:id` |
| `getProduct` | `vendors/products/get-products` | 4 | NEW: `GET /v3/me/store/products` |
| `createProduct` | `vendors/products/create-product` | 1 | NEW: `POST /v3/me/store/products` |
| `updateProduct` | `vendors/products/update-product` | 2 | NEW: `PATCH /v3/me/store/products/:id` |
| `getProductReviews` | `vendors/products/get-products-reviews` | 1 | NEW: `GET /v3/me/store/products/:id/reviews` |

**Common (4 used; 1 unused):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `getNotifications` | `vendors/common/notifications` | 2 | NEW: `GET /v3/me/notifications` |
| `markNotifications` | `vendors/common/mark_notifications` | 2 | NEW: `POST /v3/me/notifications/mark-read` |
| `getVendorStats` | `vendors/common/dashboard-activity` | 1 | NEW: `GET /v3/me/store/dashboard` |
| `getCompliance` | `vendors/common/compliance` | 1 | NEW: `GET /v3/me/store/compliance` |
| `topSelling` (UNUSED) | `vendors/common/top-selling` | 0 | Defer |

**Settings (12):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `getVendorStore` | `vendors/settings/vendor-store` | 1 | NEW: `GET /v3/me/store` |
| `getVendorPayment` | `vendors/settings/vendor-store-payment` | 1 | NEW: `GET /v3/me/store/payment-settings` |
| `getVendorTax` | `vendors/settings/vendor-store-tax` | 1 | NEW: `GET /v3/me/store/tax-settings` |
| `getVendorNotifications` | `vendors/settings/vendor-store-notifications` | 1 | NEW: `GET /v3/me/store/notification-settings` |
| `updateUserProfile` | `vendors/settings/update-user-basic` | 1 | NEW: `PATCH /v3/me` (overlaps with mobile updateProfile) |
| `updateStoreBasic` | `vendors/settings/update-vendor-store` | 1 | NEW: `PATCH /v3/me/store` |
| `updateStorePayment` | `vendors/settings/update-vendor-payment` | 1 | NEW: `PATCH /v3/me/store/payment-settings` |
| `updateStoreTax` | `vendors/settings/update-vendor-tax` | 1 | NEW: `PATCH /v3/me/store/tax-settings` |
| `updateStoreNotifications` | `vendors/settings/update-vendor-notifications` | 1 | NEW: `PATCH /v3/me/store/notification-settings` |
| `updateStoreStatus` | `vendors/settings/switch-store-status` | 1 | NEW: `PATCH /v3/me/store/status` (active/inactive) |
| `updateCompliance` | `vendors/settings/update-compliance` | 1 | NEW: `PATCH /v3/me/store/compliance` |

**Measurements (4 used; 1 unused):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `createMeasurement` | `vendors/measurement/create-measurement` | 1 | NEW: `POST /v3/me/store/measurement-guides` |
| `updateMeasurement` | `vendors/measurement/update-measurement` | 1 | NEW: `PATCH /v3/me/store/measurement-guides/:id` |
| `readMeasurement` | `vendors/measurement/get-measurements` | 1 | **DUPLICATE of mobile's `readStoreMeasurement`** → `GET /v3/vendors/:id/measurement-guides` |
| `deleteMeasurement` | `vendors/measurement/delete-measurement` | 1 | NEW: `DELETE /v3/me/store/measurement-guides/:id` |
| `getMeasurementById` (UNUSED) | `vendors/measurement/getMeasurementById` | 0 | Defer |

**Coupons (8 used; 2 unused):**

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `createCoupon` | `vendors/coupons/create-coupon` | 1 | NEW: `POST /v3/me/store/coupons` |
| `getCouponById` | `vendors/coupons/get-coupon-by-id` | 2 | NEW: `GET /v3/me/store/coupons/:id` |
| `couponAnalytics` | `vendors/coupons/coupon-analytics` | 2 | NEW: `GET /v3/me/store/coupons/:id/analytics` |
| `getCoupons` | `vendors/coupons/get-coupons` | 1 | NEW: `GET /v3/me/store/coupons` |
| `toggleCouponStatus` | `vendors/coupons/toggle-coupon-status` | 1 | NEW: `PATCH /v3/me/store/coupons/:id/status` |
| `deleteCoupon` | `vendors/coupons/delete-coupon` | 1 | NEW: `DELETE /v3/me/store/coupons/:id` |
| `updateCoupon` | `vendors/coupons/update-coupon` | 1 | NEW: `PATCH /v3/me/store/coupons/:id` |
| `validateCoupon` (UNUSED) | `vendors/coupons/validate-coupon` | 0 | Defer (likely used at customer checkout — not portal) |
| `applyCoupon` (UNUSED) | `vendors/coupons/apply-coupon` | 0 | Defer (same as above) |

#### Shared auth (users/*, 6 used; 2 unused)

| Const | Legacy URL | Callers | Notes |
|---|---|---|---|
| `UserLogin` | `users/login` | 1 | **DUPLICATE of mobile's `UserLogin`** → single v3 `POST /v3/auth/login` |
| `UserRegister` | `users/register` | 1 | **DUPLICATE of mobile's `UserRegister`** → single v3 `POST /v3/auth/register` |
| `UserValidate` | `users/validate` | 1 | NEW: `POST /v3/auth/validate` (mobile has same const but unused there) |
| `UserReset` | `users/reset` | 1 | DIFFERENT from mobile's `users/resetMobile` — see §2.2 note 3 |
| `UserConfirm` | `users/confirm` | 1 | NEW: `POST /v3/auth/confirm-account` |
| `EmailValidate` (UNUSED) | `users/validate-email` | 0 | Defer |
| `UserResetPassword` (UNUSED) | `users/reset` | 0 | DUPLICATE of UserReset same URL different name |
| `UserSettings` (UNUSED) | `users/settings` | 0 | Defer |

#### Utility (5 used)

| Const | Legacy URL | Callers | v3 status |
|---|---|---|---|
| `getUserProfile` | `utility/shared/user` | 1 | **OVERLAPS with mobile's `readProfile`** → single v3 `GET /v3/me` |
| `updateUserPassword` | `utility/shared/change-user-password` | 1 | NEW: `POST /v3/me/password` |
| `UtilityCategory` | `utility/category` | 6 | **OVERLAPS with mobile's `ProductCategory`/v3's `/v3/categories`** |
| `UtilityCollections` | `utility/collections` | 3 | NEW: `GET /v3/collections` (or admin's `readCollection`?) |
| `UtilityStores` | `utility/stores` | 2 | **OVERLAPS with mobile's `vendors_listing`/v3's `/v3/vendors`** |

### 2.4 Dead-code endpoints (10) — declared but unused

| Const | Legacy URL | Notes |
|---|---|---|
| `EmailValidate` | `users/validate-email` | Auth — never wired |
| `UserResetPassword` | `users/reset` | Duplicate of `UserReset` — never wired |
| `UserSettings` | `users/settings` | Never wired |
| `getOrderItems` | `vendors/orders/get-order-items` | Vendor order detail variant |
| `getProductSales` | `admin/common/get-sales` | Admin sales variant — `sales` is the used one |
| `topSelling` | `vendors/common/top-selling` | Vendor-side top sellers — never wired |
| `topAdminSelling` | `admin/common/top-selling` | Admin-side top sellers — never wired |
| `getMeasurementById` | `vendors/measurement/getMeasurementById` | Measurement detail variant |
| `validateCoupon` | `vendors/coupons/validate-coupon` | Likely belongs to CUSTOMER checkout, not portal |
| `applyCoupon` | `vendors/coupons/apply-coupon` | Same as above |

**Recommendation:** Do NOT migrate the 10 unused. Note: `validateCoupon` and `applyCoupon` should likely live in v3 under `/v3/cart/apply-coupon` since they're customer-checkout operations even though declared in portal — flag this for M3.1.6 cart/checkout work.

### 2.5 What M3.1.0b means for the rest of M3

The real portal endpoint surface is **86 used endpoints**, not 97.

This compresses the M3.3.x phase plan:
- Admin operations: 35 endpoints (collections + common)
- Vendor operations: 41 endpoints (products + orders + labels + measurements + coupons + settings + common)
- Shared auth: 6 endpoints (4 overlap with mobile)
- Utility: 5 endpoints (3 overlap with mobile/web)

**Unique-to-portal endpoints (after deduplication with mobile/web): ~70-75**

That's significantly more than mobile's vendor-side coverage (which has only 2 used vendor endpoints) — portal is the authoritative interface for vendor management.

### 2.6 Cross-app duplicate findings (preview of §4 dedup pass)

These are the highest-value dedup wins so far:

| Operation | Mobile const | Portal const | Web | v3 contract |
|---|---|---|---|---|
| Login | `UserLogin` (`users/login`) | `UserLogin` (`users/login`) | existing | `POST /v3/auth/login` ✅ exists |
| Register | `UserRegister` (`users/register`) | `UserRegister` (`users/register`) | existing | `POST /v3/auth/register` ✅ exists |
| Get profile | `readProfile` (`customer/settings/read-profile`) | `getUserProfile` (`utility/shared/user`) | existing | `GET /v3/me` (NEW) |
| Update profile | `updateProfile` | `updateUserProfile` (vendors/settings) | existing | `PATCH /v3/me` (NEW) |
| Categories list | `ProductCategory`+`category_listing` | `UtilityCategory` | existing | `GET /v3/categories` ✅ exists |
| Vendors list | `vendors_listing` | `UtilityStores` | existing | `GET /v3/vendors` ✅ exists |
| Vendor measurement guides | `readStoreMeasurement` (mobile vendor-side) | `readMeasurement` (portal) | n/a | `GET /v3/vendors/:id/measurement-guides` (NEW) |
| OTP duplicate inside mobile | `sendOTP`+`sendOOTP` | n/a | n/a | `POST /v3/auth/send-otp` (NEW) |
| Cart quantity inside mobile | `IncreaseItem`+`DecreaseItem` | n/a | n/a | `PATCH /v3/cart/items/:id` (NEW) |

**Estimated unique v3 endpoints needed across all three apps after dedup: ~80-90 net new (mobile + portal + web specific) on top of v3's existing ~10. Total v3 surface: ~100-110 endpoints.**

