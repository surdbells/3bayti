import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { RouterLink } from '@angular/router';
import {
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { TranslatePipe } from '@ngx-translate/core';

import { SeoService } from '../../core/seo/seo.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';

/** Response shape of POST /v3/account/deletion-request (v3-envelope). */
interface DeletionRequestResponse {
  status: string;
}

/**
 * /delete-account — PUBLIC account-deletion information + request form
 * (Google Play "account deletion" compliance).
 *
 * MUST be reachable WITHOUT signing in — the whole point is to give a
 * deletion path to people who cannot sign in (forgot password) or who
 * signed up with Google/Apple. No guard on the route.
 *
 * The page explains what deletion does and offers three routes:
 *   (a) mobile app: Settings -> Delete Account;
 *   (b) web, signed in: routerLink to /account/delete (authed self-serve);
 *   (c) can't sign in: the request form below, which POSTs the friendly
 *       routing key 'POST /account/deletion-request' (-> /v3/account/
 *       deletion-request) via RoutedHttpClient.
 *
 * The long explanatory BODY is inline English; only the form
 * labels/buttons/success/error strings are i18n keys.
 *
 * Indexable (SeoService default robots) so app-store reviewers can find
 * it from a public URL.
 */
@Component({
  selector: 'app-delete-account',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgIf, RouterLink, ReactiveFormsModule, TranslatePipe],
  template: `
    <main class="legal-doc" data-testid="delete-account-page">
      <article class="legal-doc__container">
        <header class="legal-doc__header">
          <h1 class="legal-doc__title">Delete your account</h1>
        </header>

        <section class="legal-doc__section">
          <h2>What deleting your account does</h2>
          <p>
            When your 3bayti account is deleted, it is deactivated
            immediately, so you can no longer sign in. All of your active
            sessions are ended and your profile is removed from active use.
          </p>
          <p>
            Your order and transaction records are retained to meet our
            legal and tax obligations for a retention period, after which
            they are deleted or anonymised.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>How to delete your account</h2>
          <ul>
            <li>
              <strong>In the 3bayti mobile app:</strong> open
              <em>Settings &rarr; Delete Account</em> and follow the
              prompts.
            </li>
            <li>
              <strong>On the web, while signed in:</strong> go to your
              account’s
              <a routerLink="/account/delete" data-testid="delete-account-selfserve">
                delete account
              </a>
              page to remove your account yourself.
            </li>
            <li>
              <strong>If you cannot sign in</strong> — or you signed up
              with Google or Apple — use the request form below and we
              will delete your account for you after verifying your
              identity.
            </li>
          </ul>
        </section>

        <section class="legal-doc__section legal-doc__form-section">
          <h2>Request account deletion</h2>

          <!-- Success state -->
          <div
            *ngIf="submitted()"
            class="legal-form__success"
            role="status"
            aria-live="polite"
            data-testid="delete-request-success"
          >
            <h3 class="legal-form__success-title">
              {{ 'legal.deleteAccount.form.successTitle' | translate }}
            </h3>
            <p class="legal-form__success-text">
              {{ 'legal.deleteAccount.form.successBody' | translate }}
            </p>
          </div>

          <!-- Request form -->
          <form
            *ngIf="!submitted()"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
            class="legal-form"
            data-testid="delete-request-form"
          >
            <div
              *ngIf="failed()"
              class="legal-form__error"
              role="alert"
              data-testid="delete-request-error"
            >
              {{ 'legal.deleteAccount.form.errors.generic' | translate }}
            </div>

            <div class="legal-form__field">
              <label for="del-req-email" class="legal-form__label">
                {{ 'legal.deleteAccount.form.emailLabel' | translate }}
              </label>
              <input
                id="del-req-email"
                type="email"
                autocomplete="email"
                inputmode="email"
                spellcheck="false"
                formControlName="email"
                class="legal-form__input"
                [placeholder]="'legal.deleteAccount.form.emailPlaceholder' | translate"
                data-testid="delete-request-email"
              />
              <p
                *ngIf="form.controls.email.touched && form.controls.email.invalid"
                class="legal-form__field-error"
                data-testid="delete-request-email-error"
              >
                {{ 'legal.deleteAccount.form.errors.emailInvalid' | translate }}
              </p>
            </div>

            <div class="legal-form__field">
              <label for="del-req-reason" class="legal-form__label">
                {{ 'legal.deleteAccount.form.reasonLabel' | translate }}
              </label>
              <textarea
                id="del-req-reason"
                rows="4"
                formControlName="reason"
                class="legal-form__input legal-form__input--textarea"
                [placeholder]="'legal.deleteAccount.form.reasonPlaceholder' | translate"
                data-testid="delete-request-reason"
              ></textarea>
            </div>

            <button
              type="submit"
              class="legal-form__submit"
              [disabled]="submitting()"
              data-testid="delete-request-submit"
            >
              {{ (submitting()
                    ? 'legal.deleteAccount.form.submitting'
                    : 'legal.deleteAccount.form.submit') | translate }}
            </button>
          </form>
        </section>
      </article>
    </main>
  `,
  styleUrl: './delete-account-page.scss',
})
export class DeleteAccountPageComponent implements OnInit {
  private readonly seo = inject(SeoService);
  private readonly http = inject(RoutedHttpClient);

  protected readonly submitting = signal(false);
  protected readonly submitted = signal(false);
  protected readonly failed = signal(false);

  protected readonly form: FormGroup<{
    email: FormControl<string>;
    reason: FormControl<string>;
  }>;

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      email: fb.control('', [Validators.required, Validators.email, Validators.maxLength(255)]),
      reason: fb.control('', [Validators.maxLength(2000)]),
    });
  }

  ngOnInit(): void {
    this.seo.set({
      title: 'Delete your account',
      description:
        'Delete your 3bayti account and data. Learn what account deletion ' +
        'does and request deletion here if you cannot sign in or signed up ' +
        'with Google or Apple.',
    });
  }

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    const raw = this.form.getRawValue();
    const reason = raw.reason.trim();

    this.failed.set(false);
    this.submitting.set(true);
    try {
      await firstValueFrom(
        this.http.post<DeletionRequestResponse>('POST /account/deletion-request', {
          body: {
            email: raw.email.trim(),
            reason: reason === '' ? null : reason,
          },
        }),
      );
      this.submitted.set(true);
    } catch {
      this.failed.set(true);
    } finally {
      this.submitting.set(false);
    }
  }
}
