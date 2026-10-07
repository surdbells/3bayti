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
    @if (variant === 'slim') {
      <!-- Slim single-line variant (PDP): same copy, analytics + destination. -->
      <p class="gcn-slim" [attr.data-context]="context" data-testid="gift-card-nudge-slim">
        <svg class="gcn-slim__icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
        </svg>
        <span class="gcn-slim__text">{{ 'giftCardNudge.' + context + '.title' | translate }}</span>
        <button type="button" class="gcn-slim__cta" (click)="open()">
          {{ 'giftCardNudge.cta' | translate }}
          <svg class="gcn-slim__arrow" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
            <path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </p>
    } @else {
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
    }
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
    .gcn-slim {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.3rem 0.5rem;
      margin: 0;
      font-size: 0.85rem;
      line-height: 1.4;
      color: var(--color-text-secondary, #5a4a3c);
    }
    .gcn-slim__icon { flex: 0 0 auto; color: var(--color-brand-500, #b18f1f); }
    .gcn-slim__cta {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0;
      border: 0;
      background: none;
      font: inherit;
      font-weight: 600;
      color: var(--color-brand-700, #5a3a2c);
      text-decoration: underline;
      text-underline-offset: 3px;
      cursor: pointer;
    }
    .gcn-slim__cta:hover { color: var(--color-brand-600, #8c6f0f); }
    .gcn-slim__cta:focus-visible {
      outline: 2px solid var(--color-brand-500, #b18f1f);
      outline-offset: 2px;
      border-radius: 4px;
    }
    :host-context([dir='rtl']) .gcn-slim__arrow { transform: scaleX(-1); }
  `],
})
export class GiftCardNudgeComponent {
  private readonly router = inject(Router);
  private readonly concierge = inject(ConciergeService);

  /** Placement context, drives the copy + the analytics label. */
  @Input() context: 'pdp' | 'cart' = 'pdp';

  /** `card` (default): the full nudge card. `slim`: one compact line (PDP). */
  @Input() variant: 'card' | 'slim' = 'card';

  open(): void {
    this.concierge.recordEvent('ai_gift_card_recommended', { context: this.context, surface: 'web' });
    void this.router.navigateByUrl('/gift-cards');
  }
}
