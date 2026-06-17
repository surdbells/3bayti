import {
  Component,
  ChangeDetectionStrategy,
  inject,
  OnInit,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

import { SeoService } from '../../core/seo/seo.service';
import { VENDOR_APP_URL } from '../../core/auth/auth.tokens';

interface SellFeature {
  icon: 'storefront' | 'payments' | 'logistics' | 'analytics' | 'marketing' | 'trust';
  key: string;
}

/**
 * /sell — the vendor recruitment pitch (Phase F, #13 + #6).
 *
 * Public marketing page. Keeps the storefront shopper-first while giving
 * prospective sellers a full pitch. Seller CTAs target the EXTERNAL
 * seller app (VENDOR_APP_URL = https://app.3bayti.ae) — note the on-site
 * /register is *customer* signup, so it is deliberately not used here.
 *
 * Rebuilt in the web design system (brand tokens + gilded styling); the
 * uploaded ax-* reference (markup + dashboard mockup) is not imported.
 */
@Component({
  selector: 'app-sell',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe],
  template: `
    <main class="sell" data-testid="sell-page">
      <!-- Hero -->
      <section class="sell-hero">
        <div class="sell-hero__inner">
          <p class="sell-hero__eyebrow">{{ 'sell.hero.eyebrow' | translate }}</p>
          <h1 class="sell-hero__title">{{ 'sell.hero.title' | translate }}</h1>
          <p class="sell-hero__lead">{{ 'sell.hero.lead' | translate }}</p>

          <div class="sell-hero__actions">
            <a
              [href]="registerUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="sell-btn sell-btn--primary"
              data-testid="sell-hero-register"
            >
              {{ 'sell.hero.startSelling' | translate }}
            </a>
            <a
              [href]="signInUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="sell-btn sell-btn--ghost"
            >
              {{ 'sell.hero.signIn' | translate }}
            </a>
          </div>

          <ul class="sell-hero__trust" role="list">
            <li>{{ 'sell.hero.trust.noSetup' | translate }}</li>
            <li>{{ 'sell.hero.trust.securePay' | translate }}</li>
            <li>{{ 'sell.hero.trust.goLive' | translate }}</li>
          </ul>

          <dl class="sell-hero__stats">
            <div>
              <dt class="sell-hero__stat-value">5,000+</dt>
              <dd class="sell-hero__stat-label">{{ 'sell.hero.stats.shoppersLabel' | translate }}</dd>
            </div>
            <div>
              <dt class="sell-hero__stat-value">3 min</dt>
              <dd class="sell-hero__stat-label">{{ 'sell.hero.stats.signupLabel' | translate }}</dd>
            </div>
            <div>
              <dt class="sell-hero__stat-value">99.9%</dt>
              <dd class="sell-hero__stat-label">{{ 'sell.hero.stats.uptimeLabel' | translate }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <!-- Why sell -->
      <section class="sell-section">
        <header class="sell-section__header">
          <p class="sell-section__eyebrow">{{ 'sell.why.eyebrow' | translate }}</p>
          <h2 class="sell-section__title">{{ 'sell.why.title' | translate }}</h2>
          <p class="sell-section__subtitle">{{ 'sell.why.subtitle' | translate }}</p>
        </header>

        <ul class="sell-features" role="list">
          @for (f of features; track f.key) {
            <li class="sell-feature">
              <span class="sell-feature__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  @switch (f.icon) {
                    @case ('storefront') {
                      <path d="M3.5 9 5 4h14l1.5 5M4.5 9v10a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1V9M3.5 9h17M10 20v-5h4v5" />
                    }
                    @case ('payments') {
                      <rect x="3" y="6" width="18" height="12" rx="2" /><path d="M3 10h18M7 15h4" />
                    }
                    @case ('logistics') {
                      <path d="M3 7h11v8H3zM14 10h4l3 3v2h-7" /><circle cx="7" cy="17.5" r="1.6" /><circle cx="17.5" cy="17.5" r="1.6" />
                    }
                    @case ('analytics') {
                      <path d="M4 4v16h16" /><path d="M8 15l3-4 3 2.5 4-6.5" />
                    }
                    @case ('marketing') {
                      <path d="M4 10v4a1 1 0 0 0 1 1h2l4 4V5L7 9H5a1 1 0 0 0-1 1Z" /><path d="M16 9a4 4 0 0 1 0 6" />
                    }
                    @case ('trust') {
                      <path d="M12 3l8 3v6c0 4-3 7-8 9-5-2-8-5-8-9V6l8-3Z" /><path d="M9 12l2 2 4-4" />
                    }
                  }
                </svg>
              </span>
              <h3 class="sell-feature__title">{{ ('sell.why.features.' + f.key + '.title') | translate }}</h3>
              <p class="sell-feature__desc">{{ ('sell.why.features.' + f.key + '.desc') | translate }}</p>
            </li>
          }
        </ul>
      </section>

      <!-- How it works -->
      <section class="sell-section sell-section--alt">
        <header class="sell-section__header">
          <p class="sell-section__eyebrow">{{ 'sell.steps.eyebrow' | translate }}</p>
          <h2 class="sell-section__title">{{ 'sell.steps.title' | translate }}</h2>
        </header>

        <ol class="sell-steps">
          <li class="sell-step">
            <span class="sell-step__num" aria-hidden="true">1</span>
            <h3 class="sell-step__title">{{ 'sell.steps.one.title' | translate }}</h3>
            <p class="sell-step__desc">{{ 'sell.steps.one.desc' | translate }}</p>
          </li>
          <li class="sell-step">
            <span class="sell-step__num" aria-hidden="true">2</span>
            <h3 class="sell-step__title">{{ 'sell.steps.two.title' | translate }}</h3>
            <p class="sell-step__desc">{{ 'sell.steps.two.desc' | translate }}</p>
          </li>
          <li class="sell-step">
            <span class="sell-step__num" aria-hidden="true">3</span>
            <h3 class="sell-step__title">{{ 'sell.steps.three.title' | translate }}</h3>
            <p class="sell-step__desc">{{ 'sell.steps.three.desc' | translate }}</p>
          </li>
        </ol>
      </section>

      <!-- Final CTA -->
      <section class="sell-cta">
        <div class="sell-cta__inner">
          <h2 class="sell-cta__title">{{ 'sell.cta.title' | translate }}</h2>
          <p class="sell-cta__text">{{ 'sell.cta.text' | translate }}</p>
          <a
            [href]="registerUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="sell-btn sell-btn--primary sell-btn--lg"
            data-testid="sell-cta-register"
          >
            {{ 'sell.cta.register' | translate }}
          </a>
        </div>
      </section>
    </main>
  `,
  styleUrl: './sell-page.scss',
})
export class SellPageComponent implements OnInit {
  private readonly seo = inject(SeoService);
  private readonly vendorAppUrl = inject(VENDOR_APP_URL);

  /** External seller-app register + sign-in (NOT the on-site customer routes). */
  protected readonly registerUrl = `${this.vendorAppUrl}/register`;
  protected readonly signInUrl = this.vendorAppUrl;

  protected readonly features: readonly SellFeature[] = [
    { icon: 'storefront', key: 'storefront' },
    { icon: 'payments', key: 'payments' },
    { icon: 'logistics', key: 'logistics' },
    { icon: 'analytics', key: 'analytics' },
    { icon: 'marketing', key: 'marketing' },
    { icon: 'trust', key: 'trust' },
  ];

  ngOnInit(): void {
    this.seo.set({
      title: 'Sell on 3bayti — open your store',
      description:
        'Sell locally, ship globally. Open a 3bayti store: a beautiful storefront, ' +
        'secure payments and daily payouts, integrated logistics, and the marketing ' +
        'tools to grow — all from one dashboard.',
    });
  }
}
