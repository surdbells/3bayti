import { ChangeDetectionStrategy, Component, Input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * "Price updated · was AED 199.00" note for a cart line whose price the
 * vendor changed after it was added.
 *
 * The API re-syncs cart lines to the current price on every read and keeps
 * the previously-seen price (`previous_unit_price`) until the order is
 * placed, so the customer is never surprised by the total. Used by the cart
 * page, cart drawer and checkout review. Renders nothing without a previous
 * price.
 *
 * The old price is struck through visually and spelled out for screen
 * readers ("was AED 199.00"), so the change reads correctly in both.
 */
@Component({
  selector: 'ui-price-change-note',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (previousPrice) {
      <p class="pcn" data-testid="price-change-note">
        <span class="pcn__dot" aria-hidden="true"></span>
        <span class="pcn__label">{{ 'cart.priceChange.updated' | translate }}</span>
        <span class="pcn__was">
          {{ 'cart.priceChange.was' | translate }}
          <s>{{ currency }} {{ previousPrice }}</s>
        </span>
      </p>
    }
  `,
  styles: [`
    .pcn {
      display: inline-flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2xs, 4px) var(--space-xs, 8px);
      margin: var(--space-2xs, 4px) 0 0;
      padding: 2px var(--space-xs, 8px);
      border-radius: var(--radius-pill, 999px);
      background: var(--color-warning-bg, #fef3c7);
      color: var(--color-warning-text, #92400e);
      font-size: 0.75rem;
      line-height: 1.4;
    }
    .pcn__dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--color-warning-dot, #d97706);
      flex-shrink: 0;
    }
    .pcn__label { font-weight: 600; }
    .pcn__was s { text-decoration-thickness: 1px; }
  `],
})
export class PriceChangeNoteComponent {
  /** The price the customer saw before the change (decimal string), or null. */
  @Input() previousPrice: string | null | undefined = null;
  /** Currency code shown before the amount, e.g. 'AED'. */
  @Input() currency = '';
}
