import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf } from '@angular/common';
import {
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { TranslatePipe } from '@ngx-translate/core';

import { SeoService } from '../../core/seo/seo.service';
import { VENDOR_APP_URL } from '../../core/auth/auth.tokens';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import {
  FormFieldComponent,
  PhoneInputComponent,
  PHONE_COUNTRIES,
  mapApiErrors,
  ToastService,
  ApiErrorMapping,
} from '../../shared/forms';

interface SellFeature {
  icon: 'storefront' | 'payments' | 'logistics' | 'analytics' | 'marketing' | 'trust';
  key: string;
}

/** Response shape of POST /v3/vendor-applications (raw, no envelope). */
interface VendorApplicationResponse {
  application: { id: number; status: string };
  /** true when the email already had a still-pending application. */
  already_submitted?: boolean;
}

/**
 * /sell — the vendor recruitment pitch (Phase F, #13 + #6).
 *
 * Public marketing page. Keeps the storefront shopper-first while giving
 * prospective sellers a full pitch.
 *
 * APPLY-TO-SELL flow
 * ------------------
 * Self-serve vendor signup on the external app is gone — the ONLY door
 * into the marketplace is an application that an admin reviews. The hero
 * + final CTAs now scroll to an inline "Apply to sell" form (#sell-apply)
 * which POSTs to the PUBLIC endpoint 'POST /vendor-applications'
 * (-> /v3/vendor-applications) via RoutedHttpClient. On success we show a
 * "we'll review and email you" confirmation. The duplicate-pending case
 * (already_submitted) reuses the same success state with softer copy.
 *
 * The "Sign in" CTA still points at the portal (VENDOR_APP_URL) for
 * vendors who are ALREADY approved.
 *
 * Rebuilt in the web design system (brand tokens + gilded styling).
 */
@Component({
  selector: 'app-sell',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgIf,
    ReactiveFormsModule,
    TranslatePipe,
    FormFieldComponent,
    PhoneInputComponent,
  ],
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
              href="#sell-apply"
              class="sell-btn sell-btn--primary"
              data-testid="sell-hero-apply"
              (click)="focusForm($event)"
            >
              {{ 'sell.hero.startSelling' | translate }}
            </a>
            <a
              [href]="signInUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="sell-btn sell-btn--ghost"
              data-testid="sell-hero-signin"
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
              <dt class="sell-hero__stat-value">{{ 'sell.hero.stats.signupValue' | translate }}</dt>
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

      <!-- Apply to sell -->
      <section class="sell-apply" id="sell-apply">
        <div class="sell-apply__inner">
          <header class="sell-section__header">
            <p class="sell-section__eyebrow">{{ 'sell.apply.eyebrow' | translate }}</p>
            <h2 class="sell-section__title">{{ 'sell.apply.title' | translate }}</h2>
            <p class="sell-section__subtitle">{{ 'sell.apply.subtitle' | translate }}</p>
          </header>

          <!-- Success state -->
          <div
            *ngIf="submitted()"
            class="sell-apply__success"
            role="status"
            aria-live="polite"
            data-testid="sell-apply-success"
          >
            <span class="sell-apply__success-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9" /><path d="M8.5 12.5l2.5 2.5 4.5-5" />
              </svg>
            </span>
            <h3 class="sell-apply__success-title">
              {{ (alreadyApplied() ? 'sell.apply.success.alreadyTitle' : 'sell.apply.success.title') | translate }}
            </h3>
            <p class="sell-apply__success-text">
              {{ (alreadyApplied() ? 'sell.apply.success.alreadyText' : 'sell.apply.success.text') | translate }}
            </p>
          </div>

          <!-- Form -->
          <form
            *ngIf="!submitted()"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
            class="sell-apply__form"
            data-testid="sell-apply-form"
          >
            <div class="sell-apply__row">
              <ui-form-field
                [label]="'sell.apply.fields.firstName'"
                fieldId="sell-first-name"
                [required]="true"
                [control]="form.controls.first_name"
                [errorMap]="requiredErrors"
              >
                <input
                  id="sell-first-name"
                  type="text"
                  autocomplete="given-name"
                  formControlName="first_name"
                  class="sell-input"
                  data-testid="sell-first-name"
                />
              </ui-form-field>

              <ui-form-field
                [label]="'sell.apply.fields.lastName'"
                fieldId="sell-last-name"
                [required]="true"
                [control]="form.controls.last_name"
                [errorMap]="requiredErrors"
              >
                <input
                  id="sell-last-name"
                  type="text"
                  autocomplete="family-name"
                  formControlName="last_name"
                  class="sell-input"
                  data-testid="sell-last-name"
                />
              </ui-form-field>
            </div>

            <ui-form-field
              [label]="'sell.apply.fields.email'"
              fieldId="sell-email"
              [required]="true"
              [control]="form.controls.email"
              [errorMap]="emailErrors"
            >
              <input
                id="sell-email"
                type="email"
                autocomplete="email"
                inputmode="email"
                spellcheck="false"
                formControlName="email"
                class="sell-input"
                data-testid="sell-email"
              />
            </ui-form-field>

            <ui-form-field
              [label]="'sell.apply.fields.phone'"
              fieldId="sell-phone"
              [required]="true"
              [helper]="'sell.apply.fields.phoneHint'"
              [control]="form.controls.phone"
              [errorMap]="phoneErrors"
            >
              <ui-phone-input
                inputId="sell-phone"
                formControlName="phone"
                data-testid="sell-phone"
              ></ui-phone-input>
            </ui-form-field>

            <ui-form-field
              [label]="'sell.apply.fields.businessName'"
              fieldId="sell-business-name"
              [required]="true"
              [control]="form.controls.business_name"
              [errorMap]="requiredErrors"
            >
              <input
                id="sell-business-name"
                type="text"
                autocomplete="organization"
                formControlName="business_name"
                class="sell-input"
                data-testid="sell-business-name"
              />
            </ui-form-field>

            <ui-form-field
              [label]="'sell.apply.fields.licenseNumber'"
              fieldId="sell-license-number"
              [helper]="'sell.apply.fields.licenseHint'"
              [control]="form.controls.license_number"
              [errorMap]="requiredErrors"
            >
              <input
                id="sell-license-number"
                type="text"
                formControlName="license_number"
                class="sell-input"
                data-testid="sell-license-number"
              />
            </ui-form-field>

            <ui-form-field
              [label]="'sell.apply.fields.message'"
              fieldId="sell-message"
              [helper]="'sell.apply.fields.messageHint'"
              [control]="form.controls.message"
              [errorMap]="requiredErrors"
            >
              <textarea
                id="sell-message"
                rows="4"
                formControlName="message"
                class="sell-input sell-input--textarea"
                data-testid="sell-message"
              ></textarea>
            </ui-form-field>

            <button
              type="submit"
              class="sell-btn sell-btn--primary sell-btn--lg sell-apply__submit"
              [disabled]="submitting()"
              data-testid="sell-apply-submit"
            >
              <span
                *ngIf="submitting()"
                class="sell-apply__spinner"
                aria-hidden="true"
              ></span>
              {{ (submitting() ? 'sell.apply.submitting' : 'sell.apply.submit') | translate }}
            </button>

            <p class="sell-apply__signin">
              {{ 'sell.apply.alreadyVendor' | translate }}
              <a
                [href]="signInUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="sell-apply__signin-link"
                data-testid="sell-apply-signin"
              >
                {{ 'sell.hero.signIn' | translate }}
              </a>
            </p>
          </form>
        </div>
      </section>

      <!-- Final CTA -->
      <section class="sell-cta">
        <div class="sell-cta__inner">
          <h2 class="sell-cta__title">{{ 'sell.cta.title' | translate }}</h2>
          <p class="sell-cta__text">{{ 'sell.cta.text' | translate }}</p>
          <a
            href="#sell-apply"
            class="sell-btn sell-btn--primary sell-btn--lg"
            data-testid="sell-cta-apply"
            (click)="focusForm($event)"
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
  private readonly http = inject(RoutedHttpClient);
  private readonly toast = inject(ToastService);

  /** External seller-app sign-in for ALREADY-approved vendors. */
  protected readonly signInUrl = this.vendorAppUrl;

  protected readonly submitting = signal(false);
  protected readonly submitted = signal(false);
  /** True when the success state is the idempotent "already pending" echo. */
  protected readonly alreadyApplied = signal(false);

  protected readonly form: FormGroup<{
    first_name: FormControl<string>;
    last_name: FormControl<string>;
    email: FormControl<string>;
    phone: FormControl<string>;
    business_name: FormControl<string>;
    license_number: FormControl<string>;
    message: FormControl<string>;
  }>;

  protected readonly requiredErrors: Record<string, string> = {
    required: 'sell.apply.errors.required',
    apiValidation: 'sell.apply.errors.invalid',
  };

  protected readonly emailErrors: Record<string, string> = {
    required: 'sell.apply.errors.required',
    email: 'sell.apply.errors.emailInvalid',
    apiValidation: 'sell.apply.errors.emailInvalid',
  };

  protected readonly phoneErrors: Record<string, string> = {
    required: 'sell.apply.errors.required',
    phoneInvalid: 'sell.apply.errors.phoneInvalid',
    apiValidation: 'sell.apply.errors.phoneInvalid',
  };

  protected readonly features: readonly SellFeature[] = [
    { icon: 'storefront', key: 'storefront' },
    { icon: 'payments', key: 'payments' },
    { icon: 'logistics', key: 'logistics' },
    { icon: 'analytics', key: 'analytics' },
    { icon: 'marketing', key: 'marketing' },
    { icon: 'trust', key: 'trust' },
  ];

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      first_name: fb.control('', [Validators.required, Validators.maxLength(100)]),
      last_name: fb.control('', [Validators.required, Validators.maxLength(100)]),
      email: fb.control('', [Validators.required, Validators.email, Validators.maxLength(255)]),
      phone: fb.control('', [Validators.required]),
      business_name: fb.control('', [Validators.required, Validators.maxLength(255)]),
      license_number: fb.control('', [Validators.maxLength(100)]),
      message: fb.control('', [Validators.maxLength(2000)]),
    });
  }

  ngOnInit(): void {
    this.seo.set({
      title: 'Sell on 3bayti — apply to open your store',
      description:
        'Apply to sell on 3bayti. Tell us about your business and we will review your ' +
        'application and email you. A beautiful storefront, secure payments and daily ' +
        'payouts, integrated logistics, and the marketing tools to grow — all from one ' +
        'dashboard.',
    });
  }

  /**
   * Smooth-scroll the in-page anchor to the form, then move focus to the
   * first field for keyboard + screen-reader users. preventDefault keeps
   * the URL clean and avoids a jarring jump on browsers that honour the
   * fragment instantly.
   */
  protected focusForm(event: Event): void {
    event.preventDefault();
    if (typeof document === 'undefined') return;
    const section = document.getElementById('sell-apply');
    section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const first = document.getElementById('sell-first-name');
    if (first instanceof HTMLElement) {
      /* Defer focus until after the smooth scroll begins so the browser
         doesn't fight the scroll with a focus-jump. */
      setTimeout(() => first.focus({ preventScroll: true }), 350);
    }
  }

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    const raw = this.form.getRawValue();
    const phone = raw.phone.trim();

    this.submitting.set(true);
    try {
      const env = await firstValueFrom(
        this.http.post<VendorApplicationResponse>('POST /vendor-applications', {
          body: {
            first_name: raw.first_name.trim(),
            last_name: raw.last_name.trim(),
            email: raw.email.trim(),
            phone,
            country_code: deriveCountryCode(phone),
            business_name: raw.business_name.trim(),
            license_number: blankToNull(raw.license_number),
            message: blankToNull(raw.message),
          },
        }),
      );
      this.alreadyApplied.set(env.data.already_submitted === true);
      this.submitted.set(true);
    } catch (err) {
      this.surfaceError(err);
    } finally {
      this.submitting.set(false);
    }
  }

  /**
   * Map server errors. Per-field VALIDATION_FAILED lands inline (via
   * mapApiErrors); a 429 throttle gets a dedicated toast; everything else
   * (network, 5xx) gets a generic "try again" toast.
   */
  private surfaceError(err: unknown): void {
    if (err instanceof HttpErrorResponse && err.status === 429) {
      this.toast.error('sell.apply.errors.rateLimited');
      return;
    }
    const result = mapApiErrors(err, this.form, SELL_APPLY_ERROR_MAP);
    if (result.isNetworkError) {
      this.toast.error('sell.apply.errors.network');
      return;
    }
    if (result.unmapped.length > 0) {
      this.toast.error('sell.apply.errors.unexpected');
    }
    /* per-field VALIDATION_FAILED already rendered inline — no toast. */
  }
}

/** Empty/whitespace string → null (so optional fields omit cleanly). */
function blankToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * ISO 3166-1 alpha-2 from an E.164 number; longest-prefix match, UAE
 * default. Mirrors register.ts — keeps the page from reaching into
 * PhoneInputComponent internals.
 */
function deriveCountryCode(e164: string): string {
  const sorted = [...PHONE_COUNTRIES].sort((a, b) => b.dialCode.length - a.dialCode.length);
  for (const country of sorted) {
    if (e164.startsWith(country.dialCode)) {
      return country.code;
    }
  }
  return 'AE';
}

/**
 * The public submit endpoint mostly returns VALIDATION_FAILED (mapped
 * inline by mapApiErrors) or 429 (handled before this map). This map is
 * the catch-all for any future single-code conflicts; field:null routes
 * them to the generic toast.
 */
const SELL_APPLY_ERROR_MAP: Record<string, ApiErrorMapping> = {};
