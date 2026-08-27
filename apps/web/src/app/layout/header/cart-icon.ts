import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';
import { CartService } from '../../core/cart';
import { CartDrawerService } from '../../core/cart';

/**
 * Cart icon, header-mounted trigger that opens the cart drawer.
 *
 * Visual
 * ------
 *   - Shopping-bag SVG (Heroicons "shopping-bag" outline at 24px,
 *     hand-inlined to avoid the icon library dep)
 *   - Numeric badge in the top-right corner when itemCount > 0
 *
 * a11y
 * ----
 *   - Renders as <button>; default keyboard behaviour
 *   - aria-label includes the current count so screen readers
 *     announce e.g. "Open cart, 3 items"
 *   - The badge has aria-hidden because the count is already in
 *     the aria-label
 *
 * The icon doesn't render the drawer itself, that's a separate
 * sibling at the app shell. Clicking the icon just opens the drawer.
 */
@Component({
  selector: 'app-cart-icon',
  standalone: true,
  imports: [NgIf, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="cart-icon"
      [attr.aria-label]="ariaLabel() | translate : { count: itemCount() }"
      [attr.data-aria-key]="ariaLabel()"
      (click)="onClick()"
      data-testid="cart-icon"
    >
      <svg
        class="cart-icon__svg"
        viewBox="0 0 24 24"
        width="24"
        height="24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M5 7h14l-1.2 11.2a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 7z" />
        <path d="M9 7V5a3 3 0 0 1 6 0v2" />
      </svg>
      <span
        *ngIf="itemCount() > 0"
        class="cart-icon__badge"
        aria-hidden="true"
        data-testid="cart-icon-badge"
      >
        {{ badgeText() }}
      </span>
    </button>
  `,
  styles: [
    `
      .cart-icon {
        position: relative;
        appearance: none;
        background: transparent;
        border: 1px solid transparent;
        border-radius: 8px;
        padding: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--color-text-primary, #2e241c);
        transition: background-color 0.15s ease, border-color 0.15s ease;

        &:hover,
        &:focus-visible {
          background: rgba(46, 36, 28, 0.05);
          outline: none;
          border-color: rgba(46, 36, 28, 0.12);
        }
      }

      .cart-icon__svg {
        display: block;
      }

      .cart-icon__badge {
        position: absolute;
        top: 2px;
        inset-inline-end: 2px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 999px;
        background: var(--color-brand-500, #b18f1f);
        color: #fff;
        font-size: 11px;
        font-weight: 600;
        line-height: 18px;
        text-align: center;
        font-variant-numeric: tabular-nums;
        box-shadow: 0 0 0 2px var(--color-bg-surface, #fff);
      }
    `,
  ],
})
export class CartIconComponent {
  private readonly cart = inject(CartService);
  private readonly drawer = inject(CartDrawerService);

  protected readonly itemCount = this.cart.itemCount;

  /** Caps the badge display at "99+" to prevent overflow. The full
   *  count is still announced via the aria-label. */
  protected readonly badgeText = computed(() => {
    const n = this.cart.itemCount();
    if (n <= 99) return String(n);
    return '99+';
  });

  /** Picks the i18n key, different key for empty vs non-empty so
   *  translations can read naturally in both states. */
  protected readonly ariaLabel = computed(() => {
    return this.cart.itemCount() === 0 ? 'header.cart.openEmpty' : 'header.cart.openWithCount';
  });

  protected onClick(): void {
    this.drawer.toggle();
  }
}
