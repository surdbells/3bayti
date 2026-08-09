import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { isPlatformBrowser, DecimalPipe, DatePipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { GiftCardVisualComponent } from './gift-card-visual';
import { GiftCardService } from './gift-card.service';
import { SeoService } from '../../core/seo/seo.service';
import type { GiftCard, GiftCardStatus, GiftCardTransaction } from './gift-card.model';

type LoadState = 'loading' | 'ready' | 'notfound' | 'error';

/**
 * /account/gift-cards/:id — a single gift card with its balance,
 * revealed code, and transaction history.
 *
 * There is no GET /gift-cards/{id} endpoint, so we load the buyer's
 * cards (GET /v3/gift-cards/mine, which embeds transactions) and select
 * by id. This is refresh-safe (works on a direct URL hit, unlike
 * passing the card through router state).
 */
@Component({
  selector: 'app-gift-card-detail',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, TranslatePipe, DecimalPipe, DatePipe, GiftCardVisualComponent],
  template: `
    <main class="gcd" data-testid="gift-card-detail-page">
      <div class="gcd__container">
        <a routerLink="/account/gift-cards" class="gcd__back">
          ← {{ 'giftCards.detail.back' | translate }}
        </a>

        @switch (loadState()) {
          @case ('loading') {
            <div class="gcd__layout" aria-hidden="true">
              <div class="gcd__skeleton-card"></div>
              <div class="gcd__skeleton-panel"></div>
            </div>
          }

          @case ('error') {
            <div class="gcd__state gcd__state--error" role="alert">
              <p>{{ 'giftCards.detail.errorLoad' | translate }}</p>
              <button type="button" class="gcd__retry" (click)="reload()">
                {{ 'giftCards.detail.retry' | translate }}
              </button>
            </div>
          }

          @case ('notfound') {
            <div class="gcd__state gcd__state--empty" data-testid="gift-card-detail-notfound">
              <h2 class="gcd__empty-title">{{ 'giftCards.detail.notFoundTitle' | translate }}</h2>
              <p class="gcd__empty-body">{{ 'giftCards.detail.notFoundBody' | translate }}</p>
              <a routerLink="/account/gift-cards" class="gcd__empty-cta">
                {{ 'giftCards.detail.back' | translate }}
              </a>
            </div>
          }

          @case ('ready') {
            <div class="gcd__layout">
              <div class="gcd__card">
                <ui-gift-card [card]="card()" [theme]="card()!.theme" [revealCode]="true" />
              </div>

              <section class="gcd__panel">
                <!-- Balance -->
                <div class="gcd__balance">
                  <span class="gcd__balance-label">{{ 'giftCards.detail.balanceHeading' | translate }}</span>
                  <span class="gcd__balance-value">
                    {{ card()!.currency }} {{ +card()!.balance | number:'1.2-2' }}
                  </span>
                  <span class="gcd__balance-of">
                    {{ 'giftCards.detail.ofTotal' | translate }}
                    {{ card()!.currency }} {{ +card()!.denomination | number:'1.2-2' }}
                  </span>
                  <div class="gcd__bar" role="progressbar" [attr.aria-valuenow]="balancePct()" aria-valuemin="0" aria-valuemax="100">
                    <span class="gcd__bar-fill" [style.width.%]="balancePct()"></span>
                  </div>
                </div>

                <!-- Code -->
                <div class="gcd__row">
                  <span class="gcd__row-label">{{ 'giftCards.detail.codeLabel' | translate }}</span>
                  <span class="gcd__code">
                    <code>{{ card()!.code }}</code>
                    <button type="button" class="gcd__copy" (click)="copyCode()">
                      {{ (copied() ? 'giftCards.detail.copied' : 'giftCards.detail.copy') | translate }}
                    </button>
                  </span>
                </div>

                @if (card()!.recipient_name) {
                  <div class="gcd__row">
                    <span class="gcd__row-label">{{ 'giftCards.detail.recipientLabel' | translate }}</span>
                    <span class="gcd__row-value">{{ card()!.recipient_name }}</span>
                  </div>
                }
                @if (card()!.recipient_message) {
                  <div class="gcd__row">
                    <span class="gcd__row-label">{{ 'giftCards.detail.messageLabel' | translate }}</span>
                    <span class="gcd__row-value">{{ card()!.recipient_message }}</span>
                  </div>
                }
                @if (card()!.scheduled_delivery_at) {
                  <div class="gcd__row">
                    <span class="gcd__row-label">{{ 'giftCards.detail.scheduledLabel' | translate }}</span>
                    <span class="gcd__row-value">{{ card()!.scheduled_delivery_at | date:'medium' }}</span>
                  </div>
                }
                <div class="gcd__row">
                  <span class="gcd__row-label">{{ 'giftCards.detail.statusLabel' | translate }}</span>
                  <span class="gcd__status" [class]="'gcd__status--' + statusVariant(card()!.status)">
                    {{ ('giftCards.status.' + card()!.status) | translate }}
                  </span>
                </div>
                @if (card()!.expires_at) {
                  <div class="gcd__row">
                    <span class="gcd__row-label">{{ 'giftCards.detail.expiresLabel' | translate }}</span>
                    <span class="gcd__row-value">{{ card()!.expires_at | date:'mediumDate' }}</span>
                  </div>
                }
                <div class="gcd__row">
                  <span class="gcd__row-label">{{ 'giftCards.detail.createdLabel' | translate }}</span>
                  <span class="gcd__row-value">{{ card()!.created_at | date:'mediumDate' }}</span>
                </div>

                <!-- Activity -->
                <h2 class="gcd__activity-title">{{ 'giftCards.detail.transactionsHeading' | translate }}</h2>
                @if (transactions().length === 0) {
                  <p class="gcd__no-activity">{{ 'giftCards.detail.noTransactions' | translate }}</p>
                } @else {
                  <ul class="gcd__txns" role="list">
                    @for (t of transactions(); track t.id) {
                      <li class="gcd__txn">
                        <div class="gcd__txn-main">
                          <span class="gcd__txn-type">{{ t.type }}</span>
                          <time class="gcd__txn-date">{{ t.created_at | date:'medium' }}</time>
                        </div>
                        <div class="gcd__txn-amounts">
                          <span class="gcd__txn-amount">{{ card()!.currency }} {{ +t.amount | number:'1.2-2' }}</span>
                          <span class="gcd__txn-balance">
                            {{ 'giftCards.detail.balanceAfter' | translate }}
                            {{ card()!.currency }} {{ +t.balance_after | number:'1.2-2' }}
                          </span>
                        </div>
                      </li>
                    }
                  </ul>
                }
              </section>
            </div>
          }
        }
      </div>
    </main>
  `,
  styleUrl: './gift-cards-account.scss',
})
export class GiftCardDetailPageComponent implements OnInit {
  private readonly gift = inject(GiftCardService);
  private readonly seo = inject(SeoService);
  private readonly route = inject(ActivatedRoute);
  private readonly platformId = inject(PLATFORM_ID);

  protected readonly loadState = signal<LoadState>('loading');
  protected readonly card = signal<GiftCard | null>(null);
  protected readonly copied = signal<boolean>(false);

  protected readonly transactions = computed<GiftCardTransaction[]>(
    () => this.card()?.transactions ?? [],
  );

  protected readonly balancePct = computed<number>(() => {
    const c = this.card();
    if (!c) return 0;
    const denom = Number(c.denomination);
    const bal = Number(c.balance);
    if (!Number.isFinite(denom) || denom <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round((bal / denom) * 100)));
  });

  /** Badge colour variant for a status (mirrors the wallet list). */
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
        return 'ended';
    }
  }

  ngOnInit(): void {
    this.seo.set({ title: 'Gift Card · 3bayti', description: 'Your 3bayti gift card details.' });
    void this.load();
  }

  protected reload(): void {
    void this.load();
  }

  private async load(): Promise<void> {
    this.loadState.set('loading');
    const idParam = this.route.snapshot.paramMap.get('id');
    const id = Number(idParam);
    if (!Number.isInteger(id) || id <= 0) {
      this.loadState.set('notfound');
      return;
    }
    try {
      const list = await this.gift.listMine();
      const found = list.find((c) => c.id === id) ?? null;
      if (found === null) {
        this.loadState.set('notfound');
        return;
      }
      this.card.set(found);
      this.loadState.set('ready');
    } catch {
      this.loadState.set('error');
    }
  }

  protected async copyCode(): Promise<void> {
    const c = this.card();
    if (!c || !isPlatformBrowser(this.platformId)) return;
    try {
      await navigator.clipboard.writeText(c.code);
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    } catch {
      /* Clipboard blocked (permissions / insecure context) — the code
         is already visible on the card, so this is a non-fatal nicety. */
    }
  }
}
