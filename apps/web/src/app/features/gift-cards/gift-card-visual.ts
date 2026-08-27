import { Component, Input, inject } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { NgIf } from '@angular/common';
import {
  GiftCard,
  GiftCardStatus,
  GiftCardTheme,
  GiftCardThemeMeta,
  GIFT_CARD_THEMES,
} from './gift-card.model';

/**
 * `ui-gift-card`, the themed gift-card visual.
 *
 * Renders in two modes from the same template:
 *   • Preview , pass `theme` (+ optional amount / recipient / photo)
 *                before any card exists, for the purchase configurator.
 *   • Real    , pass a full `card` (or the granular fields) to show an
 *                owned/received card with its code, balance and status.
 *
 * Each theme drives a decorative SVG motif (sunburst / rings / star /
 * bloom / seal / medallion) and an exact colour palette, mirrored from
 * the server THEME_META so preview needs no round-trip. The motif is
 * inline SVG (kept out of the SCSS budget); the layout/typography live
 * in the stylesheet.
 *
 * Inputs use the `@Input()` decorator (not signal `input()`) to stay
 * compatible with the vitest JIT harness.
 */
@Component({
  selector: 'ui-gift-card',
  standalone: true,
  imports: [NgIf],
  template: `
    <div
      class="gc"
      [class.gc--has-photo]="hasPhoto"
      [attr.data-pattern]="meta.pattern"
      [style.--gc-primary]="meta.primary_color"
      [style.--gc-accent]="meta.accent_color"
      [style.--gc-text]="meta.text_color"
      [style.--gc-border]="meta.border_color"
      [attr.aria-label]="ariaLabel"
      role="img"
    >
      <svg
        class="gc__motif"
        viewBox="0 0 340 210"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
        focusable="false"
      >
        @switch (meta.pattern) {
          @case ('sunburst') {
            <g fill="none" stroke="currentColor" stroke-width="1.4">
              @for (a of rays; track a) {
                <line x1="340" y1="0" [attr.x2]="sx(a)" [attr.y2]="sy(a)" stroke-opacity="0.55" />
              }
              <circle cx="340" cy="0" r="150" stroke-opacity="0.18" />
              <circle cx="340" cy="0" r="110" stroke-opacity="0.14" />
              <circle cx="46" cy="176" r="30" stroke-opacity="0.30" />
              <circle cx="46" cy="176" r="20" stroke-opacity="0.22" />
            </g>
          }
          @case ('rings') {
            <g fill="none" stroke="currentColor">
              <circle cx="250" cy="62" r="46" stroke-width="2.4" stroke-opacity="0.6" />
              <circle cx="296" cy="62" r="46" stroke-width="2.4" stroke-opacity="0.45" />
              <path d="M0 150 Q60 128 120 150 T240 150 T360 150" stroke-width="1.4" stroke-opacity="0.3" />
              <path d="M0 168 Q60 146 120 168 T240 168 T360 168" stroke-width="1.4" stroke-opacity="0.2" />
            </g>
          }
          @case ('star') {
            <g fill="none" stroke="currentColor" stroke-width="1.6">
              <polygon [attr.points]="starPoints" stroke-opacity="0.55" />
              <circle cx="278" cy="58" r="34" stroke-opacity="0.22" />
              <path d="M306 30 a22 22 0 1 0 4 30 a17 17 0 1 1 -4 -30 Z" fill="currentColor" fill-opacity="0.25" stroke="none" />
              <path d="M0 188 q40 -16 80 0 t80 0 t80 0 t80 0" stroke-opacity="0.2" />
            </g>
          }
          @case ('bloom') {
            <g fill="none" stroke="currentColor" stroke-width="1.5">
              @for (p of petals; track p) {
                <ellipse cx="276" cy="60" rx="11" ry="34" [attr.transform]="'rotate(' + p + ' 276 60)'" stroke-opacity="0.5" />
              }
              <circle cx="276" cy="60" r="9" fill="currentColor" fill-opacity="0.4" stroke="none" />
              <circle cx="60" cy="180" r="5" fill="currentColor" fill-opacity="0.3" stroke="none" />
            </g>
          }
          @case ('seal') {
            <g fill="none" stroke="currentColor">
              <circle cx="278" cy="60" r="44" stroke-width="2" stroke-opacity="0.55" />
              <circle cx="278" cy="60" r="34" stroke-width="1.2" stroke-opacity="0.4" />
              <polygon [attr.points]="sealStar" stroke-width="1.2" stroke-opacity="0.5" />
              @for (t of ticks; track t) {
                <line [attr.x1]="tx(t, 44)" [attr.y1]="ty(t, 44)" [attr.x2]="tx(t, 50)" [attr.y2]="ty(t, 50)" stroke-width="1.6" stroke-opacity="0.5" />
              }
            </g>
          }
          @case ('medallion') {
            <g fill="none" stroke="currentColor">
              <circle cx="278" cy="60" r="46" stroke-width="2.2" stroke-opacity="0.6" />
              <circle cx="278" cy="60" r="36" stroke-width="1" stroke-opacity="0.4" />
              <polygon [attr.points]="medStar" stroke-width="1.2" stroke-opacity="0.55" />
              <line x1="26" y1="20" x2="26" y2="190" stroke-width="3" stroke-opacity="0.45" />
              <line x1="36" y1="20" x2="36" y2="190" stroke-width="1.2" stroke-opacity="0.3" />
              @for (h of hatch; track h) {
                <line [attr.x1]="h" y1="210" [attr.x2]="h + 60" y2="150" stroke-width="0.8" stroke-opacity="0.12" />
              }
            </g>
          }
        }
      </svg>

      <div class="gc__content">
        <div class="gc__head">
          <span class="gc__brand">3bayti</span>
          <span class="gc__label">{{ meta.label }}</span>
        </div>

        <div class="gc__photo" *ngIf="hasPhoto">
          <img [src]="photoUrl" alt="" />
        </div>

        <div class="gc__amount">{{ formattedAmount }}</div>
        <div class="gc__balance" *ngIf="showBalance">{{ balanceLabel }}</div>

        <div class="gc__recipient" *ngIf="recipientName">To {{ recipientName }}</div>
        <p class="gc__message" *ngIf="recipientMessage">{{ recipientMessage }}</p>

        <div class="gc__foot">
          <span class="gc__code" *ngIf="code">{{ displayCode }}</span>
          <span class="gc__status" *ngIf="statusBadge" [attr.data-status]="status">{{ statusBadge }}</span>
        </div>
      </div>
    </div>
  `,
  styleUrl: './gift-card-visual.scss',
})
export class GiftCardVisualComponent {
  private i18n = inject(TranslateService);

  @Input({ required: true }) theme!: GiftCardTheme;
  /** Optional palette override (e.g. a real card's `theme_meta`). */
  @Input() themeMeta?: GiftCardThemeMeta | null;
  @Input() amount?: string | number | null;
  @Input() balance?: string | number | null;
  @Input() recipientName?: string | null;
  @Input() recipientMessage?: string | null;
  @Input() photoUrl?: string | null;
  @Input() code?: string | null;
  @Input() status?: GiftCardStatus | null;
  /** Reveal the full code instead of masking all but the last group. */
  @Input() revealCode = false;

  /** Convenience: hydrate every display field from a full card at once. */
  @Input() set card(c: GiftCard | null | undefined) {
    if (!c) return;
    this.theme = c.theme;
    this.themeMeta = c.theme_meta;
    this.amount = c.denomination;
    this.balance = c.balance;
    this.recipientName = c.recipient_name;
    this.recipientMessage = c.recipient_message;
    this.photoUrl = c.recipient_photo_url;
    this.code = c.code;
    this.status = c.status;
  }

  // Motif geometry helpers ------------------------------------------------
  readonly rays = [0, 8, 16, 24, 32, 40, 48, 56, 64, 72, 80, 88];
  readonly petals = [0, 30, 60, 90, 120, 150];
  readonly ticks = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
  readonly hatch = [40, 90, 140, 190, 240, 290];

  /** Sunburst ray endpoints fanning down-left from the top-right corner. */
  sx(deg: number): number {
    return 340 - 360 * Math.cos((deg * Math.PI) / 180);
  }
  sy(deg: number): number {
    return 360 * Math.sin((deg * Math.PI) / 180);
  }

  /** Seal tick marks around a 278,60 circle. */
  tx(i: number, r: number): number {
    return 278 + r * Math.cos((i * 30 * Math.PI) / 180);
  }
  ty(i: number, r: number): number {
    return 60 + r * Math.sin((i * 30 * Math.PI) / 180);
  }

  private starPolygon(cx: number, cy: number, points: number, outer: number, inner: number): string {
    const out: string[] = [];
    const step = Math.PI / points;
    for (let i = 0; i < points * 2; i++) {
      const r = i % 2 === 0 ? outer : inner;
      const a = i * step - Math.PI / 2;
      out.push(`${(cx + r * Math.cos(a)).toFixed(1)},${(cy + r * Math.sin(a)).toFixed(1)}`);
    }
    return out.join(' ');
  }

  get starPoints(): string {
    return this.starPolygon(276, 60, 12, 42, 20);
  }
  get sealStar(): string {
    return this.starPolygon(278, 60, 8, 28, 12);
  }
  get medStar(): string {
    return this.starPolygon(278, 60, 8, 30, 13);
  }

  // Display getters -------------------------------------------------------
  get meta(): GiftCardThemeMeta {
    return this.themeMeta ?? GIFT_CARD_THEMES[this.theme];
  }

  get hasPhoto(): boolean {
    return this.meta.supports_photo && !!this.photoUrl;
  }

  private aed(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);
    if (!isFinite(n)) return 'AED 0';
    return 'AED ' + new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n);
  }

  get formattedAmount(): string {
    return this.aed(this.amount);
  }

  get showBalance(): boolean {
    if (this.balance == null || this.amount == null) return false;
    return Number(this.balance) !== Number(this.amount);
  }

  get balanceLabel(): string {
    return this.aed(this.balance) + ' left';
  }

  get displayCode(): string {
    const c = (this.code ?? '').toUpperCase();
    if (!c) return '';
    if (this.revealCode) return c;
    const groups = c.split('-');
    const last = groups[groups.length - 1] ?? c.slice(-4);
    return '•••• •••• ' + last;
  }

  get statusBadge(): string | null {
    switch (this.status) {
      case 'pending_payment':
        return this.i18n.instant('giftCards.status.pending');
      case 'partially_used':
        return this.i18n.instant('giftCards.status.partlyUsed');
      case 'exhausted':
        return this.i18n.instant('giftCards.status.usedUp');
      case 'expired':
        return this.i18n.instant('giftCards.status.expired');
      case 'voided':
        return this.i18n.instant('giftCards.status.voided');
      default:
        return null; // 'active' or preview → no badge
    }
  }

  get ariaLabel(): string {
    const parts = [this.meta.label, 'gift card', this.formattedAmount];
    if (this.recipientName) parts.push('for ' + this.recipientName);
    return parts.join(' ');
  }
}
