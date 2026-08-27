import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { DecimalPipe, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslatePipe } from '@ngx-translate/core';

import { GiftCardVisualComponent } from './gift-card-visual';
import { GiftCardService } from './gift-card.service';
import { SeoService } from '../../core/seo/seo.service';
import type { GiftCard } from './gift-card.model';

type CheckState = 'idle' | 'checking' | 'found' | 'notfound' | 'error';
type RedeemError = 'none' | 'notRedeemable' | 'notfound' | 'error';

/**
 * /gift-cards/redeem, check a gift card's balance (public) and add it
 * to the signed-in account (auth).
 *
 *   - Check:  GET  /v3/gift-cards/balance?code=  (public, minimal shape)
 *   - Redeem: POST /v3/gift-cards/redeem         (auth → 401 to /login)
 *
 * Balance check is public so anyone can verify a code before signing in.
 * Redeem requires auth; a 401 routes to /login with a returnUrl back
 * here. On success the card is assigned to the buyer and appears under
 * /account/gift-cards, where we navigate.
 */
@Component({
  selector: 'app-gift-card-redeem',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, TranslatePipe, DecimalPipe, DatePipe, GiftCardVisualComponent],
  template: `
    <main class="gcr" data-testid="gift-card-redeem-page">
      <div class="gcr__container">
        <header class="gcr__header">
          <h1 class="gcr__title">{{ 'giftCards.redeem.title' | translate }}</h1>
          <p class="gcr__subtitle">{{ 'giftCards.redeem.subtitle' | translate }}</p>
        </header>

        <form class="gcr__form" (ngSubmit)="check()" novalidate>
          <label class="gcr__label" for="gcr-code">{{ 'giftCards.redeem.codeLabel' | translate }}</label>
          <div class="gcr__field">
            <input
              id="gcr-code"
              type="text"
              class="gcr__input"
              autocomplete="off"
              autocapitalize="characters"
              spellcheck="false"
              name="code"
              [ngModel]="code()"
              (ngModelChange)="onCodeInput($event)"
              [attr.placeholder]="'giftCards.redeem.codePlaceholder' | translate"
            />
            <button type="submit" class="gcr__check" [disabled]="!canCheck()">
              {{ (checkState() === 'checking' ? 'giftCards.redeem.checking' : 'giftCards.redeem.check') | translate }}
            </button>
          </div>
        </form>

        @switch (checkState()) {
          @case ('notfound') {
            <p class="gcr__msg gcr__msg--error" role="alert">{{ 'giftCards.redeem.notFound' | translate }}</p>
          }
          @case ('error') {
            <p class="gcr__msg gcr__msg--error" role="alert">{{ 'giftCards.redeem.checkError' | translate }}</p>
          }
          @case ('found') {
            <div class="gcr__result">
              <div class="gcr__card">
                <ui-gift-card
                  [theme]="result()!.theme"
                  [amount]="result()!.balance"
                  [status]="result()!.status"
                  [code]="result()!.code"
                />
              </div>

              <div class="gcr__details">
                <div class="gcr__row">
                  <span class="gcr__row-label">{{ 'giftCards.redeem.balanceLabel' | translate }}</span>
                  <span class="gcr__row-value">{{ result()!.currency }} {{ +result()!.balance | number:'1.2-2' }}</span>
                </div>
                @if (result()!.expires_at) {
                  <div class="gcr__row">
                    <span class="gcr__row-label">{{ 'giftCards.redeem.expiresLabel' | translate }}</span>
                    <span class="gcr__row-value">{{ result()!.expires_at | date:'mediumDate' }}</span>
                  </div>
                }

                @if (result()!.is_spendable) {
                  <button type="button" class="gcr__redeem" [disabled]="redeemState() === 'redeeming'" (click)="redeem()">
                    {{ (redeemState() === 'redeeming' ? 'giftCards.redeem.redeeming' : 'giftCards.redeem.redeem') | translate }}
                  </button>
                } @else {
                  <p class="gcr__msg gcr__msg--note">{{ 'giftCards.redeem.notSpendable' | translate }}</p>
                }

                @switch (redeemError()) {
                  @case ('notRedeemable') {
                    <p class="gcr__msg gcr__msg--error" role="alert">{{ 'giftCards.redeem.notRedeemable' | translate }}</p>
                  }
                  @case ('notfound') {
                    <p class="gcr__msg gcr__msg--error" role="alert">{{ 'giftCards.redeem.notFound' | translate }}</p>
                  }
                  @case ('error') {
                    <p class="gcr__msg gcr__msg--error" role="alert">{{ 'giftCards.redeem.redeemError' | translate }}</p>
                  }
                }
              </div>
            </div>
          }
        }
      </div>
    </main>
  `,
  styleUrl: './gift-card-redeem-page.scss',
})
export class GiftCardRedeemPageComponent implements OnInit {
  private readonly gift = inject(GiftCardService);
  private readonly seo = inject(SeoService);
  private readonly router = inject(Router);

  protected readonly code = signal<string>('');
  protected readonly checkState = signal<CheckState>('idle');
  protected readonly result = signal<GiftCard | null>(null);
  protected readonly redeemState = signal<'idle' | 'redeeming'>('idle');
  protected readonly redeemError = signal<RedeemError>('none');

  protected readonly trimmedCode = computed<string>(() => this.code().trim());
  protected readonly canCheck = computed<boolean>(
    () => this.trimmedCode().length > 0 && this.checkState() !== 'checking',
  );

  ngOnInit(): void {
    this.seo.set({
      title: 'Redeem a Gift Card · 3bayti',
      description: 'Check the balance of a 3bayti gift card or add it to your account.',
    });
  }

  protected onCodeInput(value: string): void {
    this.code.set(value ?? '');
    /* Any code change invalidates a prior lookup. */
    if (this.checkState() !== 'idle') {
      this.checkState.set('idle');
      this.result.set(null);
      this.redeemError.set('none');
    }
  }

  async check(): Promise<void> {
    if (!this.canCheck()) return;
    this.checkState.set('checking');
    this.result.set(null);
    this.redeemError.set('none');
    try {
      const card = await this.gift.checkBalance(this.trimmedCode());
      this.result.set(card);
      this.checkState.set('found');
    } catch (err) {
      this.checkState.set(this.isStatus(err, 404) ? 'notfound' : 'error');
    }
  }

  async redeem(): Promise<void> {
    if (this.redeemState() === 'redeeming') return;
    this.redeemError.set('none');
    this.redeemState.set('redeeming');
    try {
      await this.gift.redeem(this.trimmedCode());
      await this.router.navigateByUrl('/account/gift-cards');
    } catch (err) {
      this.redeemState.set('idle');
      if (this.isStatus(err, 401)) {
        await this.router.navigateByUrl('/login?returnUrl=/gift-cards/redeem');
        return;
      }
      if (this.isStatus(err, 404)) {
        this.redeemError.set('notfound');
        return;
      }
      if (this.isStatus(err, 400) || this.isStatus(err, 422)) {
        this.redeemError.set('notRedeemable');
        return;
      }
      this.redeemError.set('error');
    }
  }

  private isStatus(err: unknown, status: number): boolean {
    return err instanceof HttpErrorResponse && err.status === status;
  }
}
