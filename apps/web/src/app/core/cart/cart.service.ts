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

  constructor() {
    if (isPlatformBrowser(this.platformId)) {
      /* On browser construction, seed from localStorage if we're
         not yet authenticated. The merge effect below handles the
         authenticated-on-load case. */
      if (!this.auth.isAuthenticated()) {
        this._cart.set(this.loadGuestCart());
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
    return synthesized;
  }

  /**
   * Refresh the cart from the server. No-op for guests (their cart
   * is already in-signal).
   */
  async refresh(): Promise<Cart> {
    if (!this.auth.isAuthenticated()) {
      const guest = this.loadGuestCart();
      this._cart.set(guest);
      return guest;
    }
    return this.runWithLoading(async () => {
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
  private async handleSignIn(): Promise<void> {
    const guest = this.loadGuestCartRaw();
    if (guest.items.length > 0) {
      try {
        await this.mergeGuestCart(guest);
      } catch (err) {
        /* If merge fails (network, validation, etc.) fall through
           to refresh — at worst the user sees their server cart and
           we keep the localStorage payload around for the next
           attempt. */
        if (typeof console !== 'undefined') {
          console.warn('[CartService] merge failed; falling back to refresh', err);
        }
      }
    }
    try {
      await this.refresh();
    } catch (err) {
      if (typeof console !== 'undefined') {
        console.warn('[CartService] post-merge refresh failed', err);
      }
    }
  }

  /**
   * POST /v3/cart/merge with the guest cart items. On success clears
   * localStorage so a subsequent logout doesn't replay the merge.
   */
  private async mergeGuestCart(guest: GuestCart): Promise<void> {
    const payload: MergeAnonCartInput = {
      items: guest.items,
    };
    await this.runWithLoading(async () => {
      const cart = await firstValueFrom(
        this.http.post<Cart>(`${V3_BASE}/v3/cart/merge`, payload),
      );
      this._cart.set(cart);
      this.clearGuestCart();
    });
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
   * Build a Cart shape from the guest store. The id, name, image,
   * and prices are synthesized client-side; this is informational
   * only — the merge call sends just the AddCartItemInput shape and
   * the API re-resolves products at merge time.
   */
  private synthesizeCartFromGuest(guest: GuestCart): Cart {
    const items: CartItem[] = guest.items.map((input, idx) => ({
      id: idx + 1, /* synthetic; used as a stable handle for updateQty / remove */
      product_id: input.product_id,
      product_name: '', /* unknown until PDP context provides it; future enhancement: cache in localStorage too */
      product_image: '',
      quantity: input.quantity,
      unit_price: '0.00',
      line_subtotal: '0.00',
      size: input.size ?? null,
      color: input.color ?? null,
      is_custom: input.is_custom ?? false,
      measurement: input.measurement ?? null,
      extra_measurement: input.extra_measurement ?? null,
      note: input.note ?? null,
    }));
    const item_count = items.reduce((acc, it) => acc + it.quantity, 0);
    return {
      id: 0,
      status: 'active',
      currency: guest.currency,
      cart_code: 'PND',
      subtotal: '0.00',
      item_count,
      items,
    };
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
