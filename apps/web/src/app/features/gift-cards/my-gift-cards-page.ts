import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { isPlatformBrowser, DecimalPipe } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { GiftCardVisualComponent } from './gift-card-visual';
import { GiftCardService } from './gift-card.service';
import { CheckoutService } from '../../core/checkout/checkout.service';
import { markGiftCardCheckout } from './gift-card-checkout-handoff';
import { SeoService } from '../../core/seo/seo.service';
import type { GiftCard, GiftCardStatus } from './gift-card.model';

type LoadState = 'loading' | 'ready' | 'error';

/** UI buckets for the status filter row (mirrors the mobile wallet). */
type GiftCardFilter = 'all' | 'active' | 'pending_payment' | 'used' | 'expired' | 'voided';

/**
 * /account/gift-cards, the buyer's gift cards (purchased + redeemed).
 *
 * Auth-gated. Loads GET /v3/gift-cards/mine (which embeds transactions) once on
 * init and renders each card as a themed ui-gift-card tile. Mirrors the mobile
 * "My gift cards" wallet: a status-filter chip row, a per-card status badge,
 * and a copy-code affordance on spendable cards. Unpaid (pending_payment) cards
 * offer pay-resume instead of a spendable code/detail link.
 */
@Component({
  selector: 'app-my-gift-cards',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, DecimalPipe, GiftCardVisualComponent],
  template: `
    <main class="gca" data-testid="my-gift-cards-page">
      <div class="gca__container">
        <header class="gca__header">
          <div class="gca__heading">
            <h1 class="gca__title">{{ 'giftCards.mine.title' | translate }}</h1>
            <p class="gca__subtitle">{{ 'giftCards.mine.subtitle' | translate }}</p>
          </div>
          <div class="gca__actions">
            <a routerLink="/gift-cards" class="gca__action gca__action--primary">
              {{ 'giftCards.mine.buy' | translate }}
            </a>
            <a routerLink="/gift-cards/redeem" class="gca__action">
              {{ 'giftCards.mine.redeem' | translate }}
            </a>
          </div>
        </header>

        @if (loadState() === 'ready' && cards().length > 0) {
          <div class="gca__filters" role="tablist" [attr.aria-label]="'giftCards.mine.filtersLabel' | translate">
            @for (f of filters; track f.key) {
              <button
                type="button"
                class="gca__chip"
                role="tab"
                [class.gca__chip--active]="filter() === f.key"
                [attr.aria-selected]="filter() === f.key"
                (click)="setFilter(f.key)"
                [attr.data-filter]="f.key"
              >
                {{ f.labelKey | translate }}
              </button>
            }
          </div>
        }

        @switch (loadState()) {
          @case ('loading') {
            <div class="gca__grid" aria-hidden="true">
              <div class="gca__skeleton"></div>
              <div class="gca__skeleton"></div>
              <div class="gca__skeleton"></div>
            </div>
          }

          @case ('error') {
            <div class="gca__state gca__state--error" role="alert">
              <p>{{ 'giftCards.mine.errorLoad' | translate }}</p>
              <button type="button" class="gca__retry" (click)="reload()">
                {{ 'giftCards.mine.retry' | translate }}
              </button>
            </div>
          }

          @case ('ready') {
            @if (cards().length === 0) {
              <div class="gca__state gca__state--empty" data-testid="my-gift-cards-empty">
                <h2 class="gca__empty-title">{{ 'giftCards.mine.emptyTitle' | translate }}</h2>
                <p class="gca__empty-body">{{ 'giftCards.mine.emptyBody' | translate }}</p>
                <div class="gca__empty-actions">
                  <a routerLink="/gift-cards" class="gca__action gca__action--primary">
                    {{ 'giftCards.mine.buy' | translate }}
                  </a>
                  <a routerLink="/gift-cards/redeem" class="gca__action">
                    {{ 'giftCards.mine.redeem' | translate }}
                  </a>
                </div>
              </div>
            } @else if (filteredCards().length === 0) {
              <div class="gca__state gca__state--empty gca__state--filter" data-testid="my-gift-cards-filter-empty">
                <p class="gca__empty-body">{{ emptyFilterKey() | translate }}</p>
              </div>
            } @else {
              <ul class="gca__grid" role="list" data-testid="my-gift-cards-list">
                @for (c of filteredCards(); track c.id) {
                  <li class="gca__item">
                    @if (c.status === 'pending_payment') {
                      <!-- Unpaid (in-flight) purchase: not funded yet, so it must
                           NOT expose a spendable code or link to the detail view.
                           Offer pay-resume instead. -->
                      <div
                        class="gca__tile gca__tile--unpaid"
                        [attr.data-card-id]="c.id"
                        data-testid="my-gift-card-unpaid"
                      >
                        <span class="gca__unpaid-badge">
                          {{ 'giftCards.mine.unpaidBadge' | translate }}
                        </span>
                        <ui-gift-card [card]="c" [theme]="c.theme" />
                        <button
                          type="button"
                          class="gca__pay"
                          [disabled]="resumingId() === c.id"
                          data-testid="my-gift-card-pay"
                          (click)="completePayment(c)"
                        >
                          {{
                            (resumingId() === c.id
                              ? 'giftCards.mine.resuming'
                              : 'giftCards.mine.completePayment'
                            ) | translate
                          }}
                        </button>
                        @if (resumeError() === c.id) {
                          <p class="gca__pay-error" role="alert">
                            {{ 'giftCards.mine.resumeError' | translate }}
                          </p>
                        }
                      </div>
                    } @else {
                      <div class="gca__tile" [attr.data-card-id]="c.id">
                        <span class="gca__status" [class]="'gca__status--' + statusVariant(c.status)">
                          {{ ('giftCards.status.' + c.status) | translate }}
                        </span>
                        <a [routerLink]="['/account/gift-cards', c.id]" class="gca__tile-link">
                          <ui-gift-card [card]="c" [theme]="c.theme" />
                        </a>
                        <div class="gca__tile-foot">
                          <span class="gca__balance">
                            {{ 'giftCards.mine.balanceLabel' | translate }}:
                            <strong>{{ c.currency }} {{ +c.balance | number: '1.2-2' }}</strong>
                          </span>
                          @if (c.is_spendable && c.code) {
                            <button
                              type="button"
                              class="gca__copy"
                              (click)="copyCode(c, $event)"
                              [attr.data-testid]="'my-gift-card-copy-' + c.id"
                            >
                              {{ (copiedId() === c.id ? 'giftCards.mine.copied' : 'giftCards.mine.copyCode') | translate }}
                            </button>
                          }
                        </div>
                      </div>
                    }
                  </li>
                }
              </ul>
            }
          }
        }
      </div>
    </main>
  `,
  styleUrl: './gift-cards-account.scss',
})
export class MyGiftCardsPageComponent implements OnInit {
  private readonly gift = inject(GiftCardService);
  private readonly checkout = inject(CheckoutService);
  private readonly router = inject(Router);
  private readonly platformId = inject(PLATFORM_ID);
  private readonly seo = inject(SeoService);

  protected readonly loadState = signal<LoadState>('loading');
  protected readonly cards = signal<GiftCard[]>([]);

  /** Active status filter (mirrors mobile's wallet chips). */
  protected readonly filter = signal<GiftCardFilter>('all');

  /** Id of the card whose code was just copied (drives the "Copied" label). */
  protected readonly copiedId = signal<number | null>(null);

  /** Id of the unpaid card whose pay-resume is currently in flight (or null). */
  protected readonly resumingId = signal<number | null>(null);
  /** Id of the unpaid card whose last pay-resume failed (or null). */
  protected readonly resumeError = signal<number | null>(null);

  /** UI bucket → the raw statuses it includes. */
  private readonly filterBuckets: Record<string, GiftCardStatus[]> = {
    active: ['active', 'partially_used'],
    pending_payment: ['pending_payment'],
    used: ['exhausted'],
    expired: ['expired'],
    voided: ['voided'],
  };

  /** Order + i18n keys for the chip row. */
  protected readonly filters: { key: GiftCardFilter; labelKey: string }[] = [
    { key: 'all', labelKey: 'giftCards.mine.filters.all' },
    { key: 'active', labelKey: 'giftCards.mine.filters.active' },
    { key: 'pending_payment', labelKey: 'giftCards.mine.filters.pending' },
    { key: 'used', labelKey: 'giftCards.mine.filters.used' },
    { key: 'expired', labelKey: 'giftCards.mine.filters.expired' },
    { key: 'voided', labelKey: 'giftCards.mine.filters.voided' },
  ];

  /** Cards matching the active filter. */
  protected readonly filteredCards = computed<GiftCard[]>(() => {
    const f = this.filter();
    if (f === 'all') return this.cards();
    const allowed = this.filterBuckets[f] ?? [];
    return this.cards().filter((c) => allowed.includes(c.status));
  });

  /** Per-filter empty-state message key. */
  protected readonly emptyFilterKey = computed(() => {
    const f = this.filter();
    return f === 'all' ? 'giftCards.mine.emptyBody' : `giftCards.mine.filterEmpty.${f}`;
  });

  protected readonly isEmpty = computed(
    () => this.loadState() === 'ready' && this.cards().length === 0,
  );

  ngOnInit(): void {
    this.seo.set({
      title: 'My Gift Cards · 3bayti',
      description: 'View the balance and history of your 3bayti gift cards.',
    });
    void this.load();
  }

  protected reload(): void {
    void this.load();
  }

  protected setFilter(key: GiftCardFilter): void {
    this.filter.set(key);
  }

  /** Badge colour variant for a status. */
  protected statusVariant(status: GiftCardStatus): string {
    switch (status) {
      case 'active':
      case 'partially_used':
        return 'active';
      case 'pending_payment':
        return 'pending';
      case 'exhausted':
        return 'used';
      default:
        return 'ended'; // expired / voided
    }
  }

  /** Copy a card's code straight from the list (sits inside a link, so guard). */
  protected async copyCode(card: GiftCard, event: Event): Promise<void> {
    event.stopPropagation();
    event.preventDefault();
    if (!card.code || !isPlatformBrowser(this.platformId)) return;
    try {
      await navigator.clipboard.writeText(card.code);
      this.copiedId.set(card.id);
      setTimeout(() => {
        if (this.copiedId() === card.id) this.copiedId.set(null);
      }, 1800);
    } catch {
      /* clipboard unavailable, no-op */
    }
  }

  private async load(): Promise<void> {
    this.loadState.set('loading');
    try {
      // Show ALL cards the buyer owns, including unpaid (pending_payment) ones.
      const list = await this.gift.listMine();
      this.cards.set(list);
      this.loadState.set('ready');
    } catch {
      this.loadState.set('error');
    }
  }

  /**
   * Resume payment for an unpaid (pending_payment) gift card. Re-initiates
   * checkout for the same purchase and redirects to the Noon hosted page.
   * The /v3/checkout/initiate { gift_card_purchase_id } call is idempotent.
   */
  protected async completePayment(card: GiftCard): Promise<void> {
    if (this.resumingId() !== null) return;
    this.resumeError.set(null);
    this.resumingId.set(card.id);
    try {
      const res = await this.checkout.initiate({
        channel: 'web',
        delivery_fee: '0.00',
        discount: '0.00',
        gift_card_purchase_id: card.id,
      });
      markGiftCardCheckout(res.order_reference);
      if (!res.checkout_url) {
        throw new Error('checkout_url missing for gift-card purchase');
      }
      this.redirectTo(res.checkout_url);
    } catch {
      this.resumingId.set(null);
      this.resumeError.set(card.id);
    }
  }

  /** Full-page navigation to the Noon hosted checkout. Isolated for tests. */
  protected redirectTo(url: string): void {
    if (isPlatformBrowser(this.platformId)) {
      window.location.assign(url);
    }
  }
}
