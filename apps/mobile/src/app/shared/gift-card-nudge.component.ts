import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';

import { TranslatePipe } from '../translate.pipe';
import { AxIconComponent } from './ax-mobile/icon';

/**
 * "Send a gift card instead" nudge, the mobile twin of the web's
 * app-gift-card-nudge (apps/web/src/app/features/gift-cards/gift-card-nudge.ts).
 *
 * Purely presentational: the host owns the side effects (the
 * `ai_gift_card_recommended` analytics beacon, which needs the host's Ain
 * session + auth token, and the navigation to /gift-cards) and runs them from
 * `(opened)`.
 *
 *   slim (PDP): one compact tappable row, "Not sure about their size?
 *               Send a gift card", matching the web PDP's slim variant.
 *   card:       the fuller icon + title + sub + pill card (cart look).
 *
 * Copy comes from the shared `gift_card_nudge_*` keys (en + ar).
 */
@Component({
  selector: 'app-gift-card-nudge',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, AxIconComponent],
  template: `
    @if (variant === 'slim') {
      <button
        type="button"
        class="gcn-slim"
        [attr.data-context]="context"
        data-testid="gift-card-nudge-slim"
        (click)="opened.emit()">
        <span class="gcn-slim__icon" aria-hidden="true">
          <ax-icon name="gift" [size]="18"></ax-icon>
        </span>
        <span class="gcn-slim__text">{{ 'gift_card_nudge_' + context + '_title' | translate }}</span>
        <span class="gcn-slim__cta">
          {{ 'gift_card_nudge_cta' | translate }}
          <ax-icon class="gcn-slim__arrow" name="arrow-right" [size]="14" aria-hidden="true"></ax-icon>
        </span>
      </button>
    } @else {
      <button
        type="button"
        class="gcn"
        [attr.data-context]="context"
        data-testid="gift-card-nudge-card"
        (click)="opened.emit()">
        <span class="gcn__icon" aria-hidden="true">
          <ax-icon name="gift" [size]="20"></ax-icon>
        </span>
        <span class="gcn__body">
          <span class="gcn__title">{{ 'gift_card_nudge_' + context + '_title' | translate }}</span>
          <span class="gcn__sub">{{ 'gift_card_nudge_' + context + '_sub' | translate }}</span>
        </span>
        <span class="gcn__cta">{{ 'gift_card_nudge_cta' | translate }}</span>
      </button>
    }
  `,
  styles: [
    `
      :host {
        display: block;
      }

      /* ----- slim: one quiet, tappable line ----------------------------- */
      .gcn-slim {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        min-height: 44px;
        margin: 0;
        padding: 10px 12px;
        border: 1px solid var(--ax-ain-border-warm);
        border-radius: var(--ax-radius-lg);
        background: var(--ax-ain-cream-1);
        color: var(--ax-ain-ink-muted);
        font: inherit;
        font-size: 13px;
        line-height: 1.35;
        text-align: start;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
        transition: background-color 0.15s ease, border-color 0.15s ease;
      }
      .gcn-slim:active {
        background: var(--ax-ain-cream-pressed);
        border-color: var(--ax-ain-pressed-border);
      }
      .gcn-slim:focus-visible {
        outline: none;
        box-shadow: var(--ax-shadow-focus);
      }
      .gcn-slim__icon {
        flex: 0 0 auto;
        display: inline-flex;
        color: var(--ax-ain-gold);
      }
      .gcn-slim__text {
        flex: 1 1 auto;
        min-width: 0;
      }
      .gcn-slim__cta {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-weight: 600;
        color: var(--ax-ain-accent);
        white-space: nowrap;
      }

      /* ----- card: icon + title + sub + pill ---------------------------- */
      .gcn {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        margin: 0;
        padding: 12px 14px;
        border: 1px solid var(--ax-ain-border-warm);
        border-radius: var(--ax-radius-2xl);
        background: linear-gradient(135deg, var(--ax-ain-cream-1), var(--ax-ain-cream-2));
        font: inherit;
        text-align: start;
        cursor: pointer;
        -webkit-tap-highlight-color: transparent;
      }
      .gcn:active {
        background: var(--ax-ain-cream-pressed);
      }
      .gcn:focus-visible {
        outline: none;
        box-shadow: var(--ax-shadow-focus);
      }
      .gcn__icon {
        flex: 0 0 auto;
        width: 40px;
        height: 40px;
        border-radius: var(--ax-radius-full);
        display: grid;
        place-items: center;
        background: var(--ax-color-bg-brand);
        color: var(--ax-color-text-on-brand);
      }
      .gcn__body {
        flex: 1 1 auto;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
      }
      .gcn__title {
        font-size: 14px;
        font-weight: 700;
        color: var(--ax-ain-accent);
      }
      .gcn__sub {
        font-size: 12.5px;
        line-height: 1.35;
        color: var(--ax-ain-ink-muted);
      }
      .gcn__cta {
        flex: 0 0 auto;
        border-radius: var(--ax-radius-pill);
        background: var(--ax-color-bg-brand);
        color: var(--ax-color-text-on-brand);
        padding: 8px 14px;
        font-size: 12.5px;
        font-weight: 600;
        white-space: nowrap;
      }

      /* Point the arrow the reading direction if the layout is ever RTL. */
      :host-context([dir='rtl']) .gcn-slim__arrow {
        transform: scaleX(-1);
      }

      @media (prefers-reduced-motion: reduce) {
        .gcn-slim {
          transition: none;
        }
      }
    `,
  ],
})
export class GiftCardNudgeComponent {
  /** Placement context: drives the copy (`gift_card_nudge_<context>_*`). */
  @Input() context: 'pdp' | 'cart' = 'pdp';

  /** `card` (default): the full nudge card. `slim`: one compact row (PDP). */
  @Input() variant: 'card' | 'slim' = 'card';

  /** Tapped: the host beacons analytics and opens the gift-card flow. */
  @Output() readonly opened = new EventEmitter<void>();
}
