import { ChangeDetectionStrategy, Component, Input, inject } from '@angular/core';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { ConciergeService } from '../ai-concierge/concierge.service';

/**
 * A tasteful, contextual "send a gift card instead" nudge that reuses the
 * existing gift-card flow (/gift-cards). Dropped onto the PDP ("not sure about
 * their size?") and cart ("shopping for someone else?"). Fires the whitelisted
 * ai_gift_card_recommended event so gift-card discovery is measurable.
 */
@Component({
  selector: 'app-gift-card-nudge',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <aside class="gcn" [attr.data-context]="context">
      <span class="gcn__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
        </svg>
      </span>
      <div class="gcn__body">
        <strong class="gcn__title">{{ 'giftCardNudge.' + context + '.title' | translate }}</strong>
        <span class="gcn__sub">{{ 'giftCardNudge.' + context + '.sub' | translate }}</span>
      </div>
      <button type="button" class="gcn__cta" (click)="open()">{{ 'giftCardNudge.cta' | translate }}</button>
    </aside>
  `,
  styles: [`
    .gcn {
      display: flex;
      align-items: center;
      gap: 0.9rem;
      border: 1px solid #ece3dc;
      background: linear-gradient(135deg, #fbf7f4, #f4ece6);
      border-radius: var(--radius-lg, 20px);
      padding: 0.9rem 1.1rem;
    }
    .gcn__icon {
      flex: 0 0 auto;
      width: 2.6rem;
      height: 2.6rem;
      border-radius: 50%;
      display: grid;
      place-items: center;
      color: #fff;
      background: linear-gradient(135deg, #a27a60, var(--color-brand-700, #5a3a2c));
    }
    .gcn__body { flex: 1; display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
    .gcn__title { color: var(--color-brand-700, #5a3a2c); font-size: 0.95rem; }
    .gcn__sub { color: var(--color-text-secondary, #5a4a3c); font-size: 0.85rem; }
    .gcn__cta {
      flex: 0 0 auto;
      border: none;
      border-radius: var(--radius-pill, 999px);
      background: var(--color-brand-700, #5a3a2c);
      color: #fff;
      padding: 0.6rem 1.2rem;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
    }
    .gcn__cta:hover { background: var(--color-brand-500, #b18f1f); }
    @media (max-width: 480px) {
      .gcn { flex-wrap: wrap; }
      .gcn__cta { width: 100%; }
    }
  `],
})
export class GiftCardNudgeComponent {
  private readonly router = inject(Router);
  private readonly concierge = inject(ConciergeService);

  /** Placement context, drives the copy + the analytics label. */
  @Input() context: 'pdp' | 'cart' = 'pdp';

  open(): void {
    this.concierge.recordEvent('ai_gift_card_recommended', { context: this.context, surface: 'web' });
    void this.router.navigateByUrl('/gift-cards');
  }
}
