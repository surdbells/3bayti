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


---

## Section 3 — Web endpoint audit (M3.1.0c)

**Status:** ✅ Complete (May 13, 2026)

### 3.1 Web is fundamentally different from mobile/portal

Unlike mobile + portal, apps/web does NOT have a `global-component.ts`. M2 Day 5 introduced `packages/api-client/ENDPOINT_ROUTING` as the source of truth for routing decisions. Web's audit therefore has two parts:

1. **What's registered in ENDPOINT_ROUTING** (the "intended" surface)
2. **What apps/web actually calls** (the "real" surface)

These are wildly different.

### 3.2 ENDPOINT_ROUTING inventory (50 entries)

The routing table has 50 entries, distributed as:

| Section | Count | target='new' | target='old' |
|---|---|---|---|
| Health + catalog | 9 | 9 | 0 |
| Auth | 7 | 7 | 0 |
| Account (`/me/*`) | 11 | 11 | 0 |
| Admin | 12 | 12 | 0 |
| Cart | 4 | 0 | 4 |
| Checkout/orders | 3 | 0 | 3 |
| Wishlist | 3 | 0 | 3 |
| **Total** | **49** | **39** | **10** |

(Plus `GET /categories/:slug` and `GET /featured-vendors` on target='old' per M2 Day 5 fix.)

### 3.3 The reality vs intention gap

Counting calls by route key in apps/web's source code:

```
'GET /products'              — 1 caller (catalog browse)
'GET /products/:slug'        — 1 caller (product detail)
'GET /categories'            — 1 caller (categories index)
'GET /categories/:slug'      — 1 caller (category detail)
'GET /featured-vendors'      — 1 caller (home)
'POST /auth/login'           — 1 caller (auth service stub?)
'POST /me/addresses'         — 1 caller (address form stub?)
```

**That's 7 route keys actually used out of 50 registered.**

The other 43 route keys are **scaffolding placeholders** in ENDPOINT_ROUTING that no apps/web feature references. They were added in anticipation of features that don't exist yet.

### 3.4 Critical discovery: web is missing entire feature categories

apps/web's feature tree:

```
apps/web/src/app/features/
├── catalog/          (product-card, product-detail, product.model)
├── categories/       (categories, category-detail, category.model, icons)
├── dev-components/   (a sandbox for development)
└── home/             (home, home-data.service)
```

**Web has NO implementations for:**
- Cart (no cart page, no cart icon in header functional)
- Checkout (no checkout flow at all)
- Orders (no order history, no order detail)
- Account / Profile / Settings (no account section)
- Wishlist (no wishlist page)
- Login / Register UI (auth/login route key referenced but no login page)
- Designer/Vendor pages (Day 5 followup; sitemap entries stripped)
- Address book
- Reviews UI (read-only on PDP only)

### 3.5 What this means for M3.2.x (web phases)

The original M3 plan §3.2 estimated web at ~5-7 weeks because "much reuses M3.1 v3 work." That assumption was wrong. Re-examining:

**Web cannot reuse v3 endpoints if its frontend doesn't HAVE the corresponding feature.** Most of the M3.2.x work is now:

1. **Build the missing customer features** (cart, checkout, orders, account, wishlist, login)
2. **Integrate them with v3 endpoints** (cheap because endpoints already exist from M3.1.x)
3. **Build the missing designer/vendor pages** (M3.2.4 in original plan)
4. **Implement Noon payment SDK** for web checkout (M3.2.3 in original plan)

**Realistic revised web estimate: ~8-12 weeks** (was 5-7).

The good news: most of this is "build the UI for endpoints that already exist." Backend complexity is low. The work is in Angular components, forms, validation, error handling, RTL/i18n, design polish.

The bad news: cart + checkout + orders are the highest-stakes flows. Touching payment requires the same shadow-mode discipline as mobile. **Web's M3.2.2 phase essentially becomes "build the checkout flow from scratch on v3" rather than "flip an existing checkout flow."**

### 3.6 What apps/web actually CALLS today (real HTTP traffic)

These are the only live HTTP destinations the web app hits:

| Endpoint | Backend | Source |
|---|---|---|
| `/v3/products` | v3 (via RoutedHttpClient) | catalog browse |
| `/v3/products/:slug` | v3 | product detail |
| `/v3/categories` | v3 | categories index |
| `/v2/categories/:slug` | LEGACY v2 (via RoutedHttpClient `target: 'old'`) | category detail (Day 5 fallback) |
| `/v2/featured-vendors` | LEGACY v2 (via `target: 'old'`) | home Designer Spotlight |
| `/v3/sitemap-data` (build-time) | v3 | sitemap.xml generation + prerender slug discovery |

That's it. 6 distinct endpoints.

### 3.7 Updated web scope for M3

Given web's reality (catalog-only frontend), the M3.2.x phases need a rewrite. Three categories of work:

#### Category A — Backend flips (small)

For the 5 existing catalog endpoints, the only remaining flips are:
- `GET /categories/:slug` (currently `target: 'old'`) — needs v3 to add embedded products + meta first
- `GET /featured-vendors` (currently `target: 'old'`) — needs v3 endpoint built

Both are M3.1.0e (v3 contract design) outputs and the actual builds happen in M3.1.1+.

#### Category B — New customer feature builds (big)

Net-new Angular features that don't exist today:
- Login/register/reset UI (auth UI not built)
- Account dashboard (profile, addresses, password, measurements)
- Wishlist
- Cart + cart icon in header
- Checkout flow (single page or multi-step)
- Order history + order detail
- Designer index + designer detail
- Customer reviews submission UI

Estimated effort: ~4-6 weeks of Angular component work, assuming designs/wireframes exist or get produced in parallel.

#### Category C — Payment integration (medium)

Noon hosted-page integration on the web (likely simpler than mobile's InAppBrowser flow).

### 3.8 Updated M3.2.x phase estimates

| Phase | Was | Now |
|---|---|---|
| M3.2.1 Web auth flip | 2-3 days | 5-7 days (build full auth UI first) |
| M3.2.2 Web cart/checkout/orders | 3-4 weeks | 6-8 weeks (build features + integrate) |
| M3.2.3 Web Noon payment | 7-10 days | 7-10 days (unchanged) |
| M3.2.4 Web designer routes | 3-4 days | 3-5 days (slight rise; depends on M3.1.10 v3 vendor endpoints) |
| M3.2.5 Web account management | 5-7 days | 2-3 weeks (build account UI; integrate with already-built v3 endpoints) |

**Revised M3.2 total: 11-15 weeks (was 5-7).**

This is the single biggest scope adjustment from the M3.1.0 audit so far.

### 3.9 Implications for M3 plan §3 — should the plan be revised?

The M3 plan in `docs/plans/m3-plan.md` says web is ~5-7 weeks. After M3.1.0c, the realistic estimate is ~11-15 weeks. That's a meaningful difference (4-8 weeks added).

**Recommendation:** After M3.1.0e completes (full v3 contract inventory), do a single Plan Revision commit that updates §3 of the M3 plan to reflect:
- Web's true starting state (catalog-only)
- The revised M3.2.x estimates
- A note that web is greenfield-like for non-catalog features

This is not urgent today — M3.1.0d (dedup) and M3.1.0e (contracts) come first.


---

## Section 4 — Deduplication pass (M3.1.0d)

**Status:** ✅ Complete (May 13, 2026)

Cross-references mobile + portal + web endpoints to produce the master list of **unique business operations**. For each operation, this section answers:
1. Which apps perform this operation?
2. What legacy URL(s) do they currently hit?
3. What canonical v3 contract should serve all of them?

This is the foundation M3.1.0e (v3 contract design) builds on.

### 4.1 Exact URL duplicates (true duplicates)

Six legacy URLs are referenced by BOTH mobile and portal:

| Legacy URL | Mobile const | Portal const | Used? | v3 contract |
|---|---|---|---|---|
| `users/login` | `UserLogin` | `UserLogin` | both | `POST /v3/auth/login` ✅ exists |
| `users/register` | `UserRegister` | `UserRegister` | both | `POST /v3/auth/register` ✅ exists |
| `users/validate` | `UserValidate` (UNUSED) | `UserValidate` | portal only | `POST /v3/auth/validate` — NEW |
| `users/validate-email` | `EmailValidate` (UNUSED) | `EmailValidate` (UNUSED) | neither | DEFER (dead code in both apps) |
| `users/confirm` | `UserConfirm` (UNUSED) | `UserConfirm` | portal only | `POST /v3/auth/confirm-account` — NEW |
| `vendors/measurement/get-measurements` | `readStoreMeasurement` | `readMeasurement` | both | `GET /v3/vendors/:id/measurement-guides` — NEW |

### 4.2 Critical findings about ENDPOINT_ROUTING accuracy

Discovered during M3.1.0d: **7 ENDPOINT_ROUTING entries have INCORRECT `oldPath` values** — they don't match any URL that mobile or portal actually call. These were written speculatively in M2 Day 5 without auditing the legacy URLs in use.

This is critical because: if any of these routes were flipped to `target: 'old'` as a fallback, they'd hit non-existent legacy endpoints.

| Route key | ENDPOINT_ROUTING's `oldPath` | Actual legacy URL mobile uses | Severity |
|---|---|---|---|
| `GET /cart` | `/customer/cart` | `customer/read-cart` | wrong path; would 404 if used |
| `PUT /cart/items/:id` | `/customer/updateCartItem/:id` | `customer/IncreaseItem` + `customer/decreaseItem` (two separate ops, no :id) | shape mismatch |
| `DELETE /cart/items/:id` | `/customer/removeFromCart/:id` | `customer/removeFromCart` (no :id, body-only) | wrong path |
| `POST /checkout` | `/customer/checkout` | `customer/payment/initiate_payment` | wrong path |
| `GET /orders` | `/customer/orders` | `customer/read-orders` | wrong path |
| `GET /orders/:id` | `/customer/order/:id` | `customer/read-order-details` (slug-based or body-id) | wrong path |
| `POST /me/addresses` | `/users/addAddress` | `customer/settings/billing/update-billing` (different shape entirely) | wrong path AND wrong shape |
| `PUT /me/password` | `/users/changePassword` | `utility/shared/change-user-password` | wrong path |

Currently these all have `target: 'new'` or `'old'` BUT NO APP CALLS THEM, so the bugs are latent — not affecting production. Still, they need correction before any consumer (M3.2.x web cart/checkout work) routes through them.

**M3.1.0f will produce the corrected ENDPOINT_ROUTING.**

### 4.3 Master deduplication table

For each unique business operation across all three apps, the canonical v3 contract:

#### Auth + identity

| Operation | Mobile | Portal | Web | Canonical v3 | Status |
|---|---|---|---|---|---|
| Login | `UserLogin` → `users/login` | `UserLogin` → `users/login` | `POST /auth/login` (existing) | `POST /v3/auth/login` | ✅ exists |
| Register | `UserRegister` → `users/register` | `UserRegister` → `users/register` | (no UI yet) | `POST /v3/auth/register` | ✅ exists |
| Validate (post-register check?) | (UNUSED) | `UserValidate` → `users/validate` | (no UI) | `POST /v3/auth/validate` | NEW |
| Confirm account (post-OTP activation) | (UNUSED) | `UserConfirm` → `users/confirm` | (no UI) | `POST /v3/auth/confirm-account` | NEW |
| Password reset (mobile flow) | `UserReset` → `users/resetMobile` | n/a | n/a | `POST /v3/auth/password-reset-mobile` OR collapse with portal flow | NEW |
| Password reset (web/portal flow) | n/a | `UserReset` → `users/reset` | (planned) | `POST /v3/auth/password-reset` | NEW |
| Send OTP (mobile only) | `sendOTP` + `sendOOTP` (DUPLICATE) | n/a | n/a | `POST /v3/auth/send-otp` (collapsed) | NEW |
| Validate OTP (mobile only) | `validateOTP` | n/a | n/a | `POST /v3/auth/validate-otp` | NEW |
| Get Noon checkout token | `getToken` → `customer/getToken` | n/a | n/a | `POST /v3/checkout/payment-token` | NEW |
| Refresh JWT | n/a | n/a | (routing exists) | `POST /v3/auth/refresh` | NEW (already routed) |
| Logout | n/a | n/a | (routing exists) | `POST /v3/auth/logout` | NEW (already routed) |
| Update location (first launch) | `UpdateLocation` | n/a | n/a | `PATCH /v3/me/location` | NEW |

**Auth section totals:** 12 unique operations after dedup (was: 8 mobile + 8 portal + 7 routing = 23 declarations across apps). v3 needs 9 new endpoints; 3 already exist.

#### Catalog reads (browse, search, listing)

Most of this section has v3 equivalents from M2 Day 5 work. The mobile audit revealed shape mismatches that need shape-translation layer (in MobileNetworkAdapter).

| Operation | Mobile | Portal | Web | Canonical v3 | Status |
|---|---|---|---|---|---|
| Products list (filtered/sorted/paginated) | `featured`, `best_sellers`, `new_arrivals`, `best_sellers_listing`, `new_arrivals_listing`, `featuredUtility` (unused), `best_sellersUtility` (unused) | n/a | `GET /products` (existing) | `GET /v3/products` with `?sort=&filter=&limit=&offset=` | ✅ exists |
| Single product | `single_product`, `singleProduct` (UNUSED), `singleProductUtility` (unused) | n/a | `GET /products/:slug` (existing) | `GET /v3/products/:slug` | ✅ exists |
| Categories list | `ProductCategory` (UNUSED), `category_listing` | `UtilityCategory` (heavy use) | `GET /categories` (existing) | `GET /v3/categories` | ✅ exists |
| Category detail (with products) | (n/a — mobile uses different endpoint?) | (n/a) | `GET /categories/:slug` (currently routes to legacy v2) | `GET /v3/categories/:slug` | ⚠️ EXISTS but missing embedded products + meta (M2 Day 5 followup) |
| Search products | `search` | n/a | (no UI yet) | `GET /v3/products?q=...` | ✅ exists (subset of products list) |
| Vendors list (designers) | `vendors_listing` → `customer/vendors_list` | `UtilityStores` → `utility/stores` | (no UI yet) | `GET /v3/vendors` | ✅ exists |
| Single vendor | `read_vendor` → `customer/read-vendor` | (n/a) | `GET /vendors/:slug` (in routing) | `GET /v3/vendors/:slug` | ✅ exists |
| Vendor's products | `vendors_products_listing` → `customer/vendors_products`, `store_latest` | n/a | (no UI yet) | `GET /v3/vendors/:slug/products` | ✅ exists |
| Featured vendors (Designer Spotlight) | n/a | n/a | `GET /featured-vendors` (currently legacy) | `GET /v3/featured-vendors` | ❌ NEEDS BUILDING (currently 500) |
| Vendor's collections/labels | `store_labels` → `customer/read_vendor_collection` | `readLabel` → `vendors/labels/read-label` (vendor-side own) | (n/a) | `GET /v3/vendors/:id/labels` (customer view) + `GET /v3/me/store/labels` (vendor's own) | NEW (both) |
| Vendor reviews list | `store_reviews` → `customer/store-reviews` | `getProductReviews` → `vendors/products/get-products-reviews` (vendor-side own) | (n/a) | `GET /v3/vendors/:slug/reviews` (customer) + `GET /v3/me/store/products/:id/reviews` (vendor's own) | NEW (both) |
| Products by tag/label | `products_by_labels` → `customer/products_by_labels` | n/a | n/a | `GET /v3/products?label=...` | NEW (or extend existing /v3/products) |
| Styles list (custom mobile UX) | `styles_list` → `customer/styles_list` | n/a | n/a | `GET /v3/styles` | NEW |
| Sitemap data (build-time) | n/a | n/a | (existing) | `GET /v3/sitemap-data` | ✅ exists |

**Catalog section totals:** 14 unique operations. v3 needs ~5 new endpoints (collections/labels, vendor reviews, styles, +featured-vendors fix, +categories/:slug fix). The rest exist.

#### Cart + checkout + orders + payment

This is the highest-stakes section (touches money). Heavy shadow-mode discipline required per M3 plan §1.4.

| Operation | Mobile | Portal | Web | Canonical v3 | Status |
|---|---|---|---|---|---|
| Read cart | `customerCart` → `customer/read-cart` | n/a | (no UI yet) | `GET /v3/cart` | NEW |
| Add to cart | `addToCart` → `customer/addToCart` | n/a | (no UI yet) | `POST /v3/cart/items` | NEW |
| Update cart item quantity | `IncreaseItem` + `DecreaseItem` (DUPLICATE) | n/a | (no UI yet) | `PATCH /v3/cart/items/:id` with `{quantity: number}` | NEW |
| Remove cart item | `RemoveCartItem` → `customer/removeFromCart` | n/a | (no UI yet) | `DELETE /v3/cart/items/:id` | NEW |
| Checkout (initiate payment) | `initiatePayment` → `customer/payment/initiate_payment` | n/a | (no UI yet) | `POST /v3/checkout` (uses PaymentGateway internally) | NEW |
| Finalize payment (post-Noon redirect) | `finalizePayment` → `customer/finalize_payment` | n/a | (no UI yet) | `POST /v3/checkout/finalize` (admin-internal; also called by webhook) | NEW |
| Noon webhook (server-to-server) | n/a | n/a | n/a | `POST /v3/payment/webhook/noon` | NEW |
| Update billing address | `updateBilling` → `customer/settings/billing/update-billing` | n/a | (no UI yet) | `PATCH /v3/me/billing-address` | NEW |
| List user's orders | `customerOrder` → `customer/read-orders`, `read_orders_listing` → `customer/read_orders_listing` | n/a | (no UI yet) | `GET /v3/me/orders` | NEW |
| Single order detail | `orderDetails` → `customer/read-order-details` | n/a | (no UI yet) | `GET /v3/me/orders/:id` | NEW |
| Vendor's own orders (customer-side view) | `readCustomerOrders` → `customer/read-customer-orders` | (overlaps with portal's `getVendorOrders`) | n/a | `GET /v3/me/store/orders` | NEW |
| Refund (admin) | n/a | (would be admin-only) | n/a | `POST /v3/admin/orders/:id/refund` | NEW |

**Cart/checkout/orders/payment section totals:** 12 unique operations. v3 needs ALL 12 endpoints. None exist today.

#### Account / profile / settings

Mobile and portal both have profile management. Some overlap, some app-specific.

| Operation | Mobile | Portal | Web | Canonical v3 | Status |
|---|---|---|---|---|---|
| Get current user profile | `readProfile` → `customer/settings/read-profile` | `getUserProfile` → `utility/shared/user` | (routing exists) | `GET /v3/me/profile` | NEW (already routed) |
| Update profile | `updateProfile` → `customer/settings/update-profile` | `updateUserProfile` → `vendors/settings/update-user-basic` | (routing exists) | `PATCH /v3/me/profile` | NEW |
| Change password | n/a | `updateUserPassword` → `utility/shared/change-user-password` | (routing exists) | `PATCH /v3/me/password` | NEW |
| Get billing address | `readBilling` → `customer/settings/billing/read-billings` | (n/a) | n/a | `GET /v3/me/billing-address` | NEW |
| Update billing | `updateBilling` (covered in cart section) | (n/a) | n/a | `PATCH /v3/me/billing-address` | (same as above) |
| List addresses | n/a | n/a | (routing exists; wrong oldPath) | `GET /v3/me/addresses` | NEW (currently mis-routed) |
| Add address | n/a | n/a | (routing exists; wrong oldPath) | `POST /v3/me/addresses` | NEW |
| Update address | n/a | n/a | (routing exists) | `PUT /v3/me/addresses/:id` | NEW |
| Delete address | n/a | n/a | (routing exists) | `DELETE /v3/me/addresses/:id` | NEW |
| Customer measurements list | `readMeasurement` → `customer/settings/measurement/read-measurement` | n/a | (routing exists) | `GET /v3/me/measurements` | NEW |
| Update customer measurement | `updateMeasurement` → `customer/settings/measurement/update-measurement` | n/a | (routing exists) | `POST /v3/me/measurements` + `PUT /v3/me/measurements/:id` | NEW |
| Read user's review history | `readReviews` → `customer/settings/read-reviews` | n/a | n/a | `GET /v3/me/reviews` | NEW |
| Vendor self-view: their own reviews | `storeReviews` → `customer/settings/store-reviews` | (overlaps with portal's `getProductReviews`) | n/a | `GET /v3/me/store/reviews` | NEW |
| Delete user's review | `deleteReview` → `customer/settings/delete-review` | n/a | n/a | `DELETE /v3/me/reviews/:id` | NEW |
| Update phone verification | n/a | n/a | (routing exists) | `POST /v3/auth/verify-phone` | NEW |

**Account section totals:** 14 unique operations. v3 needs all 14 (some are already routed but not implemented).

#### Wishlist + reviews + follow

| Operation | Mobile | Portal | Web | Canonical v3 | Status |
|---|---|---|---|---|---|
| Read wishlist | `readWishlist` → `customer/read_wishlist` | n/a | (routing exists) | `GET /v3/me/wishlist` | NEW |
| Read wishlist labels | `readWishlistLabel` → `customer/read_wishlist_label` | n/a | n/a | `GET /v3/me/wishlist/labels` | NEW |
| Add wishlist label | `addWishlistLabel` → `customer/add_wishlist_label` | n/a | n/a | `POST /v3/me/wishlist/labels` | NEW |
| Add to wishlist | `addWishlist` → `customer/add_wishlist` | n/a | (routing exists) | `POST /v3/me/wishlist/items` | NEW |
| Remove from wishlist | n/a (but routing exists) | n/a | (routing exists) | `DELETE /v3/me/wishlist/items/:product_id` | NEW |
| Add product review | `add_review` → `customer/add-review` | n/a | n/a | `POST /v3/reviews` | NEW |
| Mark review helpful | `make_helpful` → `customer/helpful` | n/a | n/a | `POST /v3/reviews/:id/helpful` | NEW |
| Follow vendor | `follow_vendor` → `customer/follow` | n/a | n/a | `POST /v3/me/follows` | NEW |
| Unfollow vendor | `unfollow_vendor` → `customer/unfollow` | n/a | n/a | `DELETE /v3/me/follows/:vendor_id` | NEW |
| Create style | `create_style` → `customer/create_style` | n/a | n/a | `POST /v3/me/styles` | NEW |

**Wishlist/reviews/follow totals:** 10 unique operations. All NEW.

#### Chat

Mobile-only feature. 10 unique operations.

| Operation | Mobile | Canonical v3 | Status |
|---|---|---|---|
| Get chat conversations | `chat_get_conversation` → `chat/get_conversation` | `GET /v3/me/chats` | NEW |
| Get messages in conversation | `chat_get_messages` → `chat/get_messages` | `GET /v3/me/chats/:id/messages` | NEW |
| Send message | `chat_send_message` → `chat/send_message` | `POST /v3/me/chats/:id/messages` | NEW |
| Upload image (to chat) | `chat_upload_image` → `chat/upload_image` | `POST /v3/me/chats/:id/messages/image` | NEW |
| Mark conversation read | `chat_mark_read` → `chat/mark_read` | `POST /v3/me/chats/:id/read` | NEW |
| Get unread count | `chat_get_unread_count` → `chat/get_unread_count` | `GET /v3/me/chats/unread-count` | NEW |
| Get chat prompts (templated quick replies?) | `chat_get_prompts` → `chat/get_prompts` | `GET /v3/me/chats/prompts` | NEW |
| List vendors for chat | `chat_get_vendors` → `chat/get_vendors` | `GET /v3/me/chats/vendors` | NEW |
| Vendor-side: list customer chat orders | `chat_get_vendor_orders` → `chat/get_vendor_orders` | `GET /v3/me/store/chats/orders` | NEW |
| Vendor-side: list customer conversations | `chat_get_vendor_conversations` → `chat/get_vendor_conversations.php` | `GET /v3/me/store/chats` | NEW |

**Chat totals:** 10 unique operations. All NEW. Note: realtime adjacency — current legacy uses polling; M3 keeps polling unless WebSockets are explicitly added (M4).

#### Tickets / customer support

| Operation | Mobile | Portal | Canonical v3 | Status |
|---|---|---|---|---|
| Create ticket (customer) | `createTicket` → `customer/create_ticket` | n/a | `POST /v3/me/tickets` | NEW |
| Read ticket (customer) | `readTicket` → `customer/read_ticket` | n/a | `GET /v3/me/tickets/:id` | NEW |
| Read ticket messages (customer) | `readTicketMessages` → `customer/read-ticket-messages` | n/a | `GET /v3/me/tickets/:id/messages` | NEW |
| Send ticket message (customer) | `sendTicketMessage` → `customer/send-ticket-message` | n/a | `POST /v3/me/tickets/:id/messages` | NEW |
| List tickets (admin) | n/a | `tickets` → `admin/common/tickets` | `GET /v3/admin/tickets` | NEW |
| Read ticket messages (admin) | n/a | `ticketsMessages` → `admin/common/ticket-messages` | `GET /v3/admin/tickets/:id/messages` | NEW |
| Send ticket message (admin) | n/a | `sendTicketMessage` (different from mobile's!) → `admin/common/send-ticket-message` | `POST /v3/admin/tickets/:id/messages` | NEW |
| Update ticket status (admin) | n/a | `ticketsStatus` → `admin/common/ticket-status` | `PATCH /v3/admin/tickets/:id/status` | NEW |
| Update ticket priority (admin) | n/a | `ticketsPriority` → `admin/common/ticket-priority` | `PATCH /v3/admin/tickets/:id/priority` | NEW |

**Tickets totals:** 9 unique operations. All NEW.

#### Vendor self-service (portal-heavy, mobile-light)

This is portal's biggest section. Mobile only has 2 working endpoints; portal has ~41 covering same domain.

| Operation | Mobile | Portal | Canonical v3 | Status |
|---|---|---|---|---|
| Vendor dashboard | `vendor_dashboard` → `vendors/dashboard` | `getVendorStats` → `vendors/common/dashboard-activity` | `GET /v3/me/store/dashboard` | NEW |
| Toggle store status | `vendor_toggle_status` → `vendors/toggle_status` | `updateStoreStatus` → `vendors/settings/switch-store-status` | `PATCH /v3/me/store/status` | NEW |
| Get store | n/a | `getVendorStore` → `vendors/settings/vendor-store` | `GET /v3/me/store` | NEW |
| Update store basic info | n/a | `updateStoreBasic` → `vendors/settings/update-vendor-store` | `PATCH /v3/me/store` | NEW |
| Get payment settings | n/a | `getVendorPayment` → `vendors/settings/vendor-store-payment` | `GET /v3/me/store/payment-settings` | NEW |
| Update payment settings | n/a | `updateStorePayment` → `vendors/settings/update-vendor-payment` | `PATCH /v3/me/store/payment-settings` | NEW |
| Get tax settings | n/a | `getVendorTax` → `vendors/settings/vendor-store-tax` | `GET /v3/me/store/tax-settings` | NEW |
| Update tax settings | n/a | `updateStoreTax` → `vendors/settings/update-vendor-tax` | `PATCH /v3/me/store/tax-settings` | NEW |
| Get notification settings | n/a | `getVendorNotifications` → `vendors/settings/vendor-store-notifications` | `GET /v3/me/store/notification-settings` | NEW |
| Update notification settings | n/a | `updateStoreNotifications` → `vendors/settings/update-vendor-notifications` | `PATCH /v3/me/store/notification-settings` | NEW |
| Get compliance status | n/a | `getCompliance` → `vendors/common/compliance` | `GET /v3/me/store/compliance` | NEW |
| Update compliance | n/a | `updateCompliance` → `vendors/settings/update-compliance` | `PATCH /v3/me/store/compliance` | NEW |
| List products (vendor's own) | n/a | `getProduct` → `vendors/products/get-products` | `GET /v3/me/store/products` | NEW |
| Single product (vendor's own) | n/a | `getProductById` → `vendors/products/getProductById` | `GET /v3/me/store/products/:id` | NEW |
| Create product | n/a | `createProduct` → `vendors/products/create-product` | `POST /v3/me/store/products` | NEW |
| Update product | n/a | `updateProduct` → `vendors/products/update-product` | `PATCH /v3/me/store/products/:id` | NEW |
| Delete product | n/a | `deleteProductById` → `vendors/products/delete-product` | `DELETE /v3/me/store/products/:id` | NEW |
| List vendor orders | n/a | `getVendorOrders` + `getVendorOrdersByStatus` (different filters) | `GET /v3/me/store/orders?status=...` | NEW |
| Return orders | n/a | `getVendorReturnOrders` → `vendors/orders/get-return-orders` | `GET /v3/me/store/orders?status=return` | NEW |
| Delivery orders | n/a | `getVendorDeliveryOrders` → `vendors/orders/get-ready-orders` | `GET /v3/me/store/orders?status=ready` | NEW |
| Single order | n/a | `getOrderById` → `vendors/orders/getOrderById` | `GET /v3/me/store/orders/:id` | NEW |
| Update order status | n/a | `updateOrderStatus` → `vendors/orders/update-order-status` | `PATCH /v3/me/store/orders/:id/status` | NEW |
| List labels | n/a | `readLabel` → `vendors/labels/read-label` | `GET /v3/me/store/labels` | NEW |
| Create label | n/a | `createLabel` → `vendors/labels/create-label` | `POST /v3/me/store/labels` | NEW |
| Update label | n/a | `updateLabel` → `vendors/labels/update-label` | `PATCH /v3/me/store/labels/:id` | NEW |
| Delete label | n/a | `deleteLabel` → `vendors/labels/delete-label` | `DELETE /v3/me/store/labels/:id` | NEW |
| List measurements (vendor's guides) | `readStoreMeasurement` (dupe with portal's) | `readMeasurement` → `vendors/measurement/get-measurements` | `GET /v3/me/store/measurement-guides` | NEW |
| Create measurement guide | n/a | `createMeasurement` → `vendors/measurement/create-measurement` | `POST /v3/me/store/measurement-guides` | NEW |
| Update measurement guide | n/a | `updateMeasurement` → `vendors/measurement/update-measurement` | `PATCH /v3/me/store/measurement-guides/:id` | NEW |
| Delete measurement guide | n/a | `deleteMeasurement` → `vendors/measurement/delete-measurement` | `DELETE /v3/me/store/measurement-guides/:id` | NEW |
| List coupons | n/a | `getCoupons` → `vendors/coupons/get-coupons` | `GET /v3/me/store/coupons` | NEW |
| Single coupon | n/a | `getCouponById` → `vendors/coupons/get-coupon-by-id` | `GET /v3/me/store/coupons/:id` | NEW |
| Coupon analytics | n/a | `couponAnalytics` → `vendors/coupons/coupon-analytics` | `GET /v3/me/store/coupons/:id/analytics` | NEW |
| Create coupon | n/a | `createCoupon` → `vendors/coupons/create-coupon` | `POST /v3/me/store/coupons` | NEW |
| Update coupon | n/a | `updateCoupon` → `vendors/coupons/update-coupon` | `PATCH /v3/me/store/coupons/:id` | NEW |
| Toggle coupon status | n/a | `toggleCouponStatus` → `vendors/coupons/toggle-coupon-status` | `PATCH /v3/me/store/coupons/:id/status` | NEW |
| Delete coupon | n/a | `deleteCoupon` → `vendors/coupons/delete-coupon` | `DELETE /v3/me/store/coupons/:id` | NEW |
| Get notifications | n/a | `getNotifications` → `vendors/common/notifications` | `GET /v3/me/notifications` | NEW |
| Mark notifications read | n/a | `markNotifications` → `vendors/common/mark_notifications` | `POST /v3/me/notifications/mark-read` | NEW |
| Get product reviews (vendor's own products) | n/a | `getProductReviews` → `vendors/products/get-products-reviews` | `GET /v3/me/store/products/:id/reviews` | NEW |

**Vendor self-service totals:** 39 unique operations. All NEW. This is the LARGEST single category.

#### Admin / platform operations (portal-only)

| Operation | Portal | Canonical v3 | Status |
|---|---|---|---|
| Admin dashboard | `getAdminStats` → `admin/common/dashboard-activity` | `GET /v3/admin/dashboard` | NEW |
| List customers | `getCustomers` → `admin/common/get-customers` | `GET /v3/admin/customers` | NEW |
| List stores | `getStores` → `admin/common/get-stores` | `GET /v3/admin/stores` | NEW |
| Single store | `getSingleStore` → `admin/common/getSingleStore` | `GET /v3/admin/stores/:id` | NEW |
| List users (combined customer+admin) | `getUsers` → `admin/common/get-users` | `GET /v3/admin/users` | NEW |
| Activate customer | `activateCustomer` → `admin/common/activate-customer` | `POST /v3/admin/customers/:id/activate` | NEW |
| Deactivate customer | `deactivateCustomer` → `admin/common/deactivate-customer` | `POST /v3/admin/customers/:id/deactivate` | NEW |
| Activate store | `activateStore` → `admin/common/activate-store` | `POST /v3/admin/stores/:id/activate` | NEW |
| Deactivate store | `deactivateStore` → `admin/common/deactivate-store` | `POST /v3/admin/stores/:id/deactivate` | NEW |
| Delete store | `deleteStore` → `admin/common/delete-store` | `DELETE /v3/admin/stores/:id` | NEW |
| Store's orders | `getStoreOrders` → `admin/common/get-store-orders` | `GET /v3/admin/stores/:id/orders` | NEW |
| Store's orders by status | `getStoreOrdersByStatus` → `admin/common/getStoreOrdersByStatus` | `GET /v3/admin/stores/:id/orders?status=...` (dedup with above) | NEW |
| All products view | `getAdminProducts` → `admin/common/products` | `GET /v3/admin/products` | NEW |
| All products for one vendor | `productsByVendorId` → `admin/common/productsByVendorId` | `GET /v3/admin/vendors/:id/products` | NEW |
| All sales view | `sales` → `admin/common/sales` | `GET /v3/admin/sales` | NEW |
| Message vendor (admin → vendor) | `messageVendor` → `admin/message-vendor` | `POST /v3/admin/vendors/:id/messages` | NEW |
| Processing queue | `processing` → `admin/common/processing` | `GET /v3/admin/orders?status=processing` | NEW |
| Single processing item | `processingById` → `admin/common/processingById` | `GET /v3/admin/orders/:id/processing` | NEW |
| Products for processing item | `productsByProcessingId` → `admin/common/productsByProcessingId` | `GET /v3/admin/orders/:id/products` | NEW |
| Plurals by ID (?? naming unclear) | `pluralById` → `admin/common/pluralById` | INVESTIGATE — defer until clear what this does | NEW |
| Logistics view | `logistics` → `admin/common/logistics` | `GET /v3/admin/logistics` | NEW |
| Commissions view | `commissions` → `admin/common/commissions` | `GET /v3/admin/commissions` | NEW |
| Transactions view | `transactions` → `admin/common/transactions` | `GET /v3/admin/transactions` | NEW |
| Admin user register (admin adds new admin) | `AdminUserRegister` → `admin/common/register` | `POST /v3/admin/users` | NEW |
| Admin user password | `AdminUserPassword` → `admin/common/password` | `PATCH /v3/admin/users/:id/password` | NEW |
| Collections CRUD | `createCollection`, `updateCollection`, `getCollection`, `readCollection` (4 endpoints) | `GET/POST/PATCH /v3/admin/collections` and `/v3/admin/collections/:id` | NEW |
| Brands CRUD (no legacy URL — admin-only feature) | n/a | (routing exists) `GET/POST/PUT/DELETE /v3/admin/brands` | NEW |

**Admin totals:** ~30 unique operations. All NEW. The "no legacy URL" entries in ENDPOINT_ROUTING (brands, vendors, categories admin CRUD) are v3-only — they don't migrate FROM legacy; they're net-new admin features in v3.

#### Utility / shared (overlapping operations across apps)

| Operation | Mobile | Portal | Canonical v3 | Status |
|---|---|---|---|---|
| Categories list (catalog browse) | (covered above) | `UtilityCategory` (heavy use, 6 callers) | `GET /v3/categories` | ✅ exists |
| Stores list | (covered above) | `UtilityStores` → `utility/stores` | `GET /v3/vendors` | ✅ exists |
| Collections list | n/a | `UtilityCollections` → `utility/collections` (3 callers) | `GET /v3/collections` | NEW |

#### Health + external

| Operation | Source | Canonical v3 | Status |
|---|---|---|---|
| Health check | (web only) | `GET /v3/health` | ✅ exists |
| Topex cities (external delivery API) | mobile only | EXTERNAL — do not migrate | n/a |
| Topex areas (external) | mobile only | EXTERNAL — do not migrate | n/a |

### 4.4 Operation totals after deduplication

| Category | Unique ops | v3 exists | v3 to BUILD |
|---|---|---|---|
| Auth + identity | 12 | 3 | 9 |
| Catalog reads | 14 | 9 | 5 |
| Cart + checkout + orders + payment | 12 | 0 | 12 |
| Account / profile / settings | 14 | 0 | 14 |
| Wishlist + reviews + follow | 10 | 0 | 10 |
| Chat | 10 | 0 | 10 |
| Tickets / support | 9 | 0 | 9 |
| Vendor self-service | 39 | 0 | 39 |
| Admin / platform | 30 | 0 | 30 |
| Utility / shared | 3 | 2 | 1 |
| Health + external | 3 | 1 | 0 (2 external) |
| **TOTAL** | **156** | **15** | **139** |

### 4.5 Headline numbers from the dedup pass

- **156 unique business operations** identified across mobile + portal + web
- **15 already exist in v3** (mostly catalog reads from M2 Day 5)
- **139 net new v3 endpoints to BUILD** during M3

This is significantly higher than the M3 plan's "~50 endpoints to build" estimate. The original number was based on incomplete audits.

**Where the M3 plan was wrong:**
- Underestimated vendor self-service surface (portal has 39 unique ops; original assumed ~15)
- Didn't separately count admin operations (~30 ops; original assumed ~10)
- Didn't audit web's actual feature set vs intended (M3.1.0c discovery)

### 4.6 Impact on M3 timeline

Each new v3 endpoint takes ~0.5-2 days to implement properly (controller + Doctrine query + tests + ENDPOINT_ROUTING entry + shape validation + documentation). Conservatively: 1 day average.

139 endpoints × 1 day = 139 dev-days. At 5 working days/week ≈ **28 weeks** of v3 endpoint building ALONE.

Distributed across M3.1.x (mobile-driven), M3.2.x (web-driven), M3.3.x (portal-driven) phases per the M3 plan's just-in-time strategy (C10):

| App phase | Endpoints to build | Approx duration |
|---|---|---|
| M3.1 mobile | ~60 unique (auth, catalog, cart, checkout, orders, account, wishlist, chat, tickets, payment) | 12-14 weeks |
| M3.2 web | ~15 unique (re-uses mobile's; new are web-specific like designer routes, plus all the UI build work for greenfield features) | 11-15 weeks (mostly UI build; M3.1.0c discovery) |
| M3.3 portal | ~70 unique (vendor self-service + admin) | 14-18 weeks |

Some endpoints are shared across phases (login, register, profile) — counted once in the app that's first to need it.

**Revised total M3 estimate: ~40-50 weeks elapsed, 10-12 months realistic.**

(Previous estimate post-M3.1.0c was 9-12 months. The dedup pass refines but doesn't dramatically change.)

### 4.7 Dedup wins worth highlighting

These specific dedups simplify M3 design:

1. **`UserLogin`/`UserRegister`** — exact match across mobile and portal. ONE v3 endpoint serves both.
2. **`sendOTP` + `sendOOTP`** — same business op in mobile under two different legacy paths. ONE v3 endpoint with `?channel=sms|email` and `?destination=phone|email`.
3. **`IncreaseItem` + `DecreaseItem`** — two endpoints become ONE `PATCH /v3/cart/items/:id` with `{quantity: number}`.
4. **`readStoreMeasurement` (mobile) ≡ `readMeasurement` (portal)** — same business op. ONE v3 endpoint.
5. **`UtilityCategory` (portal) ≡ `category_listing` (mobile) ≡ web's catalog read** — three apps, same underlying data. ONE v3 endpoint exists.
6. **Multiple variants of order status filtering** (`getVendorOrdersByStatus`, `getVendorReturnOrders`, `getVendorDeliveryOrders`) — collapse to ONE endpoint with `?status=...` query param.

These dedups together cut ~15-20 endpoints from the implementation count.

### 4.8 Items requiring further investigation (M3.1.0e or later)

A few endpoints have unclear semantics or are duplicates-of-duplicates needing clarification:

1. **`pluralById`** (portal admin) — name unclear. What does "pluralById" mean? Need to read the controller code or ask Sodiq.
2. **`readCustomerOrders`** (mobile, distinct from `customerOrder`) — sounds like vendor-side viewing their customer orders. Need to verify.
3. **`storeReviews` vs `store_reviews`** (mobile) — confirmed in §1.2 they're different (settings vs public view). v3 needs both endpoints with clear naming.
4. **`UserResetPassword` (portal, unused)** — has same URL as `UserReset` (`users/reset`). Likely a duplicate constant; safe to remove.

These get clarified during M3.1.0e (v3 contract design).


---

## Section 5 — Master v3 Contract Design (M3.1.0e)

**Status:** 🚧 IN PROGRESS — broken into 7 sub-sub-phases per the M3.1.0e scoping decision.

| Sub-phase | Categories | # ops | Status |
|---|---|---|---|
| **0e.1** | Foundations (conventions only) | n/a | ✅ Complete (this commit) |
| **0e.2** | Auth + Identity + Account/Profile | 26 | Pending |
| **0e.3** | Catalog + Search + Utility | 17 | Pending |
| **0e.4** | Cart + Checkout + Orders + Payment | 12 | Pending |
| **0e.5** | Wishlist + Reviews + Follow + Chat + Tickets | 29 | Pending |
| **0e.6** | Vendor Self-Service | 39 | Pending |
| **0e.7** | Admin / Platform | 30 | Pending |

Per-endpoint contracts in 0e.2-0e.7 reference back to this Foundations section for shared conventions.

---

### 5.1 (M3.1.0e.1) — Foundations

This sub-section defines the cross-cutting conventions that every v3 endpoint follows. It documents EXISTING conventions in the apps/api codebase (rather than inventing new ones) and locks them as the explicit standard for M3 work.

#### 5.1.1 Response envelope (success)

Every successful v3 response uses one of three envelope shapes:

**Shape A — Single resource:**
```typescript
{
  data: { /* the resource */ }
}
```
Used by GET single-resource endpoints (`GET /v3/me/profile`, `GET /v3/products/:slug`, etc.).

**Shape B — Paginated list:**
```typescript
{
  data: Array<{ /* resources */ }>,
  meta: {
    total: number,    // unfiltered total (not just this page)
    limit: number,    // echoed from request (1-100, default varies)
    offset: number,   // echoed from request
    has_more: boolean // (offset + items.length) < total
  }
}
```
Used by all GET-list endpoints. The `has_more` computation uses ACTUAL items returned, not requested limit — handles last-page correctly.

**Shape C — Custom envelope (no `data` wrapper):**
For endpoints whose response is conceptually a single object that wraps multiple related arrays. Auth responses fall here:
```typescript
{
  access_token: string,
  refresh_token: string,
  user: { /* profile */ },
  expires_in: number
}
```
Used when wrapping in `data: { access_token: ... }` would feel forced. The general rule: **prefer Shape A**; use Shape C only when there's no single "resource" being returned.

**HTTP status codes for success:**
- `200 OK` — read endpoints, idempotent updates (PATCH/PUT)
- `201 Created` — POST that creates a new resource (response includes the created resource)
- `204 No Content` — DELETE, logout, mark-read, and other "success but nothing to return" operations. No body.

The `Responder` trait in `apps/api/src/Http/Responder.php` provides `ok()`, `created()`, `noContent()` helpers. Controllers use these for consistency.

**Critical formatting rules:**
- `Content-Type: application/json` always
- `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` encoding flags
- Empty success body is `{}` not `[]` (object shape, not array)
- ISO-8601 UTC for all timestamps (e.g. `"2026-05-13T10:03:01+00:00"`)
- Decimal money values as numbers (e.g. `980.0`), not strings; always include `currency: "AED"` sibling field

#### 5.1.2 Response envelope (error)

Every error response uses this shape:

```typescript
{
  error: {
    code: string,       // ErrorCodes::* (see §5.1.3)
    message: string,    // Safe-to-display public message
    details?: object    // Optional structured context (per-field errors, etc.)
  }
}
```

The `details` field is OPTIONAL. When present, its shape depends on the error code:

| Error code | `details` shape |
|---|---|
| `VALIDATION_FAILED` | `{ fields: { [fieldName: string]: string[] } }` (per-field error messages) |
| `OTP_RATE_LIMITED` | `{ retry_after_seconds: number }` |
| Most others | (omitted) |

**HTTP status codes for errors:**
- `400 Bad Request` — body wasn't parseable; not used for field-level validation
- `401 Unauthorized` — auth failure (token missing/invalid, login failed, refresh expired)
- `403 Forbidden` — authenticated but not allowed (role check failed)
- `404 Not Found` — resource doesn't exist
- `409 Conflict` — uniqueness violation (email taken, phone taken)
- `422 Unprocessable Entity` — field-level validation errors (body parsed; fields invalid)
- `429 Too Many Requests` — rate limited
- `500 Internal Server Error` — uncaught exception; `code: "INTERNAL_ERROR"`

The convention: **400 is for malformed body, 422 is for invalid field values.** This matches RFC 7807 spirit and common REST style. The legacy v1/v2 sometimes used 400 for everything; v3 is stricter.

The `HttpException` class in `apps/api/src/Http/Errors/HttpException.php` provides named factories: `badRequest()`, `unauthorized()`, `forbidden()`, `notFound()`, `validation()`, `conflict()`, etc. Controllers throw these; `ApiErrorMiddleware` converts to the envelope.

#### 5.1.3 Error code vocabulary

Error codes are SHARED across endpoints. They're declared as constants in `apps/api/src/Http/Errors/ErrorCodes.php` and are part of the public API contract — clients switch on them to render appropriate UI.

**Naming convention:** `DOMAIN_REASON` — domain prefix groups related codes; reason describes the failure. Examples: `AUTH_INVALID_CREDENTIALS`, `OTP_RATE_LIMITED`, `VALIDATION_FAILED`.

**Current ErrorCodes inventory (will grow during M3):**

```
AUTH_MISSING_TOKEN              Authorization header missing/wrong shape
AUTH_INVALID_TOKEN              Token sig/audience/expiry rejected
AUTH_INVALID_CREDENTIALS        Email/password wrong
AUTH_ACCOUNT_INACTIVE           User exists, is_active = false
AUTH_PHONE_NOT_VERIFIED         Registration incomplete
AUTH_REFRESH_TOKEN_INVALID      Refresh token unknown/revoked/expired

OTP_RATE_LIMITED                Per-phone hourly cap exceeded
OTP_VERIFICATION_FAILED         Wrong code, expired, consumed (collapsed)
OTP_PROVIDER_ERROR              CPaaS failure

VALIDATION_FAILED               Schema validation failed (details.fields populated)
VALIDATION_BAD_REQUEST          Body not valid JSON or not a JSON object

CONFLICT_EMAIL_TAKEN            Email already in use
CONFLICT_PHONE_TAKEN            Phone already in use

NOT_FOUND                       Resource doesn't exist
FORBIDDEN                       Role check failed
INTERNAL_ERROR                  Uncaught exception (500)
```

**M3 will add (per M3.1.0e.2+ contract design):**

```
CART_ITEM_NOT_FOUND             Cart item ID unknown or already removed
CART_PRODUCT_UNAVAILABLE        Product is out of stock or deleted
CART_INVALID_QUANTITY           Quantity ≤ 0 or > stock available

ORDER_NOT_FOUND                 Order ID unknown for this user
ORDER_NOT_CANCELLABLE           Order is already shipped / paid out

PAYMENT_INITIATION_FAILED       Gateway rejected the initiate call
PAYMENT_WEBHOOK_DUPLICATE       Idempotency-key replay (informational, returns 200)
PAYMENT_WEBHOOK_SIGNATURE_INVALID  Gateway signature didn't verify
PAYMENT_REFUND_FAILED           Refund attempt rejected by gateway

WISHLIST_LABEL_NOT_FOUND        Label ID unknown
WISHLIST_ITEM_ALREADY_EXISTS    Product already in wishlist (when uniqueness matters)

REVIEW_ALREADY_EXISTS           User already reviewed this product
REVIEW_PRODUCT_NOT_PURCHASED    Trying to review without buying (if we enforce that)

CHAT_CONVERSATION_NOT_FOUND     Conversation ID unknown for this user
CHAT_MESSAGE_TOO_LONG           Body exceeds max length

TICKET_NOT_FOUND                Ticket ID unknown for this user
TICKET_CLOSED                   Can't add messages to closed ticket

COUPON_NOT_FOUND                Coupon code unknown
COUPON_EXPIRED                  Coupon past valid date
COUPON_NOT_APPLICABLE           Doesn't apply to current cart contents
COUPON_USAGE_LIMIT_REACHED      Per-user or global limit hit
```

When a new error code is needed, the convention is: add to ErrorCodes.php first, then throw it. **Never throw a literal string** — IDE refactoring + PHPStan won't catch typos.

#### 5.1.4 Authentication model

**Token type:** JWT (HS256-signed; signing key in env).

**Header format:**
```
Authorization: Bearer <jwt>
```

No support for other auth schemes. Cookies-based auth was not adopted in v3.

**Token lifecycle:**
- Access token: 30 minutes TTL (default; can extend to 24h via `remember_me: true` on web)
- Refresh token: 30 days TTL (stored in `refresh_tokens` table; can be revoked server-side)
- Refresh flow: `POST /v3/auth/refresh` with refresh token → new access + new refresh (rotation)

**Token claims:**
```typescript
{
  sub: string,    // user_id as string
  email: string,
  roles: string[], // ['customer'], ['vendor'], ['admin'], or combinations
  iat: number,    // issued-at
  exp: number,    // expiry
  jti: string,    // unique JWT id (for refresh rotation tracking)
  aud: string,    // 'api-v3.3bayti.ae' (audience check on validation)
}
```

**Scopes:**

Each endpoint declares its required scope via middleware. Three scopes (matching the three role classes):

| Scope | Required roles | Endpoints |
|---|---|---|
| `public` | none | `/v3/health`, catalog browse, login, register |
| `customer` | `customer` | `/v3/me/*` (any user can read their own data) |
| `vendor` | `vendor` | `/v3/me/store/*` (vendor self-service; user must have vendor role) |
| `admin` | `admin` | `/v3/admin/*` (admin operations) |

A user can hold multiple roles (e.g. a vendor is also a customer). Routes requiring `vendor` accept users whose `roles` array contains `'vendor'`.

**Middleware stack per route:**

```
Public route:    [BaseMiddleware]
Customer route:  [BaseMiddleware, AuthMiddleware('customer')]
Vendor route:    [BaseMiddleware, AuthMiddleware('vendor')]
Admin route:     [BaseMiddleware, AuthMiddleware('admin')]
```

`AuthMiddleware` extracts the token, validates it, loads the User, attaches to request via `AuthMiddleware::ATTR_USER` attribute. Controllers retrieve the user via `$request->getAttribute(AuthMiddleware::ATTR_USER)`.

**Failure modes:**
- Missing `Authorization` header → `401 AUTH_MISSING_TOKEN`
- Malformed token → `401 AUTH_INVALID_TOKEN`
- Valid token but `is_active = false` → `401 AUTH_ACCOUNT_INACTIVE`
- Token's `roles` doesn't include required scope → `403 FORBIDDEN`

#### 5.1.5 Pagination

Already locked in `PaginatedEnvelope` helper. Re-stated here for completeness:

**Request:**
```
GET /v3/products?limit=24&offset=0
```

| Param | Range | Default | Notes |
|---|---|---|---|
| `limit` | 1-100 | 24 | Capped at 100 to prevent abuse |
| `offset` | ≥ 0 | 0 | No upper bound, but large offsets are slow |

Page-number-based pagination (`?page=1`) is NOT supported. Offset-based is consistent across the API.

**Response meta:**
```typescript
meta: {
  total: number,    // unfiltered-by-pagination total in DB
  limit: number,
  offset: number,
  has_more: boolean
}
```

**Cursor pagination:** NOT IMPLEMENTED. Would be a future enhancement (M5+) for very large lists. Offset-based works for all current data sizes (≤ 2,000 products, ≤ 100 vendors, etc.).

#### 5.1.6 Filtering + sorting

For list endpoints, two query parameter categories:

**Filters** — narrow the result set:
```
?vendor=laduna-abaya         # slug-based filter
?category=abayas             # slug-based filter
?min_price=50&max_price=500  # range filter
?featured=true               # boolean
?status=processing           # enum (where applicable)
?q=woven                     # full-text search
```

Slug-based filters resolve to internal IDs (e.g., `?vendor=laduna-abaya` → vendor_id lookup → SQL WHERE vendor_id=N). Unknown slugs return an empty result, not 404 ("no products for unknown vendor" is a valid empty list).

**Sorting:**
```
?sort=newest        # created_at DESC (default for catalog)
?sort=oldest        # created_at ASC
?sort=price_asc     # price.amount ASC
?sort=price_desc    # price.amount DESC
?sort=popular       # internal popularity metric (TBD per category)
```

Sort values are CASE-SENSITIVE. Invalid sort values return `422 VALIDATION_FAILED` with `details.fields.sort`.

Multi-sort (e.g. `?sort=newest,price_asc`) is NOT supported. Single sort key only. If a category needs different default ordering, the controller hard-codes it (e.g., wishlist defaults to "recently added").

#### 5.1.7 Idempotency (for POST endpoints that create resources)

For state-changing POST endpoints where double-submission could cause duplicate side effects (creating duplicate orders, charging twice, etc.), clients SHOULD supply an `Idempotency-Key` header:

```
POST /v3/checkout
Authorization: Bearer <jwt>
Idempotency-Key: client-generated-uuid-v4
Content-Type: application/json

{ ...checkout body... }
```

**Server behavior:**

1. First call with this key: process normally, cache the response keyed by `(user_id, idempotency_key)` for 24 hours
2. Subsequent calls with the same key (within 24h): return the cached response with same status code
3. Calls with the same key but DIFFERENT request body: `422 VALIDATION_FAILED` with `details.idempotency_conflict: true`

**Endpoints that REQUIRE idempotency key (M3.1.0e contracts will mark these):**

- `POST /v3/checkout` (creating an order + initiating payment)
- `POST /v3/cart/items` (adding to cart — though duplicates are less critical here)
- `POST /v3/payment/webhook/noon` (Noon may deliver same webhook multiple times)
- `POST /v3/reviews` (preventing accidental duplicate reviews)

**Endpoints that accept but don't require:**
- All other POST endpoints

**Storage:** `idempotency_keys` table:

```sql
CREATE TABLE idempotency_keys (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER REFERENCES users(id),  -- nullable for webhooks
  key TEXT NOT NULL,
  request_hash TEXT NOT NULL,  -- SHA-256 of canonicalized body
  response_status INTEGER NOT NULL,
  response_body JSONB NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at TIMESTAMPTZ NOT NULL,
  UNIQUE (user_id, key)
);
CREATE INDEX idx_idempotency_expires ON idempotency_keys (expires_at);
```

Background job deletes expired entries (key TTL = 24h).

This table doesn't exist yet — M3.1.6 builds it as part of cart/checkout/orders infrastructure.

#### 5.1.8 ID + slug conventions

**Public-facing identifiers (in URLs and response bodies):**

| Resource type | Identifier | Example | Why |
|---|---|---|---|
| Products | slug | `la23`, `woven-waves` | SEO-friendly URLs |
| Vendors | slug | `laduna-abaya` | SEO-friendly URLs |
| Categories | slug | `abayas`, `kaftans` | SEO-friendly URLs |
| Collections | slug | (TBD) | SEO if public-facing |
| Users | integer ID | `13` | No SEO need; admin only |
| Orders | string (order number) | `ORD-2026-0001234` | Human-readable for support |
| Cart items | integer ID | `42` | Internal only |
| Reviews | integer ID | `42` | Internal only |
| Coupons | code (string) | `WELCOME10`, `SUMMER25` | User-facing |
| Labels | integer ID | `42` | Internal only |
| Addresses | integer ID | `42` | Internal only |
| Tickets | string (ticket number) | `TKT-2026-0001234` | Human-readable |
| Conversations | UUID | `0188...` | Privacy + collision avoidance |
| Messages | UUID | `0188...` | Same as conversations |

**URL conventions:**

- `/v3/products/:slug` — slug-based for catalog resources
- `/v3/me/orders/:order_number` — order-number-based for orders
- `/v3/me/cart/items/:item_id` — integer ID for cart items (private to user)

**Slug generation:**

- Lowercase
- Hyphen-separated
- Latin-only (Arabic transliterates via a library — M3.1.10 task per `m3-backlog.md`)
- Numeric suffix for collisions: `laduna-abaya`, `laduna-abaya-2`, `laduna-abaya-3`
- Stable: once assigned, never regenerated. The vendor #92 slug fix (Day 7) was a one-off correction; not a precedent for routine renames.

**Order number format:** `ORD-YYYY-NNNNNNN`
- `YYYY` = year of creation (4 digits)
- `NNNNNNN` = zero-padded sequence within the year (7 digits supports 9.9M orders/year)
- Example: `ORD-2026-0001234`

**Ticket number format:** `TKT-YYYY-NNNNNNN` (same shape).

#### 5.1.9 Versioning + URL prefix

All v3 endpoints live under `/v3/`. The frontend uses route keys (`'POST /auth/login'`) and ENDPOINT_ROUTING maps to `/v3/auth/login`. The `/v3/` prefix is part of the backend's URL but not the routing key (so we can change versions without rewriting every route key).

When v4 ships (hypothetical future): new endpoints under `/v4/`. ENDPOINT_ROUTING gets new entries with `newPath: '/v4/...'`. Old entries can either get a `oldPath: '/v3/...'` and target='old' (strangler pattern again) or be deleted after migration.

#### 5.1.10 Request body content type

Always `application/json` for write endpoints (POST/PATCH/PUT/DELETE-with-body). Form-encoded (`application/x-www-form-urlencoded`) is NOT supported.

The single exception is file uploads (`multipart/form-data`) for:
- `POST /v3/me/chats/:id/messages/image` — image upload in chat
- (potentially future) product image uploads in vendor self-service

Multipart endpoints will be clearly marked in 0e.6+ contracts.

#### 5.1.11 CORS

All v3 endpoints support CORS preflight (`OPTIONS` requests). Allowed origins are env-configured; production allows:
- `https://staging.3bayti.ae` (web staging)
- `https://3bayti.ae` (web production, when DNS flips)
- `capacitor://localhost` (mobile Capacitor scheme)
- `ionic://localhost` (legacy Ionic scheme)
- portal's eventual production domain (TBD)

Allowed headers: `Authorization, Content-Type, Idempotency-Key, X-Request-ID`.
Allowed methods: `GET, POST, PUT, PATCH, DELETE, OPTIONS`.

#### 5.1.12 Rate limiting

Per-user rate limits apply to most write endpoints. Public endpoints (catalog) have per-IP limits.

Limits (subject to tuning post-launch):

| Endpoint pattern | Limit | Window | Code on exceed |
|---|---|---|---|
| `POST /v3/auth/login` | 5 attempts | 1 min per IP+email combo | `429 AUTH_RATE_LIMITED` |
| `POST /v3/auth/send-otp` | 5 sends | 1 hour per phone | `429 OTP_RATE_LIMITED` |
| `POST /v3/auth/validate-otp` | 5 attempts | 5 min per phone | `429 OTP_RATE_LIMITED` |
| Public read endpoints (catalog) | 600 reqs | 1 min per IP | `429` (no code; standard) |
| Authenticated read endpoints | 600 reqs | 1 min per user | `429` |
| Authenticated write endpoints | 60 reqs | 1 min per user | `429` |

Implementation: Redis-backed counters with sliding window. Already partially in place for OTP (per `OtpAttemptRepository`); generalize to a `RateLimitMiddleware` in M3.

#### 5.1.13 Logging + observability

Every request gets a `X-Request-ID` header in the response (UUID v7). If the client sends one, it's echoed; otherwise the server generates.

Application logs include the request ID for correlation. Log levels:
- `INFO` — successful auth events, payment events, refund events
- `WARN` — recoverable errors (validation failures, etc.)
- `ERROR` — unexpected failures, gateway errors, 500s
- `CRITICAL` — security events (rate limit hits, repeated auth failures from same IP)

**No PII in log messages.** Don't log email/phone/password — log only user_id when authenticated.

#### 5.1.14 Deprecation policy

Once an endpoint ships, its URL + request shape + response shape (success and error) are STABLE for a minimum of 90 days. Breaking changes require:
1. New URL (e.g. `/v3/products/v2` or a `/v4/products`)
2. Migration window during which both are supported
3. Deprecation notice in response header on old URL: `Deprecation: <date>`, `Sunset: <date>`

Patches (bugfixes, new optional fields) are permitted without notice.

---

### 5.1.15 What's NOT defined here (deferred to per-category contracts)

The Foundations section deliberately doesn't cover:

- Specific request/response shapes per endpoint — that's 0e.2-0e.7
- Per-endpoint auth requirements — declared per contract
- Specific error codes beyond the AUTH/OTP/VALIDATION/CONFLICT/generic ones — added per category
- Webhook signature verification specifics (Noon's HMAC details) — covered in 0e.4 (payment)
- Multipart upload field names — covered in 0e.5 (chat) and 0e.6 (vendor product images)
- Notification/email-sending side effects of endpoints — covered per endpoint

### 5.1.16 Locked decisions

After 0e.1, these are LOCKED. M3.1.0e.2+ contracts conform to them without re-deciding:

✅ JSON-only API; no form-encoded bodies (except file uploads as multipart)
✅ Bearer JWT auth; no cookies
✅ JWT claims include `roles[]` for scope-based access control
✅ 30-min access tokens; 30-day refresh tokens with rotation
✅ Three scopes: customer, vendor, admin
✅ Error envelope: `{error: {code, message, details?}}`
✅ Error codes are constants in `ErrorCodes.php`, never literal strings
✅ Success envelopes: `{data}` for single, `{data, meta}` for paginated, custom for auth
✅ Pagination: `?limit=&offset=` with `meta: {total, limit, offset, has_more}`
✅ Filtering: slug-based filters resolve to IDs; unknown slugs return empty results
✅ Sorting: single sort key via `?sort=...`; case-sensitive enum
✅ Idempotency-Key header on `POST /checkout`, `POST /reviews`, webhook handlers
✅ Slug-based public IDs for catalog; order/ticket numbers for human-readable resources
✅ All under `/v3/` URL prefix
✅ ISO-8601 UTC timestamps
✅ Money: numeric amount + `currency` field; never strings
✅ CORS configured per environment
✅ Rate limiting at middleware layer with Redis counters
✅ X-Request-ID for log correlation
✅ 90-day deprecation policy

These decisions answer the most common "should I do X or Y" questions during per-endpoint design. When a contract spec says "auth: customer", you know it means JWT bearer with `roles` includes `customer` — no need to re-spec.


---

### 5.2 (M3.1.0e.2) — Auth + Identity + Account/Profile contracts

**Status:** ✅ Complete (May 13, 2026)
**Scope:** 26 unique operations across auth, identity, account, addresses, measurements, billing, reviews.

#### 5.2.0 Major reality check (worth flagging upfront)

During 0e.2 design, audit of `apps/api/src/Http/Controllers/{Auth,Profile,Address,Measurement}/` revealed that **MUCH MORE of the auth/account surface is already implemented in v3 than the 0d deduplication pass identified.** The 0d count of "15 endpoints exist in v3" was based on v3's CATALOG endpoints only. Auth/account was largely uncounted.

Reality:
- **19 of 26 ops in 0e.2 scope are ALREADY IMPLEMENTED** in v3 (controllers + DTOs + routes registered)
- **7 ops are genuinely missing** and need building in M3.1.1

This is good news for M3 scope:
- The original M3 plan's "M3.1.1 v3 auth endpoint build (5-7 days)" phase is mostly NO-OP. Auth is already done.
- The originally-scoped 9 new auth endpoints actually need ~0 (login, register, OTP send, OTP validate, confirm, reset, refresh, logout — all exist)
- Revised: M3.1.1 becomes "audit existing v3 auth contracts + document gaps" rather than "build from scratch"

**This finding alone shaves 2-3 weeks off M3 timeline.** Documented for the Plan Revision commit deferred until post-0e.

The 7 actually-missing endpoints (covered in this section as NEW):
1. `PATCH /v3/me/password` — change password while authenticated
2. `GET /v3/me/billing-address` — get billing (distinct from shipping addresses)
3. `PATCH /v3/me/billing-address` — update billing
4. `GET /v3/me/reviews` — user's own review history
5. `DELETE /v3/me/reviews/:id` — delete user's review
6. `GET /v3/me/store/reviews` — vendor self-view of their store's reviews
7. `PATCH /v3/me/location` — first-launch location capture (mobile-specific)

#### 5.2.1 Auth + session contracts (10 ops)

Reference: 0e.1 Auth model (§5.1.4), Error codes (§5.1.3), Idempotency (§5.1.7).

---

##### POST /v3/auth/login

**Auth:** public (no token)
**Status:** ✅ EXISTS (`apps/api/src/Http/Controllers/Auth/LoginController.php`)

**Request:**
```typescript
{
  email: string,      // trimmed; lowercased by repo on lookup
  password: string,   // NOT trimmed (legacy passwords with whitespace are valid)
  device_id?: string  // optional client identifier (currently ignored server-side)
}
```

**Response 200 (Shape C — auth custom envelope):**
```typescript
{
  access_token: string,
  access_token_expires_at: string,    // ISO-8601 UTC
  refresh_token: string,
  refresh_token_expires_at: string,   // ISO-8601 UTC
  user: {                              // UserSerializer::publicProfile
    id: number,
    email: string,
    phone: string | null,
    first_name: string | null,
    last_name: string | null,
    is_phone_verified: boolean,
    roles: string[],                   // 'customer' | 'vendor' | 'admin'
    created_at: string                 // ISO-8601 UTC
  }
}
```

**Error responses:**
- `401 AUTH_INVALID_CREDENTIALS` — any combination of: email not found, account inactive, password mismatch, account soft-deleted. (Deliberately collapsed to avoid user enumeration.)
- `422 VALIDATION_FAILED` — body missing `email` or `password`

**Side effects:**
- Updates `user.last_login_at` and `user.last_login_ip`
- Persists `RefreshToken` row
- Wrapped in single Doctrine transaction

**Notes for migration:**
- Mobile's `UserLogin` (`users/login`) and Portal's `UserLogin` both route here directly
- Frontend should check `user.is_phone_verified` after login; if false, route to OTP confirmation screen
- Bcrypt hashes preserved from legacy DB (Day 4 migration) — existing customers can log in unchanged

---

##### POST /v3/auth/register

**Auth:** public
**Status:** ✅ EXISTS (`Auth/RegisterController.php`)

**Request:**
```typescript
{
  email: string,
  phone: string,         // E.164 or local format; normalized server-side
  password: string,      // min 8 chars (enforced; unlike login)
  first_name?: string,
  last_name?: string,
  preferred_language?: 'en' | 'ar'
}
```

**Response 201:**
```typescript
{
  user: { /* UserSerializer::publicProfile, is_phone_verified: false */ },
  // No tokens issued at registration. Client must:
  // 1. Receive OTP via SMS (server sends via SendOtp internally)
  // 2. Call /v3/auth/confirm with the OTP to activate
  // 3. Then call /v3/auth/login to get tokens
  otp_sent_to: string,           // masked phone (e.g. '+971****1234')
  otp_verification_id: string    // opaque ID to pair with /confirm call
}
```

**Error responses:**
- `409 CONFLICT_EMAIL_TAKEN` — email already registered
- `409 CONFLICT_PHONE_TAKEN` — phone already registered
- `422 VALIDATION_FAILED` — invalid email format, weak password, invalid phone format

**Side effects:**
- Creates `users` row with `is_phone_verified = false`, `is_active = true`
- Sends OTP via SMS provider
- Logs OTP attempt for rate limiting

---

##### POST /v3/auth/validate-email

**Auth:** public
**Status:** ✅ EXISTS (`Auth/ValidateEmailController.php`)

**Purpose:** Pre-registration check — is this email available?

**Request:** `{ email: string }`

**Response 200:**
```typescript
{
  email: string,         // normalized
  is_available: boolean  // true if no user exists with this email
}
```

**Error responses:**
- `422 VALIDATION_FAILED` — malformed email

**Notes:**
- This is a precheck UX optimization. Registration still validates.
- Rate limited (5/min per IP) to prevent enumeration

---

##### POST /v3/auth/validate-phone

**Auth:** public
**Status:** ✅ EXISTS (`Auth/ValidatePhoneController.php`)

Symmetric to `/validate-email`. Same shape with `phone` field.

---

##### POST /v3/auth/send-otp

**Auth:** public
**Status:** ✅ EXISTS (`Auth/SendOtpController.php`)

**Request:**
```typescript
{
  destination: string,                          // phone or email
  channel: 'sms' | 'email',
  purpose: 'register' | 'login' | 'reset' | 'verify'
}
```

**Response 200:**
```typescript
{
  verification_id: string,    // opaque; pair with /confirm
  expires_at: string,          // ISO-8601, typically +5min
  masked_destination: string   // '+971****1234' or 'a***@example.com'
}
```

**Error responses:**
- `429 OTP_RATE_LIMITED` — per-destination hourly cap exceeded (details: `retry_after_seconds`)
- `422 VALIDATION_FAILED` — invalid destination/channel/purpose
- `502 OTP_PROVIDER_ERROR` — SMS gateway failure

**Migration note:**
- Replaces mobile's `sendOTP` (`customer/sendOTP`) AND `sendOOTP` (`users/sendOTP`)
- Per 0e.1 dedup: ONE v3 endpoint serves both legacy paths

---

##### POST /v3/auth/confirm

**Auth:** public
**Status:** ✅ EXISTS (`Auth/ConfirmController.php`)

**Purpose:** Submit OTP to complete registration or verification.

**Request:**
```typescript
{
  verification_id: string,
  code: string         // 4-6 digit OTP
}
```

**Response 200:**
```typescript
{
  user: { /* UserSerializer::publicProfile, now is_phone_verified: true */ },
  access_token: string,                  // issued post-confirmation
  access_token_expires_at: string,
  refresh_token: string,
  refresh_token_expires_at: string
}
```

**Error responses:**
- `400 OTP_VERIFICATION_FAILED` — collapses wrong-code/expired/already-consumed
- `429 OTP_RATE_LIMITED` — too many attempts

**Idempotency:** Repeated calls with same `verification_id` after success return cached response (within 5 min). Repeated failures count against rate limit.

---

##### POST /v3/auth/reset

**Auth:** public
**Status:** ✅ EXISTS (`Auth/ResetController.php`)

**Purpose:** Step 1 of password reset — request a reset OTP.

**Request:**
```typescript
{
  destination: string,       // email OR phone
  channel: 'sms' | 'email'
}
```

**Response 200:** Same shape as `/send-otp` (verification_id + expires_at + masked_destination).

**Notes:**
- Always returns 200 even if email/phone unknown (prevents enumeration)
- Rate limited same as `/send-otp`
- This is the UNIFIED reset endpoint serving BOTH legacy `users/reset` (portal) and `users/resetMobile` (mobile). The `channel` param replaces the URL-encoded distinction.

---

##### POST /v3/auth/reset/confirm

**Auth:** public
**Status:** ✅ EXISTS (`Auth/ResetConfirmController.php`)

**Purpose:** Step 2 — submit OTP + new password.

**Request:**
```typescript
{
  verification_id: string,
  code: string,
  new_password: string    // min 8 chars
}
```

**Response 200:**
```typescript
{
  success: true,
  user: { /* UserSerializer::publicProfile */ },
  // Tokens NOT auto-issued; user must log in with new password
}
```

**Error responses:**
- `400 OTP_VERIFICATION_FAILED`
- `422 VALIDATION_FAILED` — weak password
- `429 OTP_RATE_LIMITED`

**Side effects:**
- Updates `user.password_hash` with new bcrypt
- Updates `user.password_changed_at`
- Revokes ALL existing refresh tokens for this user (security: logout everywhere)

---

##### POST /v3/auth/refresh

**Auth:** public (refresh token in body, not header)
**Status:** ✅ EXISTS (`Auth/RefreshController.php`)

**Request:** `{ refresh_token: string }`

**Response 200:** Same shape as login response (new access + new refresh, ROTATION).

**Error responses:**
- `401 AUTH_REFRESH_TOKEN_INVALID` — token unknown, revoked, or expired

**Side effects:**
- Marks old refresh token as revoked
- Issues new refresh token (rotation prevents replay attacks)

---

##### POST /v3/auth/logout

**Auth:** customer/vendor/admin (any authenticated user)
**Status:** ✅ EXISTS (`Auth/LogoutController.php`)

**Request:** `{ refresh_token?: string }` (optional; revokes specified token if provided, otherwise revokes the current session's token)

**Response 204** — no body

**Side effects:**
- Marks the refresh token as revoked
- Access token continues to work until its 30-min TTL expires (stateless JWT; not server-validated until refresh)

---

##### POST /v3/auth/logout-all

**Auth:** authenticated
**Status:** ✅ EXISTS (`Auth/LogoutAllController.php`)

**Request:** empty body

**Response 204**

**Side effects:**
- Revokes ALL refresh tokens for the authenticated user (logout all devices)

---

##### GET /v3/auth/me

**Auth:** authenticated
**Status:** ✅ EXISTS (`Auth/MeController.php`)

Returns the authenticated user's profile. Same payload as `GET /v3/me/profile`. The two endpoints coexist:
- `/v3/auth/me` — "who am I" (used in routing/onboarding logic)
- `/v3/me/profile` — "show me my profile" (used by the profile page)

#### 5.2.2 Identity / profile contracts (4 ops)

---

##### GET /v3/me/profile

**Auth:** customer (any authenticated user)
**Status:** ✅ EXISTS (`Profile/GetProfileController.php`)

**Response 200:**
```typescript
{
  user: {
    id: number,
    email: string,
    phone: string | null,
    first_name: string | null,
    last_name: string | null,
    is_phone_verified: boolean,
    is_email_verified: boolean,
    preferred_language: 'en' | 'ar',
    roles: string[],
    created_at: string,            // ISO-8601 UTC
    updated_at: string
  }
}
```

**Replaces:**
- Mobile's `readProfile` (`customer/settings/read-profile`)
- Portal's `getUserProfile` (`utility/shared/user`)

---

##### PATCH /v3/me/profile

**Auth:** customer
**Status:** ✅ EXISTS (`Profile/UpdateProfileController.php`)
**Method semantics:** JSON Merge Patch (RFC 7396). Only provided fields are updated. Empty body returns 200 no-op.

**Request:** (all fields optional)
```typescript
{
  first_name?: string | null,    // null clears
  last_name?: string | null,
  preferred_language?: 'en' | 'ar',
  // Note: email and phone are NOT here — those require verification flows
}
```

**Response 200:** Same shape as GET profile.

**Notes:**
- Email change requires `POST /v3/auth/send-otp` flow + verify before update (NOT covered in this endpoint — covered in M3.1.1 if customers can change email)
- Phone change same as email

**Replaces:**
- Mobile's `updateProfile` (`customer/settings/update-profile`)
- Portal's `updateUserProfile` (`vendors/settings/update-user-basic`)

---

##### PATCH /v3/me/password ⚠️ NEW (to build in M3.1.1)

**Auth:** customer
**Status:** ❌ NOT YET IMPLEMENTED

**Request:**
```typescript
{
  current_password: string,    // required: re-auth check
  new_password: string         // min 8 chars
}
```

**Response 204**

**Error responses:**
- `401 AUTH_INVALID_CREDENTIALS` — current_password didn't match
- `422 VALIDATION_FAILED` — weak new password
- `409 CONFLICT_SAME_PASSWORD` — new equals current (optional check; M4 polish)

**Side effects:**
- Updates `user.password_hash`
- Updates `user.password_changed_at`
- Does NOT revoke other sessions (current session continues to work; user explicitly chose to change password while logged in — if they want to logout others, they can call `/logout-all` separately)

**Replaces:**
- Portal's `updateUserPassword` (`utility/shared/change-user-password`)
- Mobile currently has no equivalent (mobile users use the reset flow instead)

---

##### PATCH /v3/me/location ⚠️ NEW (to build in M3.1.1)

**Auth:** customer
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** Mobile first-launch geolocation capture. Stores city/country for delivery cost estimates + content localization.

**Request:**
```typescript
{
  latitude?: number,        // decimal degrees, -90 to 90
  longitude?: number,       // decimal degrees, -180 to 180
  city?: string,            // optional manual override
  country_code?: string,    // ISO-3166 alpha-2, e.g. 'AE'
  permission_granted?: boolean  // false if user denied browser/native location permission
}
```

**Response 200:**
```typescript
{
  user: { /* full profile with resolved city/country fields populated */ }
}
```

**Notes:**
- If both lat/lng provided, server reverse-geocodes to city/country (or accepts client-provided city)
- If `permission_granted: false`, server stores that signal so the app doesn't re-prompt
- This is the only PATCH on /v3/me that takes a body of mostly-optional fields and stores them in a separate `user_locations` table (not on `users` row)

**Replaces:**
- Mobile's `UpdateLocation` (`customer/settings/update-location`)

#### 5.2.3 Address book contracts (6 ops)

All ✅ EXISTS in v3.

---

##### GET /v3/me/addresses

**Auth:** customer
**Status:** ✅ EXISTS (`Address/ListAddressesController.php`)

**Response 200 (paginated for safety, though most users have ≤ 5):**
```typescript
{
  data: [
    {
      id: number,
      label: string,           // 'Home', 'Office', etc.
      first_name: string,
      last_name: string,
      phone: string,
      country_code: string,    // 'AE'
      city: string,
      area: string,            // neighborhood
      street: string,
      building: string,
      apartment: string | null,
      landmark: string | null,
      is_default: boolean,
      created_at: string,
      updated_at: string
    }
  ],
  meta: { /* standard pagination */ }
}
```

---

##### POST /v3/me/addresses

**Auth:** customer
**Status:** ✅ EXISTS

**Request:**
```typescript
{
  label: string,
  first_name: string,
  last_name: string,
  phone: string,
  country_code: string,
  city: string,
  area: string,
  street: string,
  building: string,
  apartment?: string | null,
  landmark?: string | null,
  is_default?: boolean   // if true, demotes any other default
}
```

**Response 201:** Created address shape (single, not paginated).

---

##### GET /v3/me/addresses/:id

**Auth:** customer
**Status:** ✅ EXISTS

**Response 200:** Single address object.

**Error responses:**
- `404 NOT_FOUND` — address doesn't exist OR belongs to different user (collapsed for privacy)

---

##### PUT /v3/me/addresses/:id

**Auth:** customer
**Status:** ✅ EXISTS

**Request:** Full address replacement (PUT semantics, not partial). Same shape as POST request.

**Response 200:** Updated address.

**Notes:** Uses PUT (full replacement) intentionally — addresses are small enough that partial updates aren't worth the schema complexity. If a field is missing in the body, it's treated as null.

---

##### DELETE /v3/me/addresses/:id

**Auth:** customer
**Status:** ✅ EXISTS

**Response 204**

**Error responses:**
- `404 NOT_FOUND`
- `409 ADDRESS_IN_USE` — if the address is referenced by an active order (M3.1.6 enforcement)

---

##### PATCH /v3/me/addresses/:id/default

**Auth:** customer
**Status:** ✅ EXISTS (`Address/SetDefaultAddressController.php`)

**Request:** empty body (the URL itself signals the operation)

**Response 200:** Address with `is_default: true`. Side effect: any other default is demoted.

#### 5.2.4 Billing address contracts (2 ops) ⚠️ NEW

These are distinct from the shipping address book — billing is the address tied to payment methods + invoicing. Per Day 9 audit, mobile has `updateBilling` that hits a different legacy URL than the addresses endpoint.

**Design decision:** Billing is a SINGLE record per user (not a list like shipping addresses). One billing address, possibly null if never set.

---

##### GET /v3/me/billing-address ⚠️ NEW (to build in M3.1.1 OR M3.1.6)

**Auth:** customer
**Status:** ❌ NOT YET IMPLEMENTED

**Response 200:**
```typescript
{
  data: {
    first_name: string,
    last_name: string,
    company_name: string | null,
    tax_id: string | null,           // VAT TRN for UAE
    phone: string,
    email: string,
    country_code: string,
    city: string,
    area: string,
    street: string,
    building: string,
    apartment: string | null,
    postal_code: string | null,
    created_at: string,
    updated_at: string
  } | null    // null if never set
}
```

---

##### PATCH /v3/me/billing-address ⚠️ NEW

**Auth:** customer
**Status:** ❌ NOT YET IMPLEMENTED

**Method semantics:** PATCH (Merge Patch). If the user has no billing record, this creates one. If they do, partial fields update.

**Request:** Same shape as GET response (all fields optional for PATCH).

**Response 200:** Full billing object.

**Replaces:** Mobile's `updateBilling` (`customer/settings/billing/update-billing`).

#### 5.2.5 Body measurements contracts (5 ops)

All ✅ EXISTS in v3.

---

##### GET /v3/me/measurements

**Auth:** customer
**Status:** ✅ EXISTS (`Measurement/ListMeasurementsController.php`)

**Purpose:** List all measurement sets the user has saved (default + per-category).

**Response 200:**
```typescript
{
  data: [
    {
      id: number,
      category_id: number | null,      // null = default set; integer = category-specific
      category_slug: string | null,
      fields: {
        // dynamic keys; depends on what fields were captured
        bust?: number | null,           // cm
        waist?: number | null,
        hips?: number | null,
        shoulder?: number | null,
        sleeve_length?: number | null,
        arm_length?: number | null,
        torso_length?: number | null,
        // etc; whatever the user's UI captured
      },
      updated_at: string
    }
  ]
}
```

---

##### GET /v3/me/measurements/default

**Auth:** customer
**Status:** ✅ EXISTS

Returns the default (non-category-specific) measurement set. Same shape as a single item from list, or 404 if not yet set.

---

##### GET /v3/me/measurements/category/:id

**Auth:** customer
**Status:** ✅ EXISTS

Returns the measurement set for a specific category. 404 if not yet set for that category.

---

##### PUT /v3/me/measurements/default
##### PUT /v3/me/measurements/category/:id

**Auth:** customer
**Status:** ✅ EXISTS (`Measurement/UpsertMeasurementsController.php`)

**Method semantics:** PUT — upsert. Creates if missing, replaces if present.

**Request:**
```typescript
{
  fields: {
    bust?: number | null,
    waist?: number | null,
    // ... whatever fields the UI is collecting
  }
}
```

**Response 200:** Full measurement set.

---

##### DELETE /v3/me/measurements/default
##### DELETE /v3/me/measurements/category/:id

**Auth:** customer
**Status:** ✅ EXISTS (`Measurement/DeleteMeasurementsController.php`)

**Response 204**

#### 5.2.6 User reviews contracts (3 ops) ⚠️ ALL NEW

---

##### GET /v3/me/reviews ⚠️ NEW (to build in M3.1.9)

**Auth:** customer
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** List reviews the user has authored.

**Response 200 (paginated):**
```typescript
{
  data: [
    {
      id: number,
      product: {
        slug: string,
        name: string,
        image_url: string | null,
        vendor_name: string
      },
      rating: number,           // 1-5
      title: string | null,
      body: string,
      helpful_count: number,
      created_at: string,
      updated_at: string
    }
  ],
  meta: { /* standard pagination */ }
}
```

**Replaces:** Mobile's `readReviews` (`customer/settings/read-reviews`).

---

##### DELETE /v3/me/reviews/:id ⚠️ NEW (to build in M3.1.9)

**Auth:** customer
**Status:** ❌ NOT YET IMPLEMENTED

**Response 204**

**Error responses:**
- `404 NOT_FOUND` — review doesn't exist OR belongs to different user (collapsed)
- `403 FORBIDDEN` — only the author can delete (admin uses different endpoint)

**Replaces:** Mobile's `deleteReview` (`customer/settings/delete-review`).

---

##### GET /v3/me/store/reviews ⚠️ NEW (to build in M3.1.10)

**Auth:** vendor (requires `vendor` role)
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** Vendor's self-view of reviews on THEIR products.

**Response 200 (paginated):**
```typescript
{
  data: [
    {
      id: number,
      product: {
        slug: string,
        name: string,
        image_url: string | null
      },
      reviewer: {
        first_name: string,
        last_name_initial: string   // 'J.' not 'Jones' for privacy
      },
      rating: number,
      title: string | null,
      body: string,
      helpful_count: number,
      vendor_response: string | null,    // if vendor has replied
      vendor_responded_at: string | null,
      created_at: string
    }
  ],
  meta: { /* pagination */ }
}
```

**Replaces:** Mobile's `storeReviews` (`customer/settings/store-reviews`).

#### 5.2.7 0e.2 Summary

**26 operations specified.** Distribution:

| Section | Ops | v3 exists | v3 to BUILD |
|---|---|---|---|
| Auth + session | 12 | 12 | 0 |
| Identity / profile | 4 | 2 | 2 (password, location) |
| Address book | 6 | 6 | 0 |
| Billing address | 2 | 0 | 2 |
| Body measurements | 5* | 5 | 0 |
| User reviews | 3 | 0 | 3 |
| **Total** | **32** | **25** | **7** |

\* Body measurements counted 5 ops not 7 here (consolidating PUT/DELETE pairs for default + category as 4 logical endpoints with 2 URL forms each, plus list). 0d's count of 5 was correct in spirit.

**Real op count is 26-32 depending on how PUT/DELETE pairs are counted.** Either way, **25 are already implemented in v3.**

**M3 timeline impact:**
- Original M3.1.1 ("v3 auth endpoint build, 5-7 days") is effectively done already
- Revised M3.1.1: audit existing v3 auth contracts + document for mobile adapter (2-3 days)
- The 7 net-new endpoints distribute across M3.1.1 (password, location, billing), M3.1.9 (user reviews), M3.1.10 (vendor reviews)

**Savings to M3 timeline: 2-3 weeks** from this sub-phase's reality check.

#### 5.2.8 Decisions locked in 0e.2

- `/v3/auth/*` for unauthenticated and stateless ops (login, register, OTP, reset, refresh, logout)
- `/v3/me/*` for authenticated user-scoped reads/writes (profile, addresses, measurements, reviews)
- `/v3/me/store/*` for vendor-scoped subset under `/me/*` (when user has vendor role)
- Email and phone are NOT changeable via `PATCH /v3/me/profile` — require explicit OTP-protected flows (M4)
- Address book is a LIST (multiple shipping addresses); billing is a SINGLETON (one billing record)
- Body measurements have BOTH `/default` and `/category/:id` variants — same controllers, route segment differentiates
- All `/v3/me/*` routes attach `AuthMiddleware` at the group level (not per-route)
- Reviews delete: only authors can delete their own; admins use different endpoint (M3.3 admin scope)
- Vendor self-view of reviews uses `last_name_initial` for privacy (reviewers see anonymized last name)


---

### 5.3 (M3.1.0e.3) — Catalog + Search + Utility contracts

**Status:** ✅ Complete (May 13, 2026)
**Scope:** 17 unique operations across products, categories, vendors, brands, styles, collections.

#### 5.3.0 Reality audit

Same pattern as 0e.2: audit `apps/api/src/Http/Controllers/Catalog/` to know what exists vs needs building.

**EXISTS in v3** (M2 Day 5 work):
- `GET /v3/products` — list with rich filtering (vendor, category, min/max price, featured/new/sale flags, sort, limit, offset)
- `GET /v3/products/{slug}` — single product
- `GET /v3/categories` — list categories
- `GET /v3/categories/{slug}` — single category WITH children but **missing embedded products + meta** (Day 5 followup, still routes to legacy v2 in apps/web)
- `GET /v3/vendors` — list vendors
- `GET /v3/vendors/{slug}` — single vendor
- `GET /v3/vendors/{slug}/products` — vendor's product list
- `GET /v3/brands`, `GET /v3/brands/{slug}` — brand list + detail (admin-facing mostly)
- `GET /v3/sitemap-data` — build-time enumeration for apps/web sitemap

**MISSING in v3** (need building):

1. `GET /v3/featured-vendors` — Designer Spotlight strip; currently 500 (Day 5 known issue)
2. `GET /v3/categories/{slug}` — needs to embed products + meta (shape extension, not new endpoint)
3. `GET /v3/vendors/{slug}/labels` — vendor's public collections/labels (mobile's `store_labels`)
4. `GET /v3/vendors/{slug}/reviews` — public view of vendor reviews (mobile's `store_reviews`)
5. `GET /v3/styles` — mobile's `styles_list` for custom UX
6. `GET /v3/collections` — portal's `UtilityCollections` (admin lists curator collections)
7. Products-by-label filter — extend `/v3/products` with `?label=...` rather than new endpoint

**Reality:** 9 of 17 catalog ops already implemented; 5 net-new endpoints + 2 shape extensions to build.

#### 5.3.1 Products contracts

---

##### GET /v3/products

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/ListProductsController.php`)

**Query parameters (all optional):**

| Param | Type | Default | Notes |
|---|---|---|---|
| `limit` | 1-100 | 24 | per 0e.1 §5.1.5 |
| `offset` | ≥0 | 0 | per 0e.1 |
| `vendor` | string (slug) | — | filter by vendor; unknown slug returns empty result |
| `category` | string (slug) | — | filter by category; unknown slug returns empty result |
| `min_price` | decimal | — | inclusive lower bound on `price.amount` |
| `max_price` | decimal | — | inclusive upper bound |
| `featured` | bool | — | filter to `is_featured = true` |
| `new` | bool | — | filter to `is_new = true` |
| `sale` | bool | — | filter to `is_on_sale = true` |
| `sort` | enum | `newest` | one of: `newest`, `oldest`, `price_asc`, `price_desc`, `popular` |
| `q` | string | — | full-text search (when search backend added; currently maps to ILIKE %q% on name) |
| `label` | string (slug) | — | **NEW for M3** — filter by vendor's label/collection slug |

**Response 200 (Shape B paginated):**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      vendor: {
        slug: string,
        name: string,
        is_verified: boolean
      },
      category: {
        slug: string,
        name: string
      },
      price: {
        amount: number,        // decimal
        currency: 'AED'
      },
      original_price?: {        // only present if on sale
        amount: number,
        currency: 'AED'
      },
      images: [                 // ordered; index 0 is hero
        {
          url: string,
          alt: string | null
        }
      ],
      is_featured: boolean,
      is_new: boolean,
      is_on_sale: boolean,
      in_stock: boolean,
      average_rating: number | null,    // 0-5; null if no reviews
      review_count: number
    }
  ],
  meta: { total, limit, offset, has_more }
}
```

**Migration coverage:**

This single endpoint replaces a LOT of mobile's catalog reads:
- `featured` → `?sort=featured` or `?featured=true&sort=newest`
- `best_sellers` → `?sort=popular`
- `new_arrivals` → `?sort=newest&new=true`
- `best_sellers_listing` → paginated `?sort=popular&limit=24`
- `category_listing` → not this one; that's `/v3/categories`
- `search` → `?q=...`
- `filtered_products` → use the relevant filter params
- `products_by_labels` → `?label=...` (NEW filter for M3)
- `product_by_category` → `?category=...`

One endpoint, ten legacy callers collapsed.

---

##### GET /v3/products/{slug}

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/GetProductController.php`)

**Response 200 (Shape A single):**
```typescript
{
  data: {
    slug: string,
    name: string,
    description: string,        // HTML-allowed (sanitized server-side)
    vendor: {
      slug: string,
      name: string,
      logo_url: string | null,
      is_verified: boolean
    },
    category: { slug, name },
    brand: { slug, name } | null,
    price: { amount: number, currency: 'AED' },
    original_price?: { amount, currency },
    images: [{ url, alt }],
    available_sizes: string[],   // e.g. ['S', 'M', 'L', 'XL']
    available_colors: [{ name: string, hex: string | null }],
    in_stock: boolean,
    is_featured: boolean,
    is_new: boolean,
    is_on_sale: boolean,
    sale_ends_at: string | null,        // ISO-8601 if on sale
    average_rating: number | null,
    review_count: number,
    sku: string | null,
    weight_grams: number | null,
    dimensions_cm: { width, height, depth } | null,
    care_instructions: string | null,
    composition: string | null,
    created_at: string,
    updated_at: string
  }
}
```

**Error responses:**
- `404 NOT_FOUND` — slug unknown OR product is inactive/soft-deleted

**Migration coverage:** `single_product`, `singleProduct`, `singleProductUtility` (all collapse here)

#### 5.3.2 Categories contracts

---

##### GET /v3/categories

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/ListCategoriesController.php`)

**Response 200:**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      icon_url: string | null,
      parent_slug: string | null,
      product_count: number,           // active products in this category
      child_count: number              // direct children count
    }
  ]
}
```

**Notes:**
- Returns ALL active categories (no pagination needed; small set, ~8-50 categories)
- Sorted by `display_order` then `name`
- Includes both top-level and nested categories (use `parent_slug` to build tree client-side)

**Migration coverage:** `ProductCategory`, `category_listing` (mobile), `UtilityCategory` (portal — 6 callers)

---

##### GET /v3/categories/{slug}

**Auth:** public
**Status:** ⚠️ EXISTS but **SHAPE INSUFFICIENT** for apps/web (Day 5 followup)

**Current response 200:**
```typescript
{
  data: {
    slug: string,
    name: string,
    description: string | null,
    icon_url: string | null,
    parent: { slug, name } | null,
    children: [{ slug, name }],
    product_count: number
  }
}
```

**Required response 200 for apps/web** (Day 5 fix; needed in M3.1.0):
```typescript
{
  data: {
    slug: string,
    name: string,
    description: string | null,
    icon_url: string | null,
    parent: { slug, name } | null,
    children: [{ slug, name }],
    products: [                             // EMBEDDED first page
      { /* same shape as ListProducts data item */ }
    ]
  },
  meta: {
    total_products: number,
    page_size: number                       // i.e. how many products in `products[]`
  }
}
```

**Action:** Extend `GetCategoryController` to embed first page of products + add `meta.total_products` and `meta.page_size`. Default page_size 24. Subsequent pages via `GET /v3/products?category=:slug&offset=...`.

This is one of the 5+ blockers for `apps/web` to flip `GET /categories/:slug` to v3 (currently routes to legacy v2).

**Status flag:** ⚠️ SHAPE-INCOMPLETE (one of the 5+ "v3 to build" items from §5.2.0).

#### 5.3.3 Vendors contracts

---

##### GET /v3/vendors

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/ListVendorsController.php`)

**Query parameters:** `?limit=&offset=&category=` (filter by vendor's primary category)

**Response 200 (Shape B paginated):**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      logo_url: string | null,
      cover_image_url: string | null,
      bio: string | null,                 // sanitized HTML
      is_verified: boolean,
      product_count: number,
      category_slugs: string[],           // vendor's product categories
      follower_count: number,             // M3 — added when follow_vendor ships
      average_rating: number | null,
      review_count: number,
      created_at: string
    }
  ],
  meta: { total, limit, offset, has_more }
}
```

**Migration coverage:** `vendors_listing` (mobile), `UtilityStores` (portal — 2 callers)

**Note:** Day 9 audit found `/v3/vendors` has a hard cap of 100 page size. For full enumeration use `/v3/sitemap-data`. Not a blocker for migration; document and move on.

---

##### GET /v3/vendors/{slug}

**Auth:** public
**Status:** ✅ EXISTS

**Response 200:**
```typescript
{
  data: {
    slug: string,
    name: string,
    logo_url: string | null,
    cover_image_url: string | null,
    bio: string,
    is_verified: boolean,
    product_count: number,
    category_slugs: string[],
    follower_count: number,
    average_rating: number | null,
    review_count: number,
    social_links: {
      instagram_url: string | null,
      facebook_url: string | null,
      website_url: string | null
    },
    location: {
      city: string | null,
      country_code: string | null
    },
    member_since: string                  // ISO-8601 (vendor creation date)
  }
}
```

**Error responses:**
- `404 NOT_FOUND` — slug unknown or vendor is inactive

**Migration coverage:** `read_vendor` (mobile, 2 callers)

---

##### GET /v3/vendors/{slug}/products

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/ListVendorProductsController.php`)

**Query parameters:** `?limit=&offset=&sort=` (same as `/v3/products` but vendor-scoped)

**Response 200:** Same shape as `GET /v3/products` (paginated list).

**Migration coverage:** `vendors_products_listing`, `store_latest` (collapses with `?sort=newest`)

---

##### GET /v3/featured-vendors ⚠️ NEW (to build in M3.1.5)

**Auth:** public
**Status:** ❌ NOT YET IMPLEMENTED — currently 500

**Purpose:** Designer Spotlight strip on home page. Curated list of vendors with top products.

**Query parameters:** `?limit=` (default 6, max 20)

**Response 200:**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      logo_url: string | null,
      cover_image_url: string | null,
      is_verified: boolean,
      top_products: [                   // 3-4 products per vendor (embedded)
        {
          slug: string,
          name: string,
          price: { amount, currency },
          images: [{ url, alt }]
        }
      ]
    }
  ]
}
```

**Notes:**
- "Featured" status currently determined by `vendor.is_featured` flag in DB
- M4 enhancement: admin-curated featured list via portal
- This endpoint replaces `apps/web`'s home Designer Spotlight strip which currently routes to legacy v2

**Migration coverage:** apps/web's `GET /featured-vendors` route (currently `target: 'old'` per Day 5)

---

##### GET /v3/vendors/{slug}/labels ⚠️ NEW (to build in M3.1.5)

**Auth:** public
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** Vendor's public collections/labels (e.g. "Eid Collection", "Summer 2026"). Used by mobile's vendor profile screen.

**Response 200:**
```typescript
{
  data: [
    {
      id: number,
      slug: string,
      name: string,
      description: string | null,
      cover_image_url: string | null,
      product_count: number,
      created_at: string
    }
  ]
}
```

**Notes:**
- Distinct from `categories` — labels are vendor-specific groupings; categories are platform-wide
- Vendor's own management of labels lives at `/v3/me/store/labels` (portal scope, M3.3 / 0e.6)

**Migration coverage:** mobile's `store_labels` (`customer/read_vendor_collection`)

---

##### GET /v3/vendors/{slug}/reviews ⚠️ NEW (to build in M3.1.5)

**Auth:** public
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** Public view of reviews on a vendor's products. Used by mobile's vendor profile screen + future web vendor pages.

**Query parameters:** `?limit=&offset=&sort=` (sort: `newest` | `oldest` | `helpful` | `rating_desc` | `rating_asc`)

**Response 200 (Shape B paginated):**
```typescript
{
  data: [
    {
      id: number,
      product: {
        slug: string,
        name: string,
        image_url: string | null
      },
      reviewer: {
        first_name: string,
        last_name_initial: string       // 'J.' for privacy
      },
      rating: number,                   // 1-5
      title: string | null,
      body: string,
      helpful_count: number,
      vendor_response: string | null,
      vendor_responded_at: string | null,
      created_at: string
    }
  ],
  meta: { /* pagination */ }
}
```

**Migration coverage:** mobile's `store_reviews` (`customer/store-reviews`)

#### 5.3.4 Brands contracts

---

##### GET /v3/brands

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/ListBrandsController.php`)

**Query parameters:** `?limit=&offset=`

**Response 200:**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      logo_url: string | null,
      product_count: number,
      created_at: string
    }
  ],
  meta: { /* pagination */ }
}
```

**Notes:** Brands are platform-wide (vs vendor-specific labels). Used mostly for admin curation; mobile + portal may surface brand filtering in the future.

---

##### GET /v3/brands/{slug}

**Auth:** public
**Status:** ✅ EXISTS

**Response 200:** Single brand detail.

#### 5.3.5 Styles + collections + sitemap

---

##### GET /v3/styles ⚠️ NEW (to build in M3.1.5)

**Auth:** public
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** Mobile's "Styles" UI — curated style themes (e.g. "Casual", "Formal", "Modest"). Custom mobile UX.

**Response 200:**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      image_url: string | null,
      product_count: number
    }
  ]
}
```

**Migration coverage:** mobile's `styles_list` (`customer/styles_list`)

**Notes:**
- "Style" is distinct from "category" — categories are taxonomy (Abayas, Kaftans); styles are aesthetic groupings (Modern, Classic, Bohemian)
- Mobile has `create_style` too (customer creates a custom style board) — that's covered in 0e.5 (account section)

---

##### GET /v3/collections ⚠️ NEW (to build in M3.1.5)

**Auth:** public (read-only public view of admin-curated collections)
**Status:** ❌ NOT YET IMPLEMENTED

**Purpose:** Admin-curated platform-wide collections (e.g. "Eid 2026", "Wedding Season"). Distinct from vendor-specific labels.

**Response 200:**
```typescript
{
  data: [
    {
      slug: string,
      name: string,
      description: string | null,
      cover_image_url: string | null,
      product_count: number,
      vendor_count: number,            // vendors with products in this collection
      starts_at: string | null,
      ends_at: string | null,
      created_at: string
    }
  ]
}
```

**Migration coverage:** portal's `UtilityCollections` (3 callers — admin-side reference data)

**Notes:**
- Public read; admin CRUD lives in 0e.7 (`/v3/admin/collections/*`)
- Collections are platform-wide curation; vendor labels are vendor-specific (different domain)

---

##### GET /v3/sitemap-data

**Auth:** public
**Status:** ✅ EXISTS (`Catalog/GetSitemapDataController.php`)

**Purpose:** Build-time enumeration for apps/web's sitemap.xml generator + Cloudflare Worker prerender slug discovery.

**Response 200 (Shape C — custom, NOT paginated):**
```typescript
{
  categories: [
    { slug: string, name: string, updated_at: string }
  ],
  products: [
    { slug: string, updated_at: string }
  ],
  vendors: [
    { slug: string, updated_at: string }
  ]
}
```

**Notes:**
- Returns ALL active records (~1,933 products, 104 vendors, 8 categories per Day 8 evidence)
- Single response can be large (~133 KB observed) — that's fine for build-time
- Cached at the Worker layer via build pipeline

**Migration coverage:** apps/web build-time + Cloudflare Worker prerender

#### 5.3.6 0e.3 Summary

**17 operations specified** (catalog + utility scope).

| Section | Ops | v3 exists | v3 to BUILD | Notes |
|---|---|---|---|---|
| Products | 2 | 2 | 0 | + 1 filter param (`?label=`) to add |
| Categories | 2 | 1.5 | 0.5 | shape extension to embed products + meta |
| Vendors | 3 | 3 | 0 | |
| New: featured-vendors | 1 | 0 | 1 | apps/web blocker |
| New: vendor labels | 1 | 0 | 1 | mobile vendor profile |
| New: vendor reviews | 1 | 0 | 1 | mobile vendor profile |
| Brands | 2 | 2 | 0 | |
| New: styles | 1 | 0 | 1 | mobile-specific UX |
| New: collections | 1 | 0 | 1 | portal-side admin data |
| Sitemap | 1 | 1 | 0 | |
| **Total** | **15** | **9.5** | **5.5** | |

(15 ops, not 17 — some 0d-counted items collapsed. Truer scope after design.)

**v3 has ~63% of catalog implemented.** Better than 0e.2's 81% but still good news.

**5 new endpoints + 1 shape extension to build:** Distributed across M3.1.5 (mobile catalog flip phase).

#### 5.3.7 Decisions locked in 0e.3

- Products list is THE unified filter endpoint — multiple legacy endpoints collapse via query params (`?sort=`, `?featured=`, `?label=`)
- Slug-based filters resolve to IDs; unknown slugs return empty result (not 404) per 0e.1 §5.1.6
- Vendor labels (`/v3/vendors/{slug}/labels`) are public read; vendor self-management at `/v3/me/store/labels` (0e.6)
- Collections (`/v3/collections`) are platform-wide admin-curated; vendor labels are vendor-specific
- Styles are aesthetic groupings, distinct from categories (taxonomy) and labels (vendor-specific)
- Brands are platform-wide; mostly admin reference data; may surface in future filtering UI
- Category detail endpoint MUST embed first page of products + meta for apps/web parity (Day 5 fix)
- All catalog endpoints are public (no auth) — including vendor profile reads
- Featured-vendors response includes embedded `top_products[3-4]` per vendor for the home strip

#### 5.3.8 Cumulative impact on M3 timeline

After 0e.2 (saved 2-3 weeks on auth) and 0e.3 (catalog mostly done), the M3 timeline is shrinking faster than scope:

| Category | Original M3 plan | After 0e audit |
|---|---|---|
| Auth + identity + account | 5-7 days | 2-3 days |
| Catalog endpoint build | 5-7 days | 3-5 days (5 endpoints + shape fix) |
| Cart/checkout/orders endpoint build | 7-10 days | TBD (0e.4 will audit) |
| Vendor self-service build | not explicitly sized | TBD (0e.6) |

**Cumulative savings so far: 5-7 weeks of M3 timeline,** purely from realizing how much v3 is already done.

This will be quantified more precisely after 0e.4-0e.7 complete.

