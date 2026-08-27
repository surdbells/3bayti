import { Injectable, signal } from '@angular/core';

/**
 * CartDrawerService, global controller for the slide-out cart drawer.
 *
 * Responsibilities
 * ----------------
 *   - Track whether the drawer is open
 *   - Track which cart line was just added (so the drawer can highlight it)
 *   - Provide open(), close(), toggle(), openWithHighlight() entry points
 *
 * Why a separate service rather than a signal on CartService
 * ----------------------------------------------------------
 * CartService is consumed by many features (PDP, /cart page, checkout,
 * /account/orders). Co-mingling UI-presentation state with cart-data
 * state would force unrelated consumers to subscribe to drawer signals
 * they don't care about. A separate service keeps each concern testable
 * in isolation.
 */
@Injectable({ providedIn: 'root' })
export class CartDrawerService {
  private readonly _isOpen = signal<boolean>(false);
  private readonly _highlightedItemId = signal<number | null>(null);

  /** Whether the drawer is currently visible. */
  readonly isOpen = this._isOpen.asReadonly();

  /** Item id to highlight (set by openWithHighlight, cleared on close). */
  readonly highlightedItemId = this._highlightedItemId.asReadonly();

  /** Open the drawer without highlighting any item. */
  open(): void {
    this._isOpen.set(true);
    this._highlightedItemId.set(null);
  }

  /** Open the drawer and highlight a specific item (e.g. the one just added). */
  openWithHighlight(itemId: number): void {
    this._isOpen.set(true);
    this._highlightedItemId.set(itemId);
  }

  /** Close the drawer; clears the highlight too. */
  close(): void {
    this._isOpen.set(false);
    this._highlightedItemId.set(null);
  }

  /** Flip the open state. */
  toggle(): void {
    if (this._isOpen()) {
      this.close();
    } else {
      this.open();
    }
  }
}
