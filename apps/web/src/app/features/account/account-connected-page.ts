import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastService } from '../../shared/forms';
import { SocialIdentityService, type SocialIdentity } from './social-identity.service';
import { SocialAuthService, type SocialProvider } from '../../core/auth/social-auth.service';

const PROVIDERS: SocialProvider[] = ['google', 'apple'];

/**
 * /account/connected — Connected accounts.
 *
 * Lists the user's Google/Apple links (GET /me/social-identities) with a
 * Link / Unlink control per provider.
 *
 *   Link   — run the Firebase popup (SocialAuthService) for a FRESH id_token,
 *            then POST /me/social-identities. 409 CONFLICT_DUPLICATE (the
 *            provider account belongs to someone else) → inline message.
 *   Unlink — DELETE /me/social-identities/:provider. 422
 *            BUSINESS_RULE_VIOLATION (last sign-in method) → clear message;
 *            404 (already gone) → just refresh the list.
 *
 * These are Bearer /me calls (via SocialIdentityService → RoutedHttpClient),
 * not BFF calls. Hidden when Firebase isn't configured.
 */
@Component({
  selector: 'app-account-connected',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-connected" data-testid="account-connected-page">
      <div class="account-connected__container">
        <nav class="account-connected__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.connected.title' | translate }}</span>
        </nav>

        <h1 class="account-connected__title">{{ 'account.connected.title' | translate }}</h1>
        <p class="account-connected__subtitle">{{ 'account.connected.subtitle' | translate }}</p>

        <ng-container *ngIf="available; else unavailable">
          <ng-container *ngIf="!isLoading(); else loadingState">
            <ul class="account-connected__list" data-testid="connected-list">
              <li
                *ngFor="let provider of providers"
                class="account-connected__row"
                [attr.data-provider]="provider"
              >
                <span
                  class="account-connected__provider-icon"
                  [class.account-connected__provider-icon--google]="provider === 'google'"
                  [class.account-connected__provider-icon--apple]="provider === 'apple'"
                  aria-hidden="true"
                ></span>
                <span class="account-connected__provider-info">
                  <span class="account-connected__provider-name">
                    {{ 'account.connected.providers.' + provider | translate }}
                  </span>
                  <span class="account-connected__provider-status">
                    <ng-container *ngIf="linkedEmail(provider) as email; else notLinked">
                      {{ 'account.connected.linkedAs' | translate : { email: email } }}
                    </ng-container>
                    <ng-template #notLinked>
                      {{ 'account.connected.notLinked' | translate }}
                    </ng-template>
                  </span>
                </span>

                <button
                  *ngIf="isLinked(provider); else linkBtn"
                  type="button"
                  class="account-connected__btn account-connected__btn--unlink"
                  [disabled]="busy() !== null"
                  (click)="onUnlink(provider)"
                  [attr.data-testid]="'connected-unlink-' + provider"
                >
                  {{ (busy() === provider ? 'common.loading' : 'account.connected.unlink') | translate }}
                </button>
                <ng-template #linkBtn>
                  <button
                    type="button"
                    class="account-connected__btn account-connected__btn--link"
                    [disabled]="busy() !== null"
                    (click)="onLink(provider)"
                    [attr.data-testid]="'connected-link-' + provider"
                  >
                    {{ (busy() === provider ? 'common.loading' : 'account.connected.link') | translate }}
                  </button>
                </ng-template>
              </li>
            </ul>
          </ng-container>

          <ng-template #loadingState>
            <div class="account-connected__loading" data-testid="connected-loading">
              {{ 'common.loading' | translate }}
            </div>
          </ng-template>
        </ng-container>

        <ng-template #unavailable>
          <p class="account-connected__unavailable" data-testid="connected-unavailable">
            {{ 'account.connected.unavailable' | translate }}
          </p>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './account-connected.scss',
})
export class AccountConnectedPageComponent implements OnInit {
  private readonly service = inject(SocialIdentityService);
  private readonly socialAuth = inject(SocialAuthService);
  private readonly toast = inject(ToastService);

  protected readonly providers = PROVIDERS;
  protected readonly available = this.socialAuth.isAvailable;

  protected readonly isLoading = signal(true);
  /** Provider currently mid-link/unlink (disables the row buttons), or null. */
  protected readonly busy = signal<SocialProvider | null>(null);

  private readonly identities = signal<SocialIdentity[]>([]);

  protected isLinked(provider: SocialProvider): boolean {
    return this.identities().some((i) => i.provider === provider);
  }

  protected linkedEmail(provider: SocialProvider): string | null {
    const match = this.identities().find((i) => i.provider === provider);
    return match ? match.email : null;
  }

  async ngOnInit(): Promise<void> {
    if (!this.available) {
      this.isLoading.set(false);
      return;
    }
    await this.reload();
  }

  private async reload(): Promise<void> {
    this.isLoading.set(true);
    try {
      this.identities.set(await this.service.list());
    } catch {
      this.toast.error('account.connected.errors.loadFailed');
    } finally {
      this.isLoading.set(false);
    }
  }

  protected async onLink(provider: SocialProvider): Promise<void> {
    if (this.busy() !== null) return;
    this.busy.set(provider);
    try {
      const tokenResult =
        provider === 'google'
          ? await this.socialAuth.getGoogleIdToken()
          : await this.socialAuth.getAppleIdToken();

      if (!tokenResult.ok) {
        if (tokenResult.reason !== 'cancelled') {
          this.toast.error('account.connected.errors.linkFailed');
        }
        return;
      }

      await this.service.link(tokenResult.idToken);
      this.toast.success('account.connected.linked');
      await this.reload();
    } catch (err) {
      /* 409 CONFLICT_DUPLICATE — the provider account is linked elsewhere. */
      if (errorCodeOf(err) === 'CONFLICT_DUPLICATE') {
        this.toast.error('account.connected.errors.alreadyLinked');
      } else if (isNetworkError(err)) {
        this.toast.error('common.errors.network');
      } else {
        this.toast.error('account.connected.errors.linkFailed');
      }
    } finally {
      this.busy.set(null);
    }
  }

  protected async onUnlink(provider: SocialProvider): Promise<void> {
    if (this.busy() !== null) return;
    this.busy.set(provider);
    try {
      await this.service.unlink(provider);
      this.toast.success('account.connected.unlinked');
      await this.reload();
    } catch (err) {
      /* 422 BUSINESS_RULE_VIOLATION — removing the only sign-in method. */
      if (errorCodeOf(err) === 'BUSINESS_RULE_VIOLATION') {
        this.toast.error('account.connected.errors.lastMethod');
      } else if (isNetworkError(err)) {
        this.toast.error('common.errors.network');
      } else {
        /* 404 (already unlinked) or anything else — just resync. */
        await this.reload();
      }
    } finally {
      this.busy.set(null);
    }
  }
}

/** Pull the API error_code out of an HttpErrorResponse body, or null. */
function errorCodeOf(err: unknown): string | null {
  if (!(err instanceof HttpErrorResponse)) return null;
  const body = err.error as
    | { error_code?: string; error?: { code?: string }; code?: string }
    | null;
  if (body === null || typeof body !== 'object') return null;
  return body.error_code ?? body.error?.code ?? body.code ?? null;
}

/** True for a connection-level failure (no HTTP response / status 0). */
function isNetworkError(err: unknown): boolean {
  return !(err instanceof HttpErrorResponse) || err.status === 0;
}
