import {
  Injectable,
  Signal,
  signal,
  computed,
  effect,
  inject,
  PLATFORM_ID,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../auth/auth.service';
import type {
  Cart,
  CartItem,
  AddCartItemInput,
  UpdateCartItemInput,
  MergeAnonCartInput,
  GuestCart,
  CartQuoteResponse,
} from './cart.types';

/**
 * CartService — single source of truth for the customer's cart.
 *
 * Two backing stores, chosen by auth state:
 *
 *   Guest (not authenticated)
 *   -------------------------
 *   - Cart lives in localStorage under GUEST_CART_KEY.
 *   - Reads + writes are synchronous on the browser.
 *   - On SSR the guest cart is always considered empty (we can't
 *     read localStorage on the server).
 *
 *   Authenticated
 *   -------------
 *   - Cart lives on the API. CartService exposes the same surface
 *     (addItem, updateQty, removeItem) but proxies to /v3/cart.
 *   - On every method call we refresh the `cart` signal with the
 *     server's response so the UI stays in lock-step.
 *
 * Merge handshake
 * ---------------
 * When AuthService.isAuthenticated() flips false → true, a
 * one-time effect runs:
 *   1. If the guest cart has items → POST /v3/cart/merge with them
 *   2. Otherwise → GET /v3/cart to pick up whatever the user had
 *      on another device
 *   3. Clear localStorage afterwards so a future sign-out doesn't
 *      replay a stale guest cart
 *
 * On the reverse transition (true → false / logout), we drop the
 * cart signal back to a clean empty guest cart. We do NOT seed
 * localStorage from the server cart at logout — that would be
 * surprising behaviour (a signed-out user finding items they only
 * added while signed in).
 *
 * SSR considerations
 * ------------------
 * Constructed safely on the server (no localStorage access during
 * construction). The merge effect is browser-only via PLATFORM_ID
 * check. SSR renders an empty cart; hydration on the browser
 * resolves the real state.
 *
 * Why a single service rather than separate Guest/Auth services
 * -------------------------------------------------------------
 * The UI never wants to know whether the cart is guest or auth-backed.
 * Components consume `cart()`, `itemCount()`, etc. and let the service
 * route to the right store internally. One service also means the
 * merge handshake has direct access to both stores.
 */

const GUEST_CART_KEY = 'bayti_guest_cart_v1';
const V3_BASE = 'https://api-v3.3bayti.ae';

const EMPTY_CART: Cart = {
  id: 0,
  status: 'active',
  currency: 'AED',
  cart_code: 'PND',
  subtotal: '0.00',
  item_count: 0,
  items: [],
};

/**
 * Response shape of POST /v3/cart/resolve — the public endpoint that
 * prices a guest's device-local cart server-side. `cart` is the standard
 * Cart shape (same as GET /v3/cart) with current names/images/prices;
 * `removed` lists product_ids that no longer resolve (deleted/inactive)
 * so the client can prune them from localStorage.
 */
interface GuestCartResolveResponse {
  cart: Cart;
  removed: number[];
}

/** Cached server-resolved display fields for a guest line, keyed by
 *  variantKey(). Lets the synchronous synthesize show real names/prices
 *  for already-seen lines instantly (no flash) before the next resolve. */
interface ResolvedLineInfo {
  name: string;
  image: string;
  unitPrice: string;
}

@Injectable({ providedIn: 'root' })
export class CartService {
  private readonly _cart = signal<Cart>(EMPTY_CART);
  private readonly _isLoading = signal<boolean>(false);

  /** Current cart (read-only signal). Always defined; an empty cart
   *  is the zero-value rather than null so consumers don't need null
   *  guards. */
  readonly cart: Signal<Cart> = this._cart.asReadonly();

  /** True while ANY mutation or refresh is in flight. The drawer
   *  uses this for a subtle loading indicator. */
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();

  /** Total item count across all lines (sum of quantities), suitable
   *  for the header badge. Reads as `0` for empty carts; consumers
   *  decide whether to hide the badge on zero. */
  readonly itemCount = computed(() => this._cart().item_count);

  /** Sum of line subtotals (excluding shipping/tax). String decimal. */
  readonly subtotal = computed(() => this._cart().subtotal);

  /** Currency code of the current cart (for symbol/format lookup). */
  readonly currency = computed(() => this._cart().currency);

  private readonly platformId = inject(PLATFORM_ID);
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  /* Tracks whether we've already executed the merge handshake for
     the current authenticated session. Reset on auth false. */
  private mergedThisSession = false;

  /* Monotonic token for guest-cart resolves. Each refreshGuestCart()
     call takes the next value; only the latest may publish, so an older
     in-flight resolve can't clobber a newer cart state (e.g. two quick
     "add to bag" taps). */
  private resolveSeq = 0;

  /* Last server-resolved display fields per guest line (browser-session
     memory, keyed by variantKey). Used by synthesizeCartFromGuest so a
     re-render after a quantity change shows the known name/price/image
     immediately instead of flashing a blank line. */
  private readonly resolvedLineCache = new Map<string, ResolvedLineInfo>();

  constructor() {
    if (isPlatformBrowser(this.platformId)) {
      /* On browser construction, seed from localStorage if we're
         not yet authenticated. The merge effect below handles the
         authenticated-on-load case. */
      if (!this.auth.isAuthenticated()) {
        this._cart.set(this.loadGuestCart());
        /* Price the local cart against the server so names/images/prices
           are authoritative on first paint. Fire-and-forget; the
           synthesized cart above already gives an instant item count. */
        void this.refreshGuestCart();
      }

      /* React to auth transitions. */
      effect(() => {
        const authed = this.auth.isAuthenticated();
        if (authed && !this.mergedThisSession) {
          this.mergedThisSession = true;
          void this.handleSignIn();
        } else if (!authed) {
          this.mergedThisSession = false;
          /* On logout: drop to empty guest cart. Do NOT seed
             localStorage from the server cart. */
          this._cart.set(this.loadGuestCart());
          void this.refreshGuestCart();
        }
      });
    }
  }

  /* =================================================================
     Public API
     ================================================================= */

  /**
   * Add an item to the cart.
   *
   * Authenticated → POST /v3/cart/items
   * Guest         → append to localStorage cart
   *
   * Returns the updated cart. Throws on API failure; the caller
   * (typically the PDP "Add to bag" button + drawer) decides how
   * to surface the error.
   */
  async addItem(input: AddCartItemInput): Promise<Cart> {
    if (this.auth.isAuthenticated()) {
      return this.runWithLoading(async () => {
        const cart = await firstValueFrom(
          this.http.post<Cart>(`${V3_BASE}/v3/cart/items`, input),
        );
        this._cart.set(cart);
        return cart;
      });
    }
    /* Guest path. */
    const guest = this.loadGuestCartRaw();
    const existingIdx = this.findMatchingItemIdx(guest.items, input);
    if (existingIdx >= 0) {
      guest.items[existingIdx].quantity += input.quantity;
    } else {
      guest.items.push({ ...input });
    }
    this.saveGuestCart(guest);
    const synthesized = this.synthesizeCartFromGuest(guest);
    this._cart.set(synthesized);
    /* Re-price against the server (handles a price change since the
       item was added, and fills in name/image for a brand-new line). */
    void this.refreshGuestCart();
    return synthesized;
  }

  /**
   * Update the quantity of a cart line. For guests this requires the
   * line's local index (we identify by id only when authenticated,
   * since guest items don't have a server id). For guests we accept
   * a synthetic numeric id assigned at synthesize-time (the array
   * index + 1).
   */
  async updateQty(itemId: number, quantity: number): Promise<Cart> {
    if (quantity <= 0) {
      return this.removeItem(itemId);
    }
    if (this.auth.isAuthenticated()) {
      return this.runWithLoading(async () => {
        const update: UpdateCartItemInput = { quantity };
        const cart = await firstValueFrom(
          this.http.patch<Cart>(`${V3_BASE}/v3/cart/items/${itemId}`, update),
        );
        this._cart.set(cart);
        return cart;
      });
    }
    const guest = this.loadGuestCartRaw();
    const idx = itemId - 1; /* synthetic id = idx + 1 */
    if (idx < 0 || idx >= guest.items.length) {
      throw new Error(`CartService.updateQty: invalid item id ${itemId}`);
    }
    guest.items[idx].quantity = quantity;
    this.saveGuestCart(guest);
    const synthesized = this.synthesizeCartFromGuest(guest);
    this._cart.set(synthesized);
    void this.refreshGuestCart();
    return synthesized;
  }

  /**
   * Remove a line from the cart.
   */
  async removeItem(itemId: number): Promise<Cart> {
    if (this.auth.isAuthenticated()) {
      return this.runWithLoading(async () => {
        await firstValueFrom(
          this.http.delete<unknown>(`${V3_BASE}/v3/cart/items/${itemId}`),
        );
        /* DELETE returns 204; refresh to pick up the server's
           recomputed totals. */
        return this.refresh();
      });
    }
    const guest = this.loadGuestCartRaw();
    const idx = itemId - 1;
    if (idx < 0 || idx >= guest.items.length) {
      /* Idempotent for unknown ids. */
      return this._cart();
    }
    guest.items.splice(idx, 1);
    this.saveGuestCart(guest);
    const synthesized = this.synthesizeCartFromGuest(guest);
    this._cart.set(synthesized);
    void this.refreshGuestCart();
    return synthesized;
  }

  /**
   * Refresh the cart from the server. No-op for guests (their cart
   * is already in-signal).
   */
  async refresh(): Promise<Cart> {
    if (!this.auth.isAuthenticated()) {
      /* Instant synthesized cart (cached prices for known lines), then
         await the server resolve so the returned cart carries
         authoritative prices — the cart page calls refresh() on init. */
      this._cart.set(this.loadGuestCart());
      await this.refreshGuestCart();
      return this._cart();
    }
    return this.runWithLoading(async () => {
      /* Self-healing guest merge: if a device-local cart survived
         sign-in — first sign-in, or a prior merge that failed
         transiently — merge it before loading the server cart.
         Idempotent: on success localStorage is cleared so this no-ops
         thereafter; on failure localStorage is retained and the next
         refresh() retries, so guest items are never lost. Gated on a
         non-empty guest cart so the common (already-merged) path reaches
         the GET without an extra microtask hop. */
      if (this.loadGuestCartRaw().items.length > 0) {
        await this.mergeGuestCartIfPresent();
      }
      const cart = await firstValueFrom(
        this.http.get<Cart>(`${V3_BASE}/v3/cart`),
      );
      this._cart.set(cart);
      return cart;
    });
  }

  /**
   * Apply a promo code via /v3/cart/quote. Returns the full quote
   * (cart + breakdown + promo validity). The cart page surfaces
   * promo_valid + promo_message to the user.
   */
  async quoteWithPromo(promoCode: string | null): Promise<CartQuoteResponse> {
    if (!this.auth.isAuthenticated()) {
      throw new Error('CartService.quoteWithPromo: sign in required');
    }
    return this.runWithLoading(async () => {
      const response = await firstValueFrom(
        this.http.post<CartQuoteResponse>(`${V3_BASE}/v3/cart/quote`, {
          promo_code: promoCode,
        }),
      );
      /* The quote endpoint also returns the cart; sync our signal so
         any UI watching `cart` reflects line-level changes (e.g.
         products removed because the promo applied a free gift). */
      this._cart.set(response.cart);
      return response;
    });
  }

  /**
   * Internal: handle the first authenticated render after sign-in.
   * Decides between merge-then-refresh and plain refresh.
   */
  /**
   * Run once when the user becomes authenticated (see the constructor
   * effect). Delegates to refresh(), which merges any surviving guest
   * cart and then loads the server cart. Kept as a thin, named entry
   * point for the effect.
   */
  private async handleSignIn(): Promise<void> {
    try {
      await this.refresh();
    } catch (err) {
      this.warn('sign-in cart sync failed', err);
    }
  }

  /**
   * Merge the device-local guest cart into the authenticated user's
   * server cart via POST /v3/cart/merge, if a guest cart exists.
   *
   * Non-destructive + self-healing:
   *   - On success: localStorage is cleared so the merge runs exactly
   *     once (a later logout can't replay a stale payload).
   *   - On failure: the error is swallowed (NOT rethrown) and
   *     localStorage is KEPT, so the items aren't lost and the next
   *     refresh() retries the merge. We never let a failed merge leave
   *     the user staring at an empty server cart with their items
   *     orphaned in localStorage — that was the "cart disappeared on
   *     login" bug.
   *
   * Called from refresh()'s authenticated branch, so it must not open
   * its own loading scope — refresh() owns that.
   */
  private async mergeGuestCartIfPresent(): Promise<void> {
    const guest = this.loadGuestCartRaw();
    if (guest.items.length === 0) return;
    try {
      const payload: MergeAnonCartInput = { items: guest.items };
      const merged = await firstValueFrom(
        this.http.post<Cart>(`${V3_BASE}/v3/cart/merge`, payload),
      );
      this._cart.set(merged);
      this.clearGuestCart();
    } catch (err) {
      this.warn('guest cart merge failed; will retry on next cart load', err);
    }
  }

  /* =================================================================
     LocalStorage helpers
     ================================================================= */

  private loadGuestCart(): Cart {
    const raw = this.loadGuestCartRaw();
    return this.synthesizeCartFromGuest(raw);
  }

  private loadGuestCartRaw(): GuestCart {
    if (!isPlatformBrowser(this.platformId)) {
      return { items: [], currency: 'AED' };
    }
    try {
      const raw = localStorage.getItem(GUEST_CART_KEY);
      if (raw === null) return { items: [], currency: 'AED' };
      const parsed = JSON.parse(raw) as GuestCart;
      /* Defensive validation — if storage is corrupted, treat as empty. */
      if (typeof parsed !== 'object' || !Array.isArray(parsed.items)) {
        return { items: [], currency: 'AED' };
      }
      return parsed;
    } catch {
      return { items: [], currency: 'AED' };
    }
  }

  private saveGuestCart(guest: GuestCart): void {
    if (!isPlatformBrowser(this.platformId)) return;
    try {
      localStorage.setItem(GUEST_CART_KEY, JSON.stringify(guest));
    } catch {
      /* Quota exceeded — silently ignore. The user will see the
         current cart state but it won't survive a reload. */
    }
  }

  private clearGuestCart(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    try {
      localStorage.removeItem(GUEST_CART_KEY);
    } catch {
      /* ignore */
    }
  }

  /**
   * Build a Cart shape from the guest store for INSTANT render. Names,
   * images, and prices come from the in-memory resolvedLineCache (last
   * server resolve) when available, so a re-render after a quantity
   * change shows real data immediately; a brand-new line renders with
   * blank name + 0.00 until refreshGuestCart() resolves it a moment
   * later. The synthetic id (index + 1) is the stable handle the UI
   * passes back to updateQty / removeItem.
   *
   * This is a display convenience only — refreshGuestCart() is the
   * source of authoritative pricing, and the merge call on sign-in
   * sends just the AddCartItemInput shape for the API to re-resolve.
   */
  private synthesizeCartFromGuest(guest: GuestCart): Cart {
    const items: CartItem[] = guest.items.map((input, idx) => {
      const cached = this.resolvedLineCache.get(this.variantKey(input));
      const unit_price = cached?.unitPrice ?? '0.00';
      return {
        id: idx + 1,
        product_id: input.product_id,
        product_name: cached?.name ?? '',
        product_image: cached?.image ?? '',
        quantity: input.quantity,
        unit_price,
        line_subtotal: this.multiplyMoney(unit_price, input.quantity),
        size: input.size ?? null,
        color: input.color ?? null,
        is_custom: input.is_custom ?? false,
        measurement: input.measurement ?? null,
        extra_measurement: input.extra_measurement ?? null,
        note: input.note ?? null,
      };
    });
    const item_count = items.reduce((acc, it) => acc + it.quantity, 0);
    const subtotal = items.reduce(
      (acc, it) => this.addMoney(acc, it.line_subtotal),
      '0.00',
    );
    return {
      id: 0,
      status: 'active',
      currency: guest.currency ?? 'AED',
      cart_code: 'PND',
      subtotal,
      item_count,
      items,
    };
  }

  /**
   * Price the guest's device-local cart against the server via the
   * public POST /v3/cart/resolve endpoint and publish the authoritative
   * cart (current names, images, prices, and subtotal). Browser-only.
   *
   * Resilience:
   *   - A monotonic resolveSeq token ensures only the latest call may
   *     publish, so a slow earlier resolve can't overwrite a newer
   *     cart (e.g. two quick adds).
   *   - On failure (offline / 5xx) we keep the synthesized cart already
   *     in the signal — the drawer still opens with the local data.
   *
   * Reconciliation:
   *   - Lines are matched back to localStorage by variantKey so the
   *     synthetic (index + 1) ids stay consistent for updateQty /
   *     removeItem.
   *   - Products the server reports under `removed` (deleted/inactive)
   *     are pruned from localStorage and from the resolved cache.
   */
  private async refreshGuestCart(): Promise<void> {
    if (!isPlatformBrowser(this.platformId)) return;

    const guest = this.loadGuestCartRaw();
    if (guest.items.length === 0) {
      /* Nothing to price — the caller already set an empty synthesized
         cart; clear any stale cache so a future add starts fresh. */
      this.resolvedLineCache.clear();
      return;
    }

    const seq = ++this.resolveSeq;
    this._isLoading.set(true);
    try {
      const resp = await firstValueFrom(
        this.http.post<GuestCartResolveResponse>(
          `${V3_BASE}/v3/cart/resolve`,
          { items: guest.items },
        ),
      );

      /* A newer resolve started after us — discard this (now stale)
         result without touching the signal or localStorage. */
      if (seq !== this.resolveSeq) return;

      const removed = new Set<number>(resp.removed ?? []);

      /* Index resolved lines by variantKey and refresh the cache. */
      const resolvedByKey = new Map<string, CartItem>();
      for (const line of resp.cart.items) {
        const key = this.variantKey(line);
        resolvedByKey.set(key, line);
        this.resolvedLineCache.set(key, {
          name: line.product_name,
          image: line.product_image,
          unitPrice: line.unit_price,
        });
      }

      /* Rebuild from localStorage order so ids stay stable; drop any
         line the server couldn't resolve (removed or missing). */
      const keptInputs: AddCartItemInput[] = [];
      const items: CartItem[] = [];
      for (const input of guest.items) {
        const key = this.variantKey(input);
        const resolved = resolvedByKey.get(key);
        if (removed.has(input.product_id) || resolved === undefined) {
          this.resolvedLineCache.delete(key);
          continue;
        }
        items.push({
          id: keptInputs.length + 1,
          product_id: input.product_id,
          product_name: resolved.product_name,
          product_image: resolved.product_image,
          quantity: input.quantity,
          unit_price: resolved.unit_price,
          line_subtotal: resolved.line_subtotal,
          size: input.size ?? null,
          color: input.color ?? null,
          is_custom: input.is_custom ?? false,
          measurement: input.measurement ?? null,
          extra_measurement: input.extra_measurement ?? null,
          note: input.note ?? null,
        });
        keptInputs.push(input);
      }

      /* Persist the pruned cart if the server dropped anything. */
      if (keptInputs.length !== guest.items.length) {
        this.saveGuestCart({ ...guest, items: keptInputs });
      }

      const item_count = items.reduce((acc, it) => acc + it.quantity, 0);
      this._cart.set({
        id: 0,
        status: 'active',
        currency: resp.cart.currency ?? guest.currency ?? 'AED',
        cart_code: 'PND',
        subtotal: resp.cart.subtotal,
        item_count,
        items,
      });
    } catch (err) {
      /* Keep the synthesized cart already in the signal. */
      if (seq === this.resolveSeq) {
        this.warn('guest cart resolve failed; showing local cart', err);
      }
    } finally {
      /* Only the latest resolve owns the loading flag. */
      if (seq === this.resolveSeq) {
        this._isLoading.set(false);
      }
    }
  }

  /**
   * Stable dedupe/match key for a cart line across the guest store, the
   * resolve response, and the cache. Mirrors findMatchingItemIdx's axes
   * (product + size + color + is_custom).
   */
  private variantKey(it: {
    product_id: number;
    size?: string | null;
    color?: string | null;
    is_custom?: boolean;
  }): string {
    return [
      it.product_id,
      it.size ?? '',
      it.color ?? '',
      it.is_custom ? 1 : 0,
    ].join('|');
  }

  /* Decimal-string money helpers for the synthesized fallback (server
     responses already arrive pre-computed). Cents-integer math avoids
     binary-float drift for typical catalogue prices. */
  private multiplyMoney(price: string, qty: number): string {
    const cents = Math.round(parseFloat(price || '0') * 100) * qty;
    return (cents / 100).toFixed(2);
  }

  private addMoney(a: string, b: string): string {
    const cents =
      Math.round(parseFloat(a || '0') * 100) +
      Math.round(parseFloat(b || '0') * 100);
    return (cents / 100).toFixed(2);
  }

  /** Console warning, guarded for non-browser / test environments. */
  private warn(message: string, err: unknown): void {
    if (typeof console !== 'undefined') {
      console.warn(`[CartService] ${message}`, err);
    }
  }

  /**
   * Find an existing guest cart line that matches an incoming addItem
   * input on product + size + color + is_custom. Returns -1 if none.
   *
   * We dedupe on these axes so a user clicking "Add" twice on the same
   * size combines quantities rather than creating two lines.
   */
  private findMatchingItemIdx(items: AddCartItemInput[], input: AddCartItemInput): number {
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      if (
        it.product_id === input.product_id &&
        (it.size ?? null) === (input.size ?? null) &&
        (it.color ?? null) === (input.color ?? null) &&
        (it.is_custom ?? false) === (input.is_custom ?? false)
      ) {
        return i;
      }
    }
    return -1;
  }

  /* =================================================================
     Loading-state helper
     ================================================================= */

  private async runWithLoading<T>(fn: () => Promise<T>): Promise<T> {
    this._isLoading.set(true);
    try {
      return await fn();
    } finally {
      this._isLoading.set(false);
    }
  }
}
