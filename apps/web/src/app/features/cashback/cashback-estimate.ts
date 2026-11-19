import { Component, ChangeDetectionStrategy, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

import type { CartCashbackPreview } from './cashback.model';

/**
 * "You'll earn ≈ AED X cashback" line for the cart summary and the checkout
 * review totals, plus the note that paying with cashback credit earns none.
 *
 * Renders nothing when there is no preview, or when nothing is earned for a
 * reason other than cashback credit (no matching rule, programme off, ...).
 */
@Component({
  selector: 'app-cashback-estimate',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    @if (preview(); as p) {
      @if (p.uses_cashback_credit) {
        <p class="cashback-estimate__note" role="note" data-testid="cashback-credit-note">
          {{ 'cashback.paidWithCredit' | translate }}
        </p>
      } @else if (p.eligible) {
        <div class="cashback-estimate" data-testid="cashback-estimate">
          <span class="cashback-estimate__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="8" width="18" height="12" rx="2" />
              <path d="M3 12h18M12 8v12M12 8c-1.5-3-5-3-5-1s3 1 5 1zm0 0c1.5-3 5-3 5-1s-3 1-5 1z" />
            </svg>
          </span>
          <span class="cashback-estimate__body">
            <span class="cashback-estimate__amount" data-testid="cashback-estimate-amount">
              {{ 'cashback.estimate' | translate: { currency: p.currency, amount: p.estimated_amount } }}
            </span>
            <span class="cashback-estimate__hint">
              @if (p.hold_days !== null && p.hold_days > 1) {
                {{ 'cashback.estimateHintDays' | translate: { days: p.hold_days } }}
              } @else {
                {{ 'cashback.estimateHint' | translate }}
              }
            </span>
          </span>
        </div>
        @if (showWalletHint() && p.wallet_has_cashback_credit) {
          <p class="cashback-estimate__note" role="note" data-testid="cashback-wallet-note">
            {{ 'cashback.paidWithCredit' | translate }}
          </p>
        }
      }
    }
  `,
  styles: [`
    :host { display: block; }
    .cashback-estimate {
      display: flex;
      align-items: flex-start;
      gap: var(--space-xs, 8px);
      margin-top: var(--space-sm, 12px);
      padding: var(--space-xs, 8px) var(--space-sm, 12px);
      border-radius: var(--radius-md, 12px);
      background: var(--color-success-bg, #e6f2ea);
      color: var(--color-success, #2f7d52);
    }
    .cashback-estimate__icon { display: inline-flex; padding-top: 2px; flex-shrink: 0; }
    .cashback-estimate__body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .cashback-estimate__amount { font-size: 0.875rem; font-weight: 600; font-variant-numeric: tabular-nums; }
    .cashback-estimate__hint { font-size: 0.75rem; color: var(--color-text-secondary, #5a4a3c); }
    .cashback-estimate__note {
      margin: var(--space-xs, 8px) 0 0;
      font-size: 0.75rem;
      line-height: 1.4;
      color: var(--color-text-secondary, #5a4a3c);
    }
  `],
})
export class CashbackEstimateComponent {
  readonly preview = input<CartCashbackPreview | null>(null);
  /** Also warn (when eligible) that spending wallet cashback credit forfeits it. */
  readonly showWalletHint = input<boolean>(false);
}
