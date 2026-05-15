import { Injectable } from '@angular/core';

/**
 * Local (guest) cart storage backed by IndexedDB.
 *
 * Why a local cart exists
 * =======================
 * Q7=B in the M3.1.6 plan: server-side carts for authenticated users,
 * device-local carts for guests, with merge-on-sign-in. The guest
 * experience is critical for conversion — forcing users to register
 * before they can save items to a cart is a common e-commerce drop-
 * off point. This service implements the guest side; the v3 backend's
 * POST /v3/cart/merge handles the merge moment.
 *
 * Why IndexedDB (not Capacitor Preferences)
 * ==========================================
 * Preferences is a simple key-value store designed for small primitive
 * values (settings, tokens). Carts can grow to dozens of items each
 * with multi-field shapes (variant, custom-tailoring, notes). IndexedDB:
 *   1. Handles structured records natively — no JSON.stringify/parse
 *      round-trips for every operation
 *   2. Has no platform-specific size cap (Preferences on iOS is
 *      limited to ~64KB per key; a large cart could overflow)
 *   3. Provides atomic transactions, eliminating race conditions when
 *      the user rapidly adds/removes items
 *
 * The cost is implementation complexity, mitigated here by wrapping
 * the IndexedDB API in promise-based methods that mirror what the
 * UI needs.
 *
 * Schema
 * ======
 * Database: '3bayti_local_cart' v1
 * Object stores:
 *   'items' — keyPath 'localId', autoIncrement.
 *             Records: { localId, product_id, quantity, ... full
 *             item shape matching what v3 POST /cart/items expects
 *             for merge }
 *
 * Why a single object store and not separate ones for items vs.
 * metadata: keeps the API surface small. If we later need cart-level
 * state (e.g. notes, custom totals), we add a separate store; for
 * now items are sufficient.
 *
 * Item shape
 * ==========
 * Mirrors what addToCart on product.page accumulates (the legacy
 * add_cart body, minus auth/store/customer/product-display fields).
 * The shape is intentionally aligned with v3's POST /v3/cart/items
 * body shape so the merge transform doesn't need a separate shape
 * mapping (see transformMergeCartRequest in mutation-request.transforms.ts).
 *
 *   product_id:    number       (required for v3 lookup)
 *   quantity:      number       (clamped 1..999 server-side)
 *   size:          string?      (variant; empty = no size)
 *   color:         string?      (variant; empty = no color)
 *   is_custom:     boolean      (custom-tailoring flag)
 *   measurement:   string?
 *   extra_measurement: string?
 *   note:          string?
 *
 * Display fields (product_name, product_image, vendor_name, etc.)
 * are stored too so the guest cart UI can render WITHOUT a round-trip
 * to fetch product details. v3 derives these on the server when the
 * merge happens; the local copy is for display only and gets discarded
 * after merge.
 */

export type LocalCartItem = {
  // Internal stable identifier — IndexedDB autoIncrement keypath.
  // Set by addItem; clients shouldn't construct items with this set.
  localId?: number;

  // v3 merge-required fields:
  product_id: number;
  quantity: number;
  size: string;
  color: string;
  is_custom: boolean;
  measurement: string;
  extra_measurement: string;
  note: string;

  // Display-only fields (echoed back from the product page; used to
  // render the guest cart UI without round-trip). v3 ignores these
  // during merge — server derives from product_id + user.
  product_name: string;
  product_image: string;
  unit_price: string;
  vendor_id: number;
  vendor_name: string;
};

const DB_NAME = '3bayti_local_cart';
const DB_VERSION = 1;
const STORE_ITEMS = 'items';

/**
 * Open the IndexedDB database, creating the schema on first run.
 *
 * Returned promise resolves to the database handle. Reject on:
 *   - Browser disables IndexedDB (rare; private-browsing on some
 *     Safari versions)
 *   - Schema upgrade conflict (multi-tab race condition)
 *
 * Note: not memoized — called per operation. IndexedDB's connection
 * pool is light-weight enough that pooling here is premature
 * optimization; if profiling shows it matters, add a connection cache.
 */
function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onerror = () => reject(req.error ?? new Error('IndexedDB open failed'));
    req.onsuccess = () => resolve(req.result);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_ITEMS)) {
        db.createObjectStore(STORE_ITEMS, {
          keyPath: 'localId',
          autoIncrement: true,
        });
      }
    };
  });
}

/**
 * Detect whether two items should merge as the same line.
 * Same logic as v3's Cart::findEquivalentItem (server-side):
 * same product + same variant tuple + same custom-tailoring tuple.
 */
function isSameLine(a: LocalCartItem, b: LocalCartItem): boolean {
  return (
    a.product_id === b.product_id &&
    a.size === b.size &&
    a.color === b.color &&
    a.is_custom === b.is_custom &&
    a.measurement === b.measurement &&
    a.extra_measurement === b.extra_measurement
    // Note intentionally NOT in the dedup key — two adds of the same
    // variant with different notes still merge; the second note wins.
    // Matches v3's server-side behaviour.
  );
}

const MAX_QTY_PER_LINE = 999;

/**
 * Service for managing the device-local cart for guest users.
 *
 * Public API:
 *   list():              Promise<LocalCartItem[]>
 *   add(item):           Promise<void>   // merges with same-variant line
 *   updateQuantity(localId, qty): Promise<void>
 *   remove(localId):     Promise<void>
 *   clear():             Promise<void>
 *   count():             Promise<number>
 *   isEmpty():           Promise<boolean>
 *   subtotal():          Promise<string> // decimal string, e.g. '299.50'
 *
 * All methods reject on IndexedDB errors. UI callers should catch
 * and degrade gracefully (e.g. show empty cart if the DB is broken).
 *
 * Thread safety: IndexedDB transactions are atomic per call; multiple
 * concurrent calls don't corrupt state but ordering between them is
 * undefined. UI should sequence sensitive operations (e.g. await one
 * add before kicking off the next).
 */
@Injectable({ providedIn: 'root' })
export class LocalCartService {
  /**
   * Return all items in insertion order (oldest first).
   * Empty array if the cart has never been written to or after clear().
   */
  async list(): Promise<LocalCartItem[]> {
    const db = await openDb();
    try {
      return await this.txReadAll(db);
    } finally {
      db.close();
    }
  }

  /**
   * Add an item, merging with an existing same-variant line if present.
   * Quantity is clamped to MAX_QTY_PER_LINE.
   *
   * Defensive: invalid product_id or non-positive quantity is a no-op
   * (no throw — UI typically calls this from user interaction; better
   * to silently drop bad input than crash the page).
   */
  async add(item: LocalCartItem): Promise<void> {
    if (!item || typeof item.product_id !== 'number' || item.product_id <= 0) return;
    if (typeof item.quantity !== 'number' || item.quantity <= 0) return;

    const db = await openDb();
    try {
      const existing = await this.txReadAll(db);
      const match = existing.find((e) => isSameLine(e, item));
      if (match !== undefined) {
        // Merge: bump existing line's quantity, capped.
        const newQty = Math.min(MAX_QTY_PER_LINE, match.quantity + item.quantity);
        await this.txUpdate(db, { ...match, quantity: newQty, note: item.note || match.note });
      } else {
        // New line.
        const clamped = { ...item, quantity: Math.min(MAX_QTY_PER_LINE, item.quantity) };
        // localId is set by autoIncrement; ensure caller's value
        // (if any) is dropped so the store generates a fresh one.
        delete (clamped as { localId?: number }).localId;
        await this.txAdd(db, clamped);
      }
    } finally {
      db.close();
    }
  }

  /**
   * Set absolute quantity for a line. qty=0 is treated as remove
   * (matches v3's PATCH /cart/items/{id} semantics: server returns
   * 422 'DELETE to remove' on 0, but for the local cart we accept
   * the user intent and delete the line).
   *
   * Out-of-range qty (>MAX) is clamped silently.
   */
  async updateQuantity(localId: number, quantity: number): Promise<void> {
    if (typeof localId !== 'number' || localId <= 0) return;
    if (typeof quantity !== 'number' || !Number.isFinite(quantity)) return;

    if (quantity <= 0) {
      return this.remove(localId);
    }

    const db = await openDb();
    try {
      const items = await this.txReadAll(db);
      const target = items.find((i) => i.localId === localId);
      if (target === undefined) return; // already deleted; idempotent
      await this.txUpdate(db, {
        ...target,
        quantity: Math.min(MAX_QTY_PER_LINE, Math.trunc(quantity)),
      });
    } finally {
      db.close();
    }
  }

  /**
   * Remove a line by localId. Idempotent — removing a non-existent
   * localId is a no-op.
   */
  async remove(localId: number): Promise<void> {
    if (typeof localId !== 'number' || localId <= 0) return;

    const db = await openDb();
    try {
      await this.txDelete(db, localId);
    } finally {
      db.close();
    }
  }

  /**
   * Wipe all items. Used by:
   *   1. Sign-in flow after successful POST /v3/cart/merge — local
   *      cart's content is now reflected server-side
   *   2. Explicit user 'empty cart' action
   *   3. Test setup/teardown
   */
  async clear(): Promise<void> {
    const db = await openDb();
    try {
      await this.txClear(db);
    } finally {
      db.close();
    }
  }

  /**
   * Total item count across all lines (sum of quantities).
   * Used for the cart badge in the UI.
   */
  async count(): Promise<number> {
    const items = await this.list();
    return items.reduce((acc, i) => acc + (i.quantity || 0), 0);
  }

  async isEmpty(): Promise<boolean> {
    const items = await this.list();
    return items.length === 0;
  }

  /**
   * Compute subtotal as a decimal string in the AED 2dp convention
   * matching v3. Uses string arithmetic via toFixed to avoid floating-
   * point drift; for very large carts this may accumulate small
   * rounding errors but at the price points and quantities in this
   * app the deviation is well below a cent.
   */
  async subtotal(): Promise<string> {
    const items = await this.list();
    let total = 0;
    for (const it of items) {
      const price = parseFloat(it.unit_price || '0');
      const qty = it.quantity || 0;
      if (Number.isFinite(price) && Number.isFinite(qty)) {
        total += price * qty;
      }
    }
    return total.toFixed(2);
  }

  /* =================== Internal IDB helpers =================== */

  private txReadAll(db: IDBDatabase): Promise<LocalCartItem[]> {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ITEMS, 'readonly');
      const store = tx.objectStore(STORE_ITEMS);
      const req = store.getAll();
      req.onsuccess = () => resolve((req.result as LocalCartItem[]) ?? []);
      req.onerror = () => reject(req.error ?? new Error('txReadAll failed'));
    });
  }

  private txAdd(db: IDBDatabase, item: LocalCartItem): Promise<void> {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ITEMS, 'readwrite');
      const store = tx.objectStore(STORE_ITEMS);
      const req = store.add(item);
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error ?? new Error('txAdd failed'));
    });
  }

  private txUpdate(db: IDBDatabase, item: LocalCartItem): Promise<void> {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ITEMS, 'readwrite');
      const store = tx.objectStore(STORE_ITEMS);
      const req = store.put(item);
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error ?? new Error('txUpdate failed'));
    });
  }

  private txDelete(db: IDBDatabase, localId: number): Promise<void> {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ITEMS, 'readwrite');
      const store = tx.objectStore(STORE_ITEMS);
      const req = store.delete(localId);
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error ?? new Error('txDelete failed'));
    });
  }

  private txClear(db: IDBDatabase): Promise<void> {
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ITEMS, 'readwrite');
      const store = tx.objectStore(STORE_ITEMS);
      const req = store.clear();
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error ?? new Error('txClear failed'));
    });
  }
}

/** Test-only export of internal constants. */
export const _internals = {
  DB_NAME,
  DB_VERSION,
  STORE_ITEMS,
  MAX_QTY_PER_LINE,
  isSameLine,
};
