import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';

import { AuthService } from '../../core/auth/auth.service';
import { WhatsAppService } from '../../core/auth/whatsapp.service';
import { AUTH_ERROR_CODES } from '../../core/auth/auth.types';

/**
 * /account/whatsapp — link a WhatsApp number to the account for the WhatsApp
 * Commerce channel (chat with Ain on WhatsApp, personalised once linked).
 *
 * Flow: enter the number → we send an OTP to it → enter the 6-digit code →
 * verified + saved. A linked number can be changed (re-link) or removed. The
 * cached user is patched by WhatsAppService so the linked state updates without
 * a /me refetch.
 */
@Component({
  selector: 'app-account-whatsapp',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, RouterLink, TranslatePipe],
  template: `
    <main class="acct-wa" data-testid="account-whatsapp-page">
      <div class="acct-wa__container">
        <a class="acct-wa__back" routerLink="/account">← {{ 'account.whatsapp.back' | translate }}</a>
        <h1 class="acct-wa__title">{{ 'account.whatsapp.title' | translate }}</h1>
        <p class="acct-wa__intro">{{ 'account.whatsapp.intro' | translate }}</p>

        @if (error(); as e) {
          <p class="acct-wa__error" role="alert" data-testid="wa-error">{{ e | translate }}</p>
        }

        @if (mode() === 'entry') {
          <label class="acct-wa__label" for="wa-phone">{{ 'account.whatsapp.numberLabel' | translate }}</label>
          <input
            id="wa-phone"
            class="acct-wa__input"
            type="tel"
            inputmode="tel"
            autocomplete="tel"
            placeholder="+971 50 123 4567"
            [ngModel]="phone()"
            (ngModelChange)="phone.set($event)"
            data-testid="wa-phone"
          />
          <div class="acct-wa__actions">
            <button type="button" class="acct-wa__btn acct-wa__btn--primary" [disabled]="busy()" (click)="sendCode()" data-testid="wa-send">
              {{ (busy() ? 'common.loading' : 'account.whatsapp.sendCode') | translate }}
            </button>
            <button type="button" class="acct-wa__btn" [disabled]="busy()" (click)="cancel()">
              {{ 'account.whatsapp.cancel' | translate }}
            </button>
          </div>
        } @else if (mode() === 'code') {
          <p class="acct-wa__sent">{{ 'account.whatsapp.codeSent' | translate }}</p>
          <label class="acct-wa__label" for="wa-code">{{ 'account.whatsapp.codeLabel' | translate }}</label>
          <input
            id="wa-code"
            class="acct-wa__input acct-wa__input--code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            placeholder="000000"
            [ngModel]="code()"
            (ngModelChange)="code.set($event)"
            data-testid="wa-code"
          />
          <div class="acct-wa__actions">
            <button type="button" class="acct-wa__btn acct-wa__btn--primary" [disabled]="busy()" (click)="verify()" data-testid="wa-verify">
              {{ (busy() ? 'common.loading' : 'account.whatsapp.verify') | translate }}
            </button>
            <button type="button" class="acct-wa__btn" [disabled]="busy()" (click)="cancel()">
              {{ 'account.whatsapp.cancel' | translate }}
            </button>
          </div>
        } @else if (linkedNumber(); as num) {
          <div class="acct-wa__linked" data-testid="wa-linked">
            <span class="acct-wa__linked-dot" aria-hidden="true"></span>
            <span class="acct-wa__linked-label">{{ 'account.whatsapp.linkedTo' | translate }}</span>
            <span class="acct-wa__linked-number" dir="ltr">{{ num }}</span>
          </div>
          <div class="acct-wa__actions">
            <button type="button" class="acct-wa__btn" [disabled]="busy()" (click)="startLink()" data-testid="wa-change">
              {{ 'account.whatsapp.changeNumber' | translate }}
            </button>
            <button type="button" class="acct-wa__btn acct-wa__btn--danger" [disabled]="busy()" (click)="unlink()" data-testid="wa-unlink">
              {{ (busy() ? 'common.loading' : 'account.whatsapp.unlink') | translate }}
            </button>
          </div>
        } @else {
          <button type="button" class="acct-wa__btn acct-wa__btn--primary" (click)="startLink()" data-testid="wa-start">
            {{ 'account.whatsapp.linkNumber' | translate }}
          </button>
        }
      </div>
    </main>
  `,
  styles: [`
    .acct-wa { padding: clamp(1.5rem, 5vw, 3rem) 1rem; }
    .acct-wa__container { max-width: 34rem; margin: 0 auto; }
    .acct-wa__back {
      display: inline-block; margin-bottom: 1rem; color: var(--color-text-secondary, #5a4a3c);
      font-size: 0.9rem; text-decoration: none;
    }
    .acct-wa__back:hover { color: var(--color-brand-700, #5a3a2c); }
    .acct-wa__title { margin: 0 0 0.5rem; font-size: 1.5rem; color: var(--color-text-primary, #2e241c); }
    .acct-wa__intro { margin: 0 0 1.5rem; color: var(--color-text-secondary, #5a4a3c); line-height: 1.55; }
    .acct-wa__error {
      margin: 0 0 1rem; padding: 0.7rem 0.9rem; border-radius: 0.6rem;
      background: rgba(179, 38, 30, 0.08); color: #b3261e; font-size: 0.9rem;
    }
    .acct-wa__label { display: block; margin-bottom: 0.4rem; font-size: 0.9rem; font-weight: 600; color: var(--color-text-primary, #2e241c); }
    .acct-wa__input {
      width: 100%; padding: 0.8rem 1rem; border: 1px solid var(--color-border, #e7ded2);
      border-radius: 0.6rem; background: var(--color-bg-surface, #fff);
      color: var(--color-text-primary, #2e241c); font-size: 1rem;
    }
    .acct-wa__input:focus-visible { outline: none; border-color: var(--color-brand-500, #b18f1f); }
    .acct-wa__input--code { letter-spacing: 0.4em; text-align: center; font-size: 1.3rem; }
    .acct-wa__sent { margin: 0 0 1rem; color: var(--color-text-secondary, #5a4a3c); font-size: 0.9rem; }
    .acct-wa__actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.25rem; }
    .acct-wa__btn {
      padding: 0.75rem 1.4rem; border: 1px solid var(--color-border, #e7ded2); border-radius: 999px;
      background: var(--color-bg-surface, #fff); color: var(--color-text-primary, #2e241c);
      font-size: 0.95rem; font-weight: 600; cursor: pointer;
    }
    .acct-wa__btn:disabled { opacity: 0.5; cursor: default; }
    .acct-wa__btn--primary { border-color: transparent; background: var(--color-brand-700, #5a3a2c); color: #fff; }
    .acct-wa__btn--primary:hover:not(:disabled) { background: var(--color-brand-500, #b18f1f); }
    .acct-wa__btn--danger { color: #b3261e; border-color: rgba(179, 38, 30, 0.3); }
    .acct-wa__linked {
      display: flex; align-items: center; gap: 0.6rem; padding: 1rem 1.2rem;
      border: 1px solid var(--color-border, #e7ded2); border-radius: 0.75rem;
      background: var(--color-bg-canvas, #faf8f5);
    }
    .acct-wa__linked-dot { width: 0.6rem; height: 0.6rem; border-radius: 50%; background: #128c7e; flex: none; }
    .acct-wa__linked-label { color: var(--color-text-secondary, #5a4a3c); font-size: 0.9rem; }
    .acct-wa__linked-number { margin-inline-start: auto; font-weight: 600; color: var(--color-text-primary, #2e241c); }
  `],
})
export class AccountWhatsAppPageComponent {
  private readonly auth = inject(AuthService);
  private readonly whatsapp = inject(WhatsAppService);
  private readonly i18n = inject(TranslateService);

  /** The currently linked number (null when none), from the cached user. */
  protected readonly linkedNumber = computed(() => this.auth.currentUser()?.whatsapp_phone ?? null);

  protected readonly mode = signal<'view' | 'entry' | 'code'>('view');
  protected readonly phone = signal('');
  protected readonly code = signal('');
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  private verificationId = '';

  protected startLink(): void {
    this.error.set(null);
    this.phone.set('');
    this.mode.set('entry');
  }

  protected cancel(): void {
    this.error.set(null);
    this.busy.set(false);
    this.mode.set('view');
  }

  protected async sendCode(): Promise<void> {
    const phone = this.normalise(this.phone());
    if (phone === null) {
      this.error.set('account.whatsapp.errors.invalidNumber');
      return;
    }
    this.busy.set(true);
    this.error.set(null);
    try {
      const res = await this.whatsapp.sendLink(phone);
      this.verificationId = res.verification_id;
      this.code.set('');
      this.mode.set('code');
    } catch (err) {
      this.error.set(this.sendErrorKey(err));
    } finally {
      this.busy.set(false);
    }
  }

  protected async verify(): Promise<void> {
    const code = this.code().trim();
    if (!/^\d{6}$/.test(code)) {
      this.error.set('account.whatsapp.errors.invalidCode');
      return;
    }
    this.busy.set(true);
    this.error.set(null);
    try {
      await this.whatsapp.verifyLink(this.verificationId, code);
      this.mode.set('view');
    } catch (err) {
      this.error.set(this.verifyErrorKey(err));
    } finally {
      this.busy.set(false);
    }
  }

  protected async unlink(): Promise<void> {
    this.busy.set(true);
    this.error.set(null);
    try {
      await this.whatsapp.unlink();
      this.mode.set('view');
    } catch {
      this.error.set('account.whatsapp.errors.generic');
    } finally {
      this.busy.set(false);
    }
  }

  private normalise(raw: string): string | null {
    let p = raw.trim().replace(/[\s-]/g, '');
    if (p !== '' && p[0] !== '+') {
      p = '+' + p;
    }
    return /^\+[1-9]\d{6,14}$/.test(p) ? p : null;
  }

  private extractErrorCode(err: unknown): string | null {
    if (!(err instanceof HttpErrorResponse)) return null;
    const body = err.error as { error_code?: string; code?: string; error?: { code?: string } } | null;
    if (body === null || typeof body !== 'object') return null;
    return body.error_code ?? body.code ?? body.error?.code ?? null;
  }

  private sendErrorKey(err: unknown): string {
    if (!(err instanceof HttpErrorResponse) || err.status === 0) return 'common.errors.network';
    if (this.extractErrorCode(err) === AUTH_ERROR_CODES.CONFLICT_PHONE_TAKEN) {
      return 'account.whatsapp.errors.taken';
    }
    if (this.extractErrorCode(err) === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
      return 'account.whatsapp.errors.rateLimited';
    }
    return 'account.whatsapp.errors.sendFailed';
  }

  private verifyErrorKey(err: unknown): string {
    if (!(err instanceof HttpErrorResponse) || err.status === 0) return 'common.errors.network';
    const code = this.extractErrorCode(err);
    if (code === AUTH_ERROR_CODES.OTP_INVALID_CODE || code === AUTH_ERROR_CODES.OTP_VERIFICATION_FAILED) {
      return 'account.whatsapp.errors.invalidCode';
    }
    if (code === AUTH_ERROR_CODES.OTP_RATE_LIMITED) {
      return 'account.whatsapp.errors.rateLimited';
    }
    return 'account.whatsapp.errors.verifyFailed';
  }
}
