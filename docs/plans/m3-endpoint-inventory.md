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

