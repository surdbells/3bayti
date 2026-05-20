import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { NgIf, isPlatformBrowser } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CheckoutStepperComponent } from './checkout-stepper';
import { CheckoutService, CheckoutStatusService } from '../../core/checkout';

/**
 * /checkout/return — payment outcome resolver.
 *
 * Landing chain:
 *   Noon hosted checkout → 302 from {API}/v3/checkout/return/{ref}
 *   (Y.3-A) → here, as /checkout/return?ref={ref}.
 *
 * What it does
 * ------------
 *   1. Read ?ref from the query string.
 *   2. Poll GET /v3/checkout/status/{ref} (via CheckoutStatusService)
 *      until terminal or the 60s ceiling.
 *   3. Branch on the outcome:
 *      - paid          → clear checkout state, navigate to
 *                        /checkout/success/{order_id}
 *      - terminal,
 *        not paid       → failure state (declined / cancelled / failed)
 *      - timed out      → "still processing" state (we couldn't
 *                        confirm in 60s; the webhook may still land)
 *      - missing ref /
 *        404 forever    → generic failure state
 *
 * Why not trust the redirect itself
 * ----------------------------------
 * Noon's redirect means "the shopper came back", not "payment
 * succeeded". The webhook is the source of truth; polling the local
 * status endpoint surfaces it. See CheckoutStatusService.
 *
 * Checkout state cleanup
 * ----------------------
 * Cleared ONLY on the paid branch (mirrors Y.2-G's success page,
 * which also clears — clearing twice is harmless and idempotent).
 * On failure/timeout we KEEP the checkout state so "Try again" lands
 * the shopper back on /checkout/review with their addresses + promo
 * intact (Q3.4).
 *
 * SSR
 * ---
 * RenderMode.Server (added in Y.3-C routing). Polling starts in
 * ngOnInit guarded by isPlatformBrowser so the server render shows
 * the neutral "confirming" state and the browser takes over on
 * hydrate.
 */
type ReturnPhase = 'confirming' | 'failed' | 'processing';

@Component({
  selector: 'app-checkout-return',
  standalone: true,
  imports: [NgIf, RouterLink, TranslatePipe, CheckoutStepperComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="checkout-page" data-testid="checkout-return-page">
      <div class="checkout-page__container">
        <h1 class="checkout-page__title">
          {{ 'checkout.return.title' | translate }}
        </h1>

        <app-checkout-stepper [activeStep]="2"></app-checkout-stepper>

        <section
          class="checkout-page__section payment-handoff"
          aria-labelledby="return-heading"
        >
          <h2 id="return-heading" class="visually-hidden">
            {{ 'checkout.return.title' | translate }}
          </h2>

          <!-- Confirming (polling in progress) -->
          <ng-container *ngIf="phase() === 'confirming'">
            <div class="payment-handoff__spinner" aria-hidden="true">
              <span class="payment-handoff__dot"></span>
              <span class="payment-handoff__dot"></span>
              <span class="payment-handoff__dot"></span>
            </div>
            <p
              class="payment-handoff__body"
              aria-live="polite"
              data-testid="return-confirming"
            >
              {{ 'checkout.return.confirming' | translate }}
            </p>
          </ng-container>

          <!-- Timed out but possibly still processing -->
          <ng-container *ngIf="phase() === 'processing'">
            <p
              class="payment-handoff__body"
              aria-live="polite"
              data-testid="return-processing"
            >
              {{ 'checkout.return.processingBody' | translate }}
            </p>
            <div class="checkout-page__actions">
              <a routerLink="/account/orders" class="checkout-page__continue">
                {{ 'checkout.return.viewOrders' | translate }}
              </a>
            </div>
          </ng-container>

          <!-- Failed / declined / cancelled -->
          <ng-container *ngIf="phase() === 'failed'">
            <div class="return-failed__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
              </svg>
            </div>
            <p
              class="payment-handoff__body"
              aria-live="polite"
              data-testid="return-failed"
            >
              {{ 'checkout.return.failedBody' | translate }}
            </p>
            <div class="checkout-page__actions">
              <a
                routerLink="/cart"
                class="checkout-page__back"
                data-testid="return-back-to-bag"
              >
                {{ 'checkout.return.backToBag' | translate }}
              </a>
              <a
                routerLink="/checkout/review"
                class="checkout-page__continue"
                data-testid="return-try-again"
              >
                {{ 'checkout.return.tryAgain' | translate }}
              </a>
            </div>
          </ng-container>
        </section>
      </div>
    </main>
  `,
  styleUrl: './checkout.scss',
})
export class CheckoutReturnPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly checkout = inject(CheckoutService);
  private readonly status = inject(CheckoutStatusService);
  private readonly platformId = inject(PLATFORM_ID);

  private readonly _phase = signal<ReturnPhase>('confirming');
  protected readonly phase = this._phase.asReadonly();

  async ngOnInit(): Promise<void> {
    if (!isPlatformBrowser(this.platformId)) {
      /* Server render shows the neutral confirming state; the browser
         hydrate runs ngOnInit again and does the real polling. */
      return;
    }

    const ref = this.route.snapshot.queryParamMap.get('ref');
    if (ref === null || ref.trim() === '') {
      /* No reference — can't confirm anything. Treat as failure. */
      this._phase.set('failed');
      return;
    }

    let result;
    try {
      result = await this.status.pollUntilTerminal(ref.trim());
    } catch {
      /* pollUntilTerminal swallows transient errors internally; a
         throw here is unexpected, but treat defensively as failure. */
      this._phase.set('failed');
      return;
    }

    /* Paid → success. Clear checkout state and route to the order. */
    if (result.status !== null && result.status.paid) {
      this.checkout.clear();
      await this.router.navigateByUrl(`/checkout/success/${result.status.order_id}`);
      return;
    }

    /* Terminal but not paid → declined/cancelled/failed. Keep
       checkout state so "Try again" works. */
    if (result.status !== null && result.status.terminal) {
      this._phase.set('failed');
      return;
    }

    /* Timed out (or never got a confirmable status). The webhook may
       still land later; tell the shopper to check their orders. */
    this._phase.set('processing');
  }
}
