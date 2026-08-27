import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { NgIf, isPlatformBrowser } from '@angular/common';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CheckoutStepperComponent } from './checkout-stepper';
import { CheckoutService } from '../../core/checkout';

/**
 * /checkout/payment, step 3 of 3.
 *
 * Handoff bridge between our checkout and Noon's hosted payment page.
 *
 * Flow
 * ----
 *   1. Pulls checkout_url + order_reference from window.history.state
 *      (set by /checkout/review on navigate()).
 *   2. Shows a brief redirecting message + the stepper at activeStep=2.
 *   3. After a 600ms beat (lets the user see the page render so the
 *      transition doesn't feel abrupt), calls window.location.assign
 *      to send them to Noon.
 *   4. Also renders an "Open payment page" button as a fallback in
 *      case auto-redirect is blocked by browser extensions or strict
 *      popup-blockers (rare with location.assign but defensive).
 *
 * Refresh-safety
 * --------------
 * If the user refreshes this page after the redirect fires, the
 * router state is gone. We then:
 *   - Show a friendly "Couldn't find your pending checkout" message
 *   - Provide a Back button to /checkout/review
 *
 * We do NOT store checkout_url in sessionStorage. The URL is short-
 * lived (Noon expires hosted-checkout URLs in 10-15 minutes per their
 * docs) and re-using a stale one would frustrate users. Better to
 * send them back to review where Place Order will re-mint a URL.
 *
 * CheckoutService cleanup
 * -----------------------
 * We DO NOT clear() CheckoutService here. The order isn't actually
 * placed until Noon confirms payment. The confirmation arrives via:
 *   Noon → 302 from {API}/v3/checkout/return/{ref} (Y.3-A) →
 *   /checkout/return?ref={ref} (Y.3-C), which polls the status
 *   endpoint and clears CheckoutService only on the paid branch.
 * If the user abandons Noon and returns to our site, their cart +
 * checkout state should still be intact.
 *
 * The completed return chain (Y.3)
 * --------------------------------
 * This page is the *handoff*. The return flow (built in Y.3) is:
 *   - {API}/v3/checkout/return/{ref} 302s the browser to the web app
 *   - /checkout/return polls /v3/checkout/status/:ref until terminal
 *   - paid → /checkout/success/:id; declined/failed → failure state
 *     with Try again / Back to bag; timeout → "still processing"
 *
 * This page just needs to get the shopper off our site to Noon
 * reliably; the return URL itself is set server-side at initiate
 * time, so this page does not construct it.
 *
 * Bounce guards
 * -------------
 *   - If no router state on landing → bounce to /checkout/review
 *     after a 300ms grace period (so refreshes during the redirect
 *     beat don't double-bounce in an ugly way).
 *
 * a11y
 * ----
 *   - aria-live="polite" on the status text so screen readers
 *     announce the redirect
 *   - Manual "Open payment page" anchor is focusable + visible to
 *     keyboard users
 */
@Component({
  selector: 'app-checkout-payment',
  standalone: true,
  imports: [NgIf, TranslatePipe, CheckoutStepperComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="checkout-page" data-testid="checkout-payment-page">
      <div class="checkout-page__container">
        <h1 class="checkout-page__title">
          {{ 'checkout.payment.title' | translate }}
        </h1>

        <app-checkout-stepper [activeStep]="2"></app-checkout-stepper>

        <section
          class="checkout-page__section payment-handoff"
          aria-labelledby="payment-heading"
        >
          <h2 id="payment-heading" class="visually-hidden">
            {{ 'checkout.payment.title' | translate }}
          </h2>

          <ng-container *ngIf="checkoutUrl() !== null; else missingState">
            <div class="payment-handoff__spinner" aria-hidden="true">
              <span class="payment-handoff__dot"></span>
              <span class="payment-handoff__dot"></span>
              <span class="payment-handoff__dot"></span>
            </div>

            <p
              class="payment-handoff__body"
              aria-live="polite"
              data-testid="payment-status"
            >
              {{ 'checkout.payment.body' | translate }}
            </p>

            <p
              *ngIf="orderReference() !== null"
              class="payment-handoff__ref"
              data-testid="payment-order-ref"
            >
              {{ orderReference() }}
            </p>

            <a
              [href]="checkoutUrl()"
              class="payment-handoff__manual"
              data-testid="payment-manual-link"
            >
              {{ 'checkout.payment.openManual' | translate }}
            </a>
          </ng-container>

          <ng-template #missingState>
            <p class="payment-handoff__missing" data-testid="payment-missing-state">
              {{ 'checkout.payment.body' | translate }}
            </p>
            <button
              type="button"
              class="checkout-page__continue"
              (click)="onBackToReview()"
              data-testid="payment-back-to-review"
            >
              {{ 'checkout.payment.backToReview' | translate }}
            </button>
          </ng-template>
        </section>
      </div>
    </main>
  `,
  styleUrl: './checkout.scss',
})
export class CheckoutPaymentPageComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly checkout = inject(CheckoutService);
  private readonly platformId = inject(PLATFORM_ID);

  private readonly _checkoutUrl = signal<string | null>(null);
  protected readonly checkoutUrl = this._checkoutUrl.asReadonly();

  private readonly _orderRef = signal<string | null>(null);
  protected readonly orderReference = this._orderRef.asReadonly();

  /** How long to display the handoff UI before redirecting.
   *  Exposed as a protected member so tests can drive it. */
  protected readonly REDIRECT_DELAY_MS = 600;

  /** How long to wait before bouncing when no router state exists. */
  protected readonly MISSING_STATE_BOUNCE_MS = 300;

  ngOnInit(): void {
    if (!isPlatformBrowser(this.platformId)) {
      /* SSR, nothing to do; the browser hydrate will run ngOnInit
         again. */
      return;
    }

    /* Read router state set by /checkout/review's navigate() call. */
    const state = (history.state ?? {}) as {
      checkout_url?: string;
      order_reference?: string;
    };
    const url = typeof state.checkout_url === 'string' ? state.checkout_url : null;
    const ref = typeof state.order_reference === 'string' ? state.order_reference : null;

    if (url === null) {
      /* No state, likely a refresh or direct navigation. Bounce
         back to review after a small grace period so the user sees
         a transition, not an instant flash. */
      window.setTimeout(() => {
        void this.router.navigateByUrl('/checkout/review');
      }, this.MISSING_STATE_BOUNCE_MS);
      return;
    }

    this._checkoutUrl.set(url);
    this._orderRef.set(ref);

    /* Defer the redirect by a brief beat so the user perceives the
       transition. window.location.assign is preferred over .href
       because it lands in browser history correctly (the user can
       use the back button to return). The redirect is extracted to
       a protected method so tests can stub it without monkey-patching
       window.location (which is non-configurable in jsdom). */
    window.setTimeout(() => {
      this.redirectTo(url);
    }, this.REDIRECT_DELAY_MS);
  }

  protected async onBackToReview(): Promise<void> {
    await this.router.navigateByUrl('/checkout/review');
  }

  /** Override-point for tests; production path calls window.location.assign. */
  protected redirectTo(url: string): void {
    try {
      window.location.assign(url);
    } catch {
      /* If the assign throws (e.g. blocked URL scheme), the manual
         link still works. No toast, the UI already exposes the
         fallback. */
    }
  }
}
