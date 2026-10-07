import {
  AfterViewInit,
  Directive,
  ElementRef,
  OnDestroy,
  inject,
  signal,
} from '@angular/core';

/**
 * ScrollRail, a horizontally scrolling single-row list (CSS scroll-snap)
 * with keyboard + arrow-button navigation. Used by the PDP's "Complete the
 * look" strip and "You may also like" row.
 *
 * - Edge state (`atStart` / `atEnd` / `overflowing`) drives prev/next
 *   buttons (`(click)="rail.scroll(1)"`, `[disabled]="rail.atEnd()"`).
 * - ArrowLeft / ArrowRight move focus between items (`[data-rail-item]`),
 *   Home / End jump to the first / last item.
 * - RTL-aware: in a right-to-left context the visual "next" is to the
 *   left and `scrollLeft` runs negative (spec behaviour in every evergreen
 *   browser), so positions are compared as absolute values.
 * - Browser-only APIs (ResizeObserver, MutationObserver, matchMedia,
 *   getComputedStyle) are feature-guarded so the directive is inert in
 *   SSR / jsdom.
 *
 * Usage:
 *   <ul appScrollRail #rail="scrollRail">
 *     <li data-rail-item>…</li>
 *   </ul>
 *   <button (click)="rail.scroll(-1)" [disabled]="rail.atStart()">‹</button>
 */
@Directive({
  selector: '[appScrollRail]',
  exportAs: 'scrollRail',
  standalone: true,
  host: {
    '(scroll)': 'update()',
    '(keydown)': 'onKeydown($event)',
  },
})
export class ScrollRailDirective implements AfterViewInit, OnDestroy {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  /** Scrolled to (or sitting at) the inline-start edge. */
  readonly atStart = signal(true);
  /** Scrolled to the inline-end edge (true until measured). */
  readonly atEnd = signal(true);
  /** Content is wider than the rail, i.e. there is something to scroll. */
  readonly overflowing = signal(false);

  private resizeObserver?: ResizeObserver;
  private mutationObserver?: MutationObserver;

  ngAfterViewInit(): void {
    const el = this.host.nativeElement;
    this.update();
    if (typeof ResizeObserver !== 'undefined') {
      this.resizeObserver = new ResizeObserver(() => this.update());
      this.resizeObserver.observe(el);
    }
    /* Items can arrive after the rail renders (async data): recompute the
       edge state when the children change. */
    if (typeof MutationObserver !== 'undefined') {
      this.mutationObserver = new MutationObserver(() => this.update());
      this.mutationObserver.observe(el, { childList: true });
    }
  }

  ngOnDestroy(): void {
    this.resizeObserver?.disconnect();
    this.mutationObserver?.disconnect();
  }

  /** Recompute the edge state from the current scroll position. */
  update(): void {
    const el = this.host.nativeElement;
    const max = el.scrollWidth - el.clientWidth;
    const pos = Math.abs(el.scrollLeft);
    this.overflowing.set(max > 4);
    this.atStart.set(pos <= 4);
    this.atEnd.set(pos >= max - 4);
  }

  /**
   * Scroll roughly one "page" (80% of the visible width) towards the
   * inline-end (`1`) or inline-start (`-1`). Scroll-snap settles the
   * result on a whole item.
   */
  scroll(direction: 1 | -1): void {
    const el = this.host.nativeElement;
    const step = Math.max(el.clientWidth * 0.8, 120);
    const left = direction * this.inlineSign() * step;
    if (typeof el.scrollBy === 'function') {
      el.scrollBy({ left, behavior: this.prefersReducedMotion() ? 'auto' : 'smooth' });
    } else {
      el.scrollLeft += left;
    }
  }

  /** Arrow keys move focus item-to-item (visual order, RTL-aware). */
  onKeydown(event: KeyboardEvent): void {
    const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
    if (!keys.includes(event.key)) return;
    const items = Array.from(
      this.host.nativeElement.querySelectorAll<HTMLElement>('[data-rail-item]'),
    );
    if (items.length === 0) return;
    const active = items.findIndex((item) => item.contains(document.activeElement));
    if (active === -1) return;

    let next: number;
    if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = items.length - 1;
    } else {
      /* ArrowRight is "next" in LTR and "previous" in RTL. */
      const forward = (event.key === 'ArrowRight') === (this.inlineSign() === 1);
      next = Math.min(items.length - 1, Math.max(0, active + (forward ? 1 : -1)));
    }
    event.preventDefault();
    if (next === active) return;
    const target =
      items[next].querySelector<HTMLElement>('[data-rail-focus]') ??
      items[next].querySelector<HTMLElement>('a[href], button:not([disabled])');
    target?.focus();
  }

  /** +1 in LTR, -1 in RTL (the sign of "towards the inline end"). */
  private inlineSign(): 1 | -1 {
    const el = this.host.nativeElement;
    if (typeof getComputedStyle !== 'function') return 1;
    return getComputedStyle(el).direction === 'rtl' ? -1 : 1;
  }

  private prefersReducedMotion(): boolean {
    return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  }
}
