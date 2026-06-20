# Mobile adapter removal — remaining steps

As of June 2026 the mobile app is fully migrated to **direct v3** calls
(`get_v3`/`post_v3`/`patch_v3`/`put_v3`/`delete_v3` → `callV3Direct`). The legacy
**strangler machinery** can't be deleted yet because of **one** remaining caller.
This doc is the plan to finish.

## The one blocker: `readStoreMeasurement` (PDP store size guide)

`apps/mobile/src/app/customer/product/product.page.ts:650` still calls
`networkAdapter.post_request(this.store_m, GlobalComponent.readStoreMeasurement)`
to fetch a store's size chart by store id.

- There is **no customer-facing v3 endpoint** for it. `GET /v3/vendor/measurements`
  (`VendorSizeChartController`) is JWT-vendor-self-scoped (403s a shopper).
- It's **already broken under v3**: `ProductSerializer::detailShape` emits
  `vendor => { slug, name }` with **no numeric id**, so the PDP's `this.single.store`
  stays `0`, `store_m.store = 0`, and the call returns empty. The size-chart UI on the
  PDP shows nothing today regardless of the adapter.

### Step 1 — Backend (API, needs redeploy of api-v3.3bayti.ae)

1. **Expose the vendor id on the PDP.** In `apps/api/.../Serializers/ProductSerializer.php`
   `detailShape`, add the vendor's id to the `vendor` object (mirror
   `VendorSerializer::getLegacyVendorId(...)` used in `featuredShape` for the legacy id
   the mobile store routes use):
   ```php
   'vendor' => [
       'slug' => $p->getVendor()->getSlug(),
       'name' => $p->getVendor()->getName(),
       'id'        => $p->getVendor()->getId(),
       'legacy_id' => $this->getLegacyVendorId($p->getVendor()), // for legacy store routes
   ],
   ```
2. **Add a public store-size-chart endpoint.** New route + controller, e.g.
   `GET /v3/vendors/{id}/size-chart` (or `/v3/vendors/by-legacy-id/{id}/size-chart`,
   matching how the PDP/store pages key vendors). It returns the same rows
   `VendorSizeChartController::list` returns, but resolves the vendor from the **path id**
   (not JWT) and is **public** (optional auth). Reuse that controller's serializer/shape so
   the mobile mapping is unchanged.
3. **Register the route-key** in `packages/api-client/src/feature-flags.ts`:
   ```ts
   'GET /vendors/:vendorId/size-chart': {
     target: 'new', oldPath: '', newPath: '/v3/vendors/:vendorId/size-chart', shape: 'v3-envelope',
   },
   ```

### Step 2 — Mobile (after Step 1 deploys)

1. In `transforms/catalog-response.transforms.ts` `transformProductDetailResponse`, map the
   v3 `vendor.legacy_id` (or `vendor.id`) onto `store` so `this.single.store` is the real id
   (currently hard-`0`).
2. In `product.page.ts`, replace the `readStoreMeasurement` `post_request` with
   `get_v3('GET /vendors/:vendorId/size-chart', { pathParams: { vendorId: String(this.single.store) } })`
   (public read; add `authToken` only if the endpoint requires it). Map `response.data` to
   `store_measurement[]` as the current handler does. Guard against `store === 0`.

## Step 3 — Delete the legacy strangler machinery (after Step 2)

Once `readStoreMeasurement` is on v3, **no caller** uses the adapter's legacy path. Delete:

- In `apps/mobile/src/app/core/http/mobile-network-adapter.ts`: `post_request`,
  `get_request`, `route()`, `tryConvertPostToGet`, and the legacy-routed `callV3`/
  `resolveRouteKey` path. **Keep** `callV3Direct`, `withRefreshRetry`,
  `executeHttpRequest`, `envelopeAndTransform`, and the `*_v3` methods — that's the v3 client.
- The **request** transforms (legacy-path only): `catalog-request.transforms.ts`,
  the `AUTHED_GET_REQUEST_TRANSFORMS` in `order-request.transforms.ts`,
  `mutation-request.transforms.ts` (+ their `.spec.ts`). **Keep** the **response**
  transforms (`catalog-response`, `mutation-response`) — `callV3Direct` still applies them.
- Legacy URL constants in `global-component.ts` that were only used by `post_request`
  (readProfile/updateProfile/readBilling/updateBilling/customerCart/addToCart/IncreaseItem/
  etc.). **Keep** `baseURL` only if anything still needs it (e.g. TopEx city/area via
  `NetworkService.get_request` — those are an external API and stay).
- `ENDPOINT_ROUTING` itself **stays** (used by `resolveUrl`/`resolveConfig` for the v3
  `newPath`s); the `oldPath`/`oldPathAliases`/`target` fields become vestigial and can be
  trimmed in a follow-up.

### Note — what is NOT the adapter
The DIRECT-v3 layer (`*_v3` → `callV3Direct` → `resolveConfig`/`envelopeAndTransform` +
the **response** transforms + `ENDPOINT_ROUTING.newPath`) is now the app's primary API
client and **stays**. "Remove the adapter" means only the legacy strangler parts above.
