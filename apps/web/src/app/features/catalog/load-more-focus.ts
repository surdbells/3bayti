import { afterNextRender, type Injector } from '@angular/core';

/** What handOffLoadMoreFocus() needs to know about one "load more" click. */
export interface LoadMoreFocusHandOff {
  /** Injector of the listing component (afterNextRender needs one outside an injection context). */
  injector: Injector;
  /** The "Load more" button as it was when the load started. */
  button: HTMLElement | null | undefined;
  /** Whether that button held keyboard focus when the load started. */
  focusedAtStart: boolean;
  /** Index of the first product card the load appended (= cards shown before it). */
  firstNewIndex: number;
  /** The grid's product-card elements, in order, once the new page has rendered. */
  cards: () => ArrayLike<Element> | null | undefined;
  /** Stable focus target (tabindex="-1") when there is no new card link to focus. */
  fallback: () => HTMLElement | null | undefined;
}

/**
 * Keyboard-focus hand-off for a "Load more" button that is about to vanish.
 *
 * The listing pages (category, collection, curated listings) render "Load
 * more" (and its in-place retry) only while more pages exist:
 * `@if (hasMore())`. When a click fetches the LAST page the focused button
 * is removed from the DOM, and focus would drop to <body>, sending a
 * keyboard / screen-reader user back to the top of the document.
 *
 * Call this once such a load has SUCCEEDED and no further page exists.
 * After the next render it moves focus to the first product card that load
 * appended (its product link), so the shopper carries on exactly where the
 * new products start; with no such link (an empty last page, a card without
 * a slug) it falls back to the stable grid / results region.
 *
 * It only acts when the shopper was on the button: focus was on it when the
 * load started and is still there (or was dropped to <body> by its
 * removal). A pointer click that never focused the button (Safari), or focus
 * that moved elsewhere meanwhile, is left alone, so focus is never stolen.
 */
export function handOffLoadMoreFocus(opts: LoadMoreFocusHandOff): void {
  const button = opts.button;
  if (!button || !opts.focusedAtStart || !focusIsOnOrDroppedFrom(button)) return;
  afterNextRender(
    {
      write: () => {
        // Still rendered (the listing turned out to have more after all), or
        // the shopper has moved focus somewhere else meanwhile: leave it.
        if (button.isConnected || !focusIsOnOrDroppedFrom(button)) return;
        const card = opts.cards()?.[opts.firstNewIndex];
        const link = card?.querySelector<HTMLElement>('a[href]');
        if (link) {
          link.focus();
        } else {
          opts.fallback()?.focus({ preventScroll: true });
        }
      },
    },
    { injector: opts.injector },
  );
}

/** Focus is on `button`, or fell back to <body> because the button left the DOM. */
function focusIsOnOrDroppedFrom(button: HTMLElement): boolean {
  const doc = button.ownerDocument;
  const active = doc.activeElement;
  if (active === button) return true;
  return !button.isConnected && (active === null || active === doc.body);
}
