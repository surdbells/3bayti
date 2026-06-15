# Handover — 3bayti order-scoped customer↔vendor chat (Phases 3–7)

Paste this into a fresh conversation to continue. Phases **1 and 2 are complete, committed, and pushed**; the API suite is green at **1574 tests**.

---

## 1. What this feature is
Replace the misused admin→vendor `/messages` inbox with a real **order-item-scoped chat between the buying customer and the selling vendor**. When an order is paid, a thread is auto-created per order item, seeded with the full order details (incl. shipping). Both parties chat (polling now, WebSocket later); email+push fire on unread; and customers/vendors are **blocked from sharing personal contact info** to prevent off-platform deals.

### Locked decisions (already approved by Sodiq — do not re-ask)
- **Scope = per order item** (one conversation per `order_items.id`, unique).
- **PII policy = block + warn the sender** (stronger than the legacy flag-only).
- **Real-time = polling now, WebSocket-ready structure** (stack has a Ratchet/Node WS sidecar for later).
- **First system message includes full shipping details** (it's the order's own data; system messages are never moderated).

---

## 2. Environment & workflow (unchanged from prior sessions)
- Repo: `github.com/surdbells/3bayti.git`, branch `main`. Claude clone at `/home/claude/work/3bayti`. User = **Sodiq** (CEO DOST HQ/Kodek, Lagos).
- Monorepo (pnpm/turbo): `apps/api` (Slim 4 + Doctrine ORM 3 + PostgreSQL 16, ramsey/uuid ^4.7, Doctrine Migrations ^3.8, DBAL 4.2), `apps/web` (Angular 21 SSR), `apps/portal` (Angular **19.2** SPA, Cloudflare Pages, angular.json project key `abayti`, builds to `dist/abayti/browser`), `apps/mobile` (Ionic Angular 21).
- Claude env: PHP 8.3.6 + composer + pnpm + node 22. `vendor/bin/phpunit` works. **No local Postgres** — `orm:validate-schema` and migrations need the server; locally the only error you'll see is `could not find driver` (missing local pdo_pgsql) which is NOT a mapping error. `php -l` + PHPUnit (which mocks the EM) are the local gates.
- **Operating rules (Sodiq's userPreferences):** implementation plan + approval before coding; phased delivery; no stubs/placeholders; production-ready; premium UI/UX; **status snapshot after every commit**; always `git pull --no-edit origin main` before push; **always build the portal locally before pushing portal changes**; senior-architect mindset; stage-gate against plan; create a handover when the chat gets long.
- **Build portal:** `cd apps/portal && pnpm run build` (~33–51s; success = "Application bundle generation complete"). **Deploy portal:** build + `pnpm exec wrangler pages deploy dist/abayti/browser --project-name 3bayti-portal --commit-dirty=true --skip-caching`.
- Benign warnings to ignore: html2canvas CommonJS; `PHP Warning: Module "pdo_pgsql" is already loaded`.

### ⚠️ MANDATORY deploy step whenever entities change (also fixes a known notifications 500)
Production caches entity metadata to FILES (`config/doctrine.php` → `PhpFilesAdapter` at `var/cache/doctrine`, proxies not auto-generated in prod). A plain `git pull` + migrate + reload leaves the metadata cache STALE → Doctrine throws `[Semantical Error] Class X has no field or association named Y`. The deploy MUST be:
```bash
cd /www/wwwroot/3bayti && git pull origin main
cd apps/api && php bin/console migrations:migrate -n        # applies …000005 (chat tables) + any earlier pending
php bin/console orm:clear-cache:metadata && php bin/console orm:clear-cache:query && php bin/console orm:generate-proxies
/etc/init.d/php-fpm-83 reload
```
Server: `root@m23241` (m23241.contaboserver.net), aaPanel, Apache + `php-fpm-83`, web user `www`, umask 0077. API live at `https://api-v3.3bayti.ae`. Logs: `apps/api/var/logs/3bayti-api-YYYY-MM-DD.log` (Monolog JSON).

---

## 3. DONE — Phase 1 (`8a95708`) + Phase 2 (`c667904`)
All under namespace `Bayti\Api\Domain\Chat` (`apps/api/src/Domain/Chat/`):

- **Conversation.php** (table `chat_conversations`): `id` bigint, `uuid` (uuid7, unique), `customer`(User), `vendor`(Vendor), `order`(Order), `orderItem`(OrderItem, **unique**), `status` (active/closed), `customerUnreadCount`, `vendorUnreadCount`, `lastMessageAt`, `lastMessagePreview`(200), `created/updatedAt`. Consts `STATUS_ACTIVE/CLOSED`, `PARTY_CUSTOMER/VENDOR/SYSTEM`. `recordMessage(senderType, preview)` bumps the recipient's unread (customer→vendor, vendor→customer, **system→both**), refreshes preview (trimmed to 200). `markReadFor(party)` resets one side; `unreadFor(party)`; `close()`; `isActive()`.
- **Message.php** (table `chat_messages`): `id`, `uuid`, `conversation`, `sender`(User, nullable for system), `senderType`, `type` (text/image/system), `content`, `contentAr`(nullable), `isFlagged`, `flagType`, `status` (sent/blocked/redacted), `createdAt`. Private ctor + factories `fromCustomer(c,user,content,type='text')` / `fromVendor(...)` / `system(c,content,?contentAr)`. `block(flagType)` → status=blocked + flagged (NOT delivered). `redact(content,flagType)` → status=redacted (delivered, masked). **`isDelivered()` = status !== blocked** (redacted IS delivered).
- **ModerationService.php** (FINAL — tested with a real instance): `check(content): ModerationResult` and `redact(content, result, mask='•••')`. EN+AR regex for `FLAG_PHONE/EMAIL/SOCIAL/ADDRESS`, ported from legacy `class/ModerationService.php`.
- **ModerationResult.php**: `isFlagged`, `flagTypes[]`, `matches`; `flagTypeString()` ('phone,email'), `allMatches()` (longest-first, safe masking), `labels()` (human warning text e.g. "phone number, email address").
- **ConversationRepository.php**: `save`/`add`(no-flush), `findByOrderItem(OrderItem|int)` (idempotent provisioning), `findByUuid`, `findForVendor(int[] vendorIds, limit, offset)` → `{items, total, unread}` (unread = SUM vendorUnreadCount), `findForCustomer(int customerId, limit, offset)` → same with customerUnreadCount.
- **MessageRepository.php**: `save`/`add`, `findForConversation(convId, limit, ?beforeId, ?afterId, ?viewerParty)` — blocked messages visible only to their own sender (pass `$viewerParty`); returns chronological (oldest→newest); `findByUuid`.
- **OrderDetailsMessageBuilder.php** (NON-final, so it's mockable in the provisioner test): `build(Order, OrderItem): array{0:en, 1:ar}` — bilingual seed: ORDER (ref/date/status), ITEM (product/qty/size/color/custom), MEASUREMENTS (`formatMeasurement` decodes JSON to humanised key/value, free text → 'Details'), note, PRICING (unit, item+order subtotals, delivery, discount/gift card when >0, total, `currency`-prefixed), SHIPPING (recipient/phone/street/city+state/country/postal), + no-PII reminder.
- **OrderChatProvisioner.php** (NON-final; deps `EntityManagerInterface` + `OrderDetailsMessageBuilder` + `LoggerInterface`): `provisionForOrder(Order): int` — per item, skip if `findByOrderItem` exists, else create Conversation + `Message::system(...)` via the builder, `recordMessage(PARTY_SYSTEM, en)`, single `flush()`. Idempotent (unique `order_item_id` is the backstop).
- **migrations/Version20260614000005.php**: CREATE `chat_conversations` + `chat_messages` (idempotent `IF NOT EXISTS`; BIGINT FKs to users/vendors/orders/order_items + chat_conversations; unique uuid/order_item; indexes on (vendor_id,last_message_at), (customer_id,last_message_at), (conversation_id,id)).
- **Hooks (order PAID only):** `src/Http/Controllers/Checkout/NoonWebhookController.php` (the `$transition === 'paid'` branch, after `pushNotifications->orderPaid`) and `InitiateCheckoutController.php` (the gift-card full-cover `markPaid` path, ~line 387). Both call `$this->chatProvisioner->provisionForOrder($order)` in try/catch (must never abort the webhook/checkout). **The pre-payment checkout-initiate path (~line 461) is intentionally NOT a hook.** Both controllers got a `private readonly \Bayti\Api\Domain\Chat\OrderChatProvisioner $chatProvisioner` constructor param (NoonWebhook: appended after the required `$logger`; InitiateCheckout: inserted before the defaulted `$logger`).
- **DI** (`config/di.php`): `ModerationService`, `OrderDetailsMessageBuilder`, `OrderChatProvisioner` all `\DI\autowire()`.
- **Tests** (`apps/api/tests/Domain/Chat/`): `ModerationServiceTest` (17), `ConversationTest` (7), `MessageTest` (4), `OrderDetailsMessageBuilderTest` (2), `OrderChatProvisionerTest` (3). Full suite **1574**.

---

## 4. REMAINING PLAN

### Phase 3 — Chat API (role-scoped) — DO THIS NEXT
Customer endpoints under **`/v3/chat`**, vendor endpoints under **`/v3/vendor/chat`** (mirrors `/v3/orders` for customers, `/v3/vendor/*` for vendors). All groups `->add(AuthMiddleware::class)`.

Proposed endpoints (fold conversation meta into the messages response so opening a chat is one call; keep mark-read explicit so background polling doesn't wrongly clear unread):
- `GET /v3/chat/conversations` (customer list) · `GET /v3/vendor/chat/conversations` (vendor list, across all the owner's stores)
- `GET /v3/chat/conversations/{uuid}/messages?after_id=&before_id=&limit=` (+ conversation meta in `meta`) · vendor equivalent
- `POST /v3/chat/conversations/{uuid}/read` (mark read for the viewer) · vendor equivalent
- `POST /v3/chat/conversations/{uuid}/messages` (**send, with block+warn moderation folded in here — see 3c**) · vendor equivalent

**Suggested sub-phases (commit each; status snapshot after each):**
- **3a** `ChatSerializer` + customer read endpoints (list, messages+meta, mark-read) + routes + HttpTestCase tests.
- **3b** vendor read endpoints (store-scoped via `VendorRepository::findIdsByOwnerUser`) + routes + tests.
- **3c** send endpoint (customer + vendor) **with `ModerationService` block+warn**: on `check()` flagged → persist `Message` then `->block(flagTypeString)`, do NOT `recordMessage` (not delivered), return **422** with `ErrorCodes` + `result->labels()` so the client warns the sender; clean → persist + `recordMessage(party, content)` + flush. (This means Phase 4 narrows to *admin* visibility of flagged attempts.)
- **3d** portal route-keys + any api-client wiring (see §6).

#### Conventions to follow (already verified this session)
- Controllers are flat, `final class`, `use Bayti\Api\Http\Responder;` (methods `ok(array)`, `created(array)`, `noContent()`), implement `protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }`, ctor takes `protected readonly ResponseFactoryInterface $responseFactory, private readonly EntityManagerInterface $em, ...`.
- Resolve the user: `$user = $request->getAttribute(AuthMiddleware::ATTR_USER); if (!$user instanceof User) throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');`
- **Access checks:** customer controllers require `$conv->getCustomer()->getId() === $user->getId()`; vendor controllers require `in_array($conv->getVendor()->getId(), $ownerVendorIds, true)` where `$ownerVendorIds = $this->em->getRepository(Vendor::class)->findIdsByOwnerUser($user)`. On mismatch throw `HttpException::notFound(...)` (don't reveal existence).
- `HttpException` factories: `unauthorized(code,msg)`, `forbidden(msg)`, `notFound(msg)`, `badRequest(msg)`, `conflict(...)`, `validation(...)`. `ErrorCodes::AUTH_INVALID_TOKEN`. (For the 422 on blocked PII, reuse a validation-style code; check `src/Http/Errors/ErrorCodes.php` for the closest existing constant or add one.)
- Pagination: clamp `limit` (default 10–20, max 50) + `offset`; existing list controllers return `$this->ok(['conversations'=>[...], 'pagination'=>['limit','offset','count','total','unread']])` — OR use `Bayti\Api\Http\PaginatedEnvelope::build(items,total,limit,offset)`. Match whichever the mobile/portal client expects; `ListOrdersController` uses the explicit `ok()` shape.
- **ChatSerializer (to create, `src/Http/Serializers/ChatSerializer.php`, autowired):** `conversationListShape(Conversation, string $viewerParty)` → uuid, status, last_message_at, last_message_preview, unread (viewer side via `unreadFor`), counterparty {for customer: vendor `getName`/`getSlug`/`getLogoUrl`; for vendor: customer `getFirstName`/`getLastName`}, order_reference (`$c->getOrder()->getOrderReference()`), item {product_name_snapshot, image_snapshot} from `$c->getOrderItem()`. `messageShape(Message)` → uuid, sender_type, type, content, content_ar, is_flagged, status, created_at (ISO). Let the client decide bubble alignment from `sender_type`.
- Display getters available: `Vendor::getName()/getSlug()/getLogoUrl()`, `User::getFirstName()/getLastName()/getEmail()`, `OrderItem::getProductNameSnapshot()/getProductImageSnapshot()`, `Order::getOrderReference()`.

#### Testing (HttpTestCase — `apps/api/tests/Http/HttpTestCase.php`)
`makeUser(id:)` + `->setRoles(vendor:true/admin:true)`; `bind(class,value)`; `stubEm(callable)` mocks `getRepository` via `willReturnMap`; `handle()`; `jsonRequest(method, uri, bodyArray, headers)` — **body must be an array, use `[]` for GET, never null**; `jsonBody()`. JWT via `$app->getContainer()->get(JwtService::class)->issueTokenPair($user)->accessToken`. Services must be NON-final to mock; `User/Vendor/Order/OrderItem/Conversation` are non-final (createMock OK). v3 envelope: `PaginatedEnvelope::build` → `{data, meta:{total,limit,offset,has_more}}`; `::single` → `{data}` (namespace `Bayti\Api\Http\PaginatedEnvelope`).

### Phase 4 — Admin moderation visibility
Surface flagged/blocked attempts to admins (e.g. `GET /v3/admin/chat/flagged` listing `Message` rows where `is_flagged = true`, with conversation/order/party context). Admin group `/v3/admin` (controllers use `__invoke($request,$response,$args)` with `$args['id']`). Add admin route-keys.

### Phase 5 — Notifications (email + push to the other party on unread, debounced)
Reuse `MailerInterface::send(to, subject, textBody, htmlBody, context)`, `NotificationLog::sent(?orderId, template, recipient)` (orderId nullable → bell feed), and the push infra (`PushNotificationService`). Debounce: only notify if the recipient hasn't been notified for this conversation within N minutes (store `last_notified_at` per party on the conversation, or a small throttle table). Templates e.g. `chat.message.customer` / `chat.message.vendor`. Render in the notifications list mapper (see `ListVendorNotificationsController`).

### Phase 6 — Real-time (polling now, WS-ready)
Client polls `GET …/messages?after_id=<lastSeenId>` on the open chat (e.g. every 4–6s) and the conversation list for unread badges. Keep the message shape and the `after_id` cursor identical to what a future WS push would emit, so the Ratchet/Node sidecar can later push the same payloads with no client change.

### Phase 7 — Frontends
- **Mobile** (`apps/mobile/src/app/pages/`): wire the existing `chat`, `chat-orders`, `chat-vendors`, `vendor-chat-list` pages (+ a customer `messages` entry) to the new endpoints; bubble UI; PII-block warning toast on 422; polling.
- **Portal** (`apps/portal/src/app/vendor/vendor-messages`): **rebuild** this (currently the admin-inbox behavior) as the vendor's order-chat list + thread. Use the `ax-*` design system and the `PortalCrudAdapter` (see §6). Premium UI/UX (Sodiq's bar): clean conversation list with unread badges + counterparty + order ref, threaded view, composer with the block-warning state.

---

## 5. Legacy reference (`/home/claude/work/legacy-backend`)
- `class/Chat.php`: `getOrCreateConversation` (lazy per `order_item_id`), `createSystemMessage` (bilingual), `createMessage` (sender_type customer/vendor/system + content_ar + prompt_id), `flagMessage` (is_flagged/flag_type/flag_matches). Legacy only **flags-and-delivers**; we **block**.
- `class/ModerationService.php`: the EN/AR regex source (already ported).
- `chat/{send_message,get_conversation,get_messages,get_vendor_conversations}.php`: endpoint shapes to mirror for client compatibility.

---

## 6. Portal client wiring (for 3d / Phase 7)
- **ENDPOINT_ROUTING** strangler table in `packages/api-client/src/feature-flags.ts`: each key has `target:'new'`(→api-v3.3bayti.ae) or `'old'`(→DEAD legacy), `oldPath`/`newPath`/`shape`. Add a key → register → run `node apps/portal/tools/gen-route-keys.mjs` (currently **204 keys**) → typed in `apps/portal/src/app/services/v3-route-keys.generated.ts`.
- **PortalCrudAdapter** (`apps/portal/src/app/services/portal-crud-adapter.ts`): all verbs go through a private `send(routeKey, makeReq, authToken?)` that on a 401 (non-`/auth/` route) refreshes the token once (shared in-flight via `shareReplay(1)`) and retries, logging out only if refresh fails. v3 auth endpoints return FLAT bodies (`res.access_token`), not enveloped.
- Vendor self-resolution: `VendorRepository::findByOwnerUser($user)` → `Vendor[]`; `findIdsByOwnerUser($user)` → `int[]`.

---

## 7. Outstanding SERVER actions (Sodiq must run; Claude can't reach prod)
1. **Deploy the API with the cache-clear** (the §2 mandatory step) — applies migrations through `…000005` (chat tables) **and** clears the stale metadata cache (this is also the fix for the previously-diagnosed `/v3/vendor/notifications` 500 caused by a stale `NotificationLog.isRead` mapping). Pending server migrations may include `…000001` (collections legacy id), `…000002` (notification is_read/read_at — columns already confirmed present), `…000003/…000004` (compliance), `…000005` (chat).
2. **Deploy the portal** (build + wrangler) to ship two earlier fixes: `/account` now renders the insightful dashboard (`92a9617`) and the refresh-token retry flow (`de98fe0`).
3. Ensure `apps/api/var/uploads/compliance/` is persistent + writable by `www` (KYC private storage from a prior session).

---

## 8. First moves in the new chat
1. `cd /home/claude/work/3bayti/apps/api && git pull --no-edit origin main` (you should see Phase 2 `c667904` present).
2. `vendor/bin/phpunit 2>&1 | tail -3` → confirm **1574** green.
3. Re-read the Phase 1/2 source in `apps/api/src/Domain/Chat/` if needed.
4. Present the Phase 3 sub-plan (3a–3d) to Sodiq, get approval, then build 3a → commit → status snapshot → continue.
