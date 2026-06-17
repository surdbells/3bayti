import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { GiftCardVisualComponent } from './gift-card-visual';
import { GiftCardService } from './gift-card.service';
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
                    <a
                      [routerLink]="['/account/gift-cards', c.id]"
                      class="gca__tile"
                      [attr.data-card-id]="c.id"
                    >
                      <ui-gift-card [card]="c" [theme]="c.theme" />
                    </a>
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
  private readonly seo = inject(SeoService);

  protected readonly loadState = signal<LoadState>('loading');
  protected readonly cards = signal<GiftCard[]>([]);

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
      const list = await this.gift.listMine();
      this.cards.set(list);
      this.loadState.set('ready');
    } catch {
      this.loadState.set('error');
    }
  }
}
