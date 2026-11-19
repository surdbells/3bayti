import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

import { TranslatePipe } from '../translate.pipe';
import { AxIconComponent } from './ax-mobile/icon';
import { CartCashbackEstimate } from '../core/services/cashback.service';

/**
 * Cashback programme line for the cart and checkout summaries, the mobile
 * twin of the web's estimated-cashback row.
 *
 * Purely presentational: the host fetches GET /v3/cart/cashback (via
 * CashbackService.estimateForCart) and passes the result in.
 *
 *   - eligible            → "You'll earn ≈ AED X cashback" + when it is paid
 *   - uses_cashback_credit → this payment spends a cashback card, so the
 *                            order earns nothing (the API's guard)
 *   - wallet_has_cashback_credit → heads-up that paying with that credit
 *                            forfeits the cashback
 *
 * Renders nothing for a null estimate.
 */
@Component({
  selector: 'app-cashback-estimate',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, AxIconComponent],
  template: `
    @if (estimate; as e) {
      @if (e.eligible) {
        <div class="cbe" role="note" data-testid="cashback-estimate">
          <span class="cbe__icon" aria-hidden="true"><ax-icon name="coins" [size]="16"></ax-icon></span>
          <span class="cbe__text">
            <span class="cbe__title">{{ 'cashback_estimate_line' | translate:{ amount: e.estimated_amount } }}</span>
            <span class="cbe__hint">
              @if (e.hold_days !== null && e.hold_days > 0) {
                {{ 'cashback_estimate_hint_days' | translate:{ days: e.hold_days } }}
              } @else {
                {{ 'cashback_estimate_hint' | translate }}
              }
            </span>
          </span>
        </div>
      }
      @if (e.uses_cashback_credit) {
        <p class="cbe-note cbe-note--active" role="note" data-testid="cashback-credit-note">
          <ax-icon name="info" [size]="14" aria-hidden="true"></ax-icon>
          <span>{{ 'cashback_credit_used_note' | translate }}</span>
        </p>
      } @else if (e.wallet_has_cashback_credit) {
        <p class="cbe-note" role="note" data-testid="cashback-credit-note">
          <ax-icon name="info" [size]="14" aria-hidden="true"></ax-icon>
          <span>{{ 'cashback_credit_note' | translate }}</span>
        </p>
      }
    }
  `,
  styles: [`
    :host { display: block; }
    .cbe {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-top: 12px;
      padding: 10px 12px;
      border-radius: 12px;
      background: var(--ax-color-bg-success-subtle);
      color: var(--ax-color-text-on-success-subtle);
    }
    .cbe__icon { display: inline-flex; flex-shrink: 0; margin-top: 1px; }
    .cbe__text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .cbe__title { font-size: 14px; font-weight: 700; line-height: 1.3; }
    .cbe__hint { font-size: 12px; line-height: 1.35; color: var(--ax-color-text-secondary); }
    .cbe-note {
      display: flex;
      align-items: flex-start;
      gap: 6px;
      margin: 10px 0 0;
      font-size: 12px;
      line-height: 1.4;
      color: var(--ax-color-text-secondary);
    }
    .cbe-note ax-icon { flex-shrink: 0; margin-top: 1px; }
    .cbe-note--active {
      padding: 8px 10px;
      border-radius: 10px;
      background: var(--ax-color-bg-info-subtle);
      color: var(--ax-color-text-on-info-subtle);
    }
  `],
})
export class CashbackEstimateComponent {
  @Input() estimate: CartCashbackEstimate | null = null;
}
