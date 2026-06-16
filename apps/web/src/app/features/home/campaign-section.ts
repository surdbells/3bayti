import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { CountdownComponent } from '../../shared/ui/countdown';
import { formatMoney } from '../catalog/product.model';
import type { Money } from '../catalog/product.model';
import type { Campaign, CampaignItem, CampaignType } from '../campaigns/campaign.model';

/**
 * CampaignSection — a homepage rail for a live campaign (Anniversary
 * Deals or Flash Sale). Visually distinct from the neutral product
 * strips: a tinted band, a prominent live countdown, per-item savings
 * badges, and — for flash sales — scarcity stock bars.
 *
 * Variant differences:
 *   anniversary → celebratory gold band, savings badges, no stock bars
 *   flash       → ember band, savings badges + per-item stock bars
 *
 * The parent renders this only when the campaign is non-null, so there's
 * no empty state to handle here.
 */
@Component({
  selector: 'home-campaign-section',
  standalone: true,
  imports: [CfImagePipe, CountdownComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="cq" [class.cq--flash]="variant === 'flash'" [attr.aria-label]="campaign.title">
      <div class="cq__bounds">
        <header class="cq__head">
          <div class="cq__head-text">
            <p class="cq__eyebrow">{{ eyebrow }}</p>
            <h2 class="cq__title">{{ campaign.title }}</h2>
            @if (campaign.subtitle) {
              <p class="cq__subtitle">{{ campaign.subtitle }}</p>
            }
          </div>
          <div class="cq__timer">
            <span class="cq__timer-label">{{ variant === 'flash' ? 'Ends in' : 'Offer ends in' }}</span>
            <ui-countdown [endsAt]="campaign.ends_at" [serverNow]="serverNow" />
          </div>
        </header>

        <div class="cq__rail" role="list">
          @for (item of campaign.items; track item.product.id) {
            <article class="cq-card" role="listitem">
              <a class="cq-card__media" [href]="'/product/' + item.product.slug" tabindex="-1" aria-hidden="true">
                @if (item.product.primary_image?.url) {
                  <img
                    [src]="item.product.primary_image!.url | cfImage:'card'"
                    [alt]="''"
                    loading="lazy"
                    decoding="async"
                  />
                } @else {
                  <span class="cq-card__placeholder"></span>
                }
                @if (item.discount_percent > 0) {
                  <span class="cq-card__badge">−{{ item.discount_percent }}%</span>
                }
              </a>
              <div class="cq-card__body">
                @if (item.product.vendor?.name) {
                  <p class="cq-card__vendor">{{ item.product.vendor!.name }}</p>
                }
                <a class="cq-card__name" [href]="'/product/' + item.product.slug">{{ item.product.name }}</a>
                <p class="cq-card__price">
                  @if (item.campaign_price) {
                    <span class="cq-card__price-now">{{ money(item.campaign_price) }}</span>
                    <span class="cq-card__price-was">{{ money(item.product.price) }}</span>
                  } @else {
                    <span class="cq-card__price-now">{{ money(item.product.price) }}</span>
                  }
                </p>

                @if (showStock && item.stock_total) {
                  <div class="cq-stock" [attr.aria-label]="stockLabel(item)">
                    <span class="cq-stock__track">
                      <span class="cq-stock__fill" [style.width.%]="stockPct(item)"></span>
                    </span>
                    <span class="cq-stock__txt">{{ item.stock_remaining }} left</span>
                  </div>
                }
              </div>
            </article>
          }
        </div>
      </div>
    </section>
  `,
  styles: [`
    :host { display: block; }

    .cq {
      padding-block: var(--space-2xl);
      padding-inline: var(--page-padding-x);
      background:
        linear-gradient(150deg, #f7edd7 0%, #faf3e6 55%, var(--color-bg-canvas) 100%);
      border-block: 1px solid var(--color-border-subtle);
    }
    .cq--flash {
      background:
        linear-gradient(150deg, var(--color-flash-bg) 0%, #fdf1e7 55%, var(--color-bg-canvas) 100%);
    }

    .cq__bounds {
      max-width: var(--page-max-width);
      margin-inline: auto;
    }

    .cq__head {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: var(--space-md) var(--space-lg);
      margin-block-end: var(--space-lg);
    }
    .cq__head-text { min-width: 0; }
    .cq__eyebrow {
      margin: 0 0 var(--space-2xs);
      font-size: var(--text-eyebrow);
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--color-sale);
    }
    .cq--flash .cq__eyebrow { color: var(--color-flash); }
    .cq__title {
      margin: 0;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-weight: 600;
      font-size: var(--text-section-title);
      line-height: 1.1;
      color: var(--color-brand-700);
    }
    .cq__subtitle {
      margin: var(--space-2xs) 0 0;
      font-size: 0.95rem;
      color: var(--color-text-secondary);
    }

    .cq__timer {
      display: inline-flex;
      flex-direction: column;
      gap: var(--space-2xs);
      align-items: flex-start;
    }
    .cq__timer-label {
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--color-text-tertiary);
    }

    /* Horizontal rail */
    .cq__rail {
      display: flex;
      gap: var(--space-md);
      overflow-x: auto;
      overflow-y: visible;
      padding-block: var(--space-2xs) var(--space-sm);
      scroll-snap-type: x proximity;
      scrollbar-width: none;
      -ms-overflow-style: none;
    }
    .cq__rail::-webkit-scrollbar { display: none; }

    .cq-card {
      flex: 0 0 auto;
      width: 168px;
      scroll-snap-align: start;
    }
    @media (min-width: 1024px) {
      .cq-card { width: 200px; }
    }

    .cq-card__media {
      position: relative;
      display: block;
      aspect-ratio: 3 / 4;
      border-radius: var(--radius-md);
      overflow: hidden;
      background: var(--color-bg-muted);
      box-shadow: var(--shadow-card-resting);
    }
    .cq-card__media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform var(--duration-slow) var(--ease-out);
    }
    .cq-card:hover .cq-card__media img { transform: scale(1.05); }
    .cq-card__placeholder {
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, var(--color-brand-50), var(--color-bg-muted));
    }
    .cq-card__badge {
      position: absolute;
      inset-block-start: var(--space-xs);
      inset-inline-start: var(--space-xs);
      padding: 2px 8px;
      border-radius: var(--radius-pill);
      background: var(--color-sale);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      box-shadow: var(--shadow-floating);
    }
    .cq--flash .cq-card__badge { background: var(--color-flash); }

    .cq-card__body {
      display: flex;
      flex-direction: column;
      gap: 2px;
      padding-block-start: var(--space-sm);
    }
    .cq-card__vendor {
      margin: 0;
      font-size: 0.72rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--color-text-tertiary);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .cq-card__name {
      font-family: 'Playfair Display', Georgia, serif;
      font-weight: 600;
      font-size: 0.95rem;
      line-height: 1.25;
      color: var(--color-brand-700);
      text-decoration: none;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .cq-card__name:hover { color: var(--color-brand-500); }
    .cq-card__name:focus-visible {
      outline: 2px solid var(--color-brand-500);
      outline-offset: 2px;
      border-radius: 2px;
    }
    .cq-card__price {
      margin: var(--space-2xs) 0 0;
      display: flex;
      align-items: baseline;
      gap: var(--space-xs);
      flex-wrap: wrap;
    }
    .cq-card__price-now {
      font-weight: 700;
      font-size: 0.95rem;
      color: var(--color-sale);
    }
    .cq--flash .cq-card__price-now { color: var(--color-flash); }
    .cq-card__price-was {
      font-size: 0.82rem;
      color: var(--color-text-tertiary);
      text-decoration: line-through;
    }

    /* Flash-sale stock bar */
    .cq-stock {
      display: flex;
      align-items: center;
      gap: var(--space-xs);
      margin-block-start: var(--space-xs);
    }
    .cq-stock__track {
      position: relative;
      flex: 1;
      height: 5px;
      border-radius: var(--radius-pill);
      background: rgba(46, 36, 28, 0.10);
      overflow: hidden;
    }
    .cq-stock__fill {
      position: absolute;
      inset-block: 0;
      inset-inline-start: 0;
      border-radius: var(--radius-pill);
      background: var(--color-flash);
      transition: width var(--duration-slow) var(--ease-out);
    }
    .cq-stock__txt {
      font-size: 0.72rem;
      font-weight: 600;
      color: var(--color-flash);
      white-space: nowrap;
    }

    @media (prefers-reduced-motion: reduce) {
      .cq-card:hover .cq-card__media img { transform: none; }
      .cq-stock__fill { transition: none; }
    }
  `],
})
export class CampaignSectionComponent {
  @Input({ required: true }) campaign!: Campaign;
  @Input() serverNow?: string;
  @Input() variant: CampaignType = 'anniversary';
  @Input() eyebrow = '';

  get showStock(): boolean {
    return this.variant === 'flash';
  }

  money(m: Money | null | undefined): string {
    return formatMoney(m);
  }

  stockPct(item: CampaignItem): number {
    if (!item.stock_total || item.stock_total <= 0) return 0;
    const remaining = item.stock_remaining ?? 0;
    return Math.max(0, Math.min(100, Math.round((remaining / item.stock_total) * 100)));
  }

  stockLabel(item: CampaignItem): string {
    return `${item.stock_remaining ?? 0} of ${item.stock_total} remaining`;
  }
}
