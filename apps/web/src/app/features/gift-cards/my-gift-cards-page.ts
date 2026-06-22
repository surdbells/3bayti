import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { GiftCardVisualComponent } from './gift-card-visual';
import { GiftCardService } from './gift-card.service';
import { CheckoutService } from '../../core/checkout/checkout.service';
import { markGiftCardCheckout } from './gift-card-checkout-handoff';
import { SeoService } from '../../core/seo/seo.service';
import type { GiftCard } from './gift-card.model';

type LoadState = 'loading' | 'ready' | 'error';

/**
 * /account/gift-cards — the buyer's gift cards (purchased + redeemed).
 *
 * Auth-gated. Loads GET /v3/gift-cards/mine (which embeds transactions)
 * once on init and renders each card as a themed ui-gift-card tile that
 * links to the detail view. No manual activation: cards activate
 * automatically via the Noon webhook after purchase.
 */
@Component({
  selector: 'app-my-gift-cards',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, GiftCardVisualComponent],
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
            } @else {
              <ul class="gca__grid" role="list" data-testid="my-gift-cards-list">
                @for (c of cards(); track c.id) {
                  <li class="gca__item">
                    @if (c.status === 'pending_payment') {
                      <!-- Unpaid (in-flight) purchase: not yet funded, so it
                           must NOT expose a spendable code or link to the
                           detail view. Offer pay-resume instead. -->
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
                      <a
                        [routerLink]="['/account/gift-cards', c.id]"
                        class="gca__tile"
                        [attr.data-card-id]="c.id"
                      >
                        <ui-gift-card [card]="c" [theme]="c.theme" />
                      </a>
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

  /** Id of the unpaid card whose pay-resume is currently in flight (or null). */
  protected readonly resumingId = signal<number | null>(null);
  /** Id of the unpaid card whose last pay-resume failed (or null). */
  protected readonly resumeError = signal<number | null>(null);

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

  private async load(): Promise<void> {
    this.loadState.set('loading');
    try {
      // Show ALL cards the buyer owns, including unpaid (pending_payment)
      // ones. Unpaid cards render as UNPAID with a pay-resume button instead
      // of linking to the detail view (which would expose the code).
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
   * The /v3/checkout/initiate { gift_card_purchase_id } call is idempotent,
   * so a buyer who bailed earlier simply lands back at the payment step.
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
