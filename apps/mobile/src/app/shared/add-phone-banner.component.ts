import { Component, OnDestroy, OnInit } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { Subscription } from 'rxjs';

import { TranslatePipe } from '../translate.pipe';
import { AxIconComponent } from './ax-mobile/icon';

/**
 * Session-only dismissal, shared across every instance (home + account) via a
 * module-level flag. It resets when the app process restarts / the webview
 * reloads, so the banner returns next session until the user adds a phone.
 */
let dismissed = false;

/**
 * "Add your phone number" banner.
 *
 * Shows for a signed-in user who has NO phone on file and hasn't verified one
 * (the phone-after-social case: Google/Apple sign-ups arrive with phone === ''
 * and is_phone_verified === false, both live on the cached `user` blob written
 * at login by transformV3LoginResponse). Tapping it opens the profile page's
 * existing add/change-phone OTP flow via ?addPhone=1.
 *
 * Renders nothing for guests, for users with a phone, or once dismissed, so
 * it's safe to drop at the top of any page. Re-reads the blob on every
 * navigation so it disappears as soon as a number is added.
 */
@Component({
  selector: 'app-add-phone-banner',
  standalone: true,
  imports: [TranslatePipe, AxIconComponent],
  template: `
    @if (show) {
      <div class="addphone-banner">
        <button
          type="button"
          class="addphone-banner__main"
          (click)="goAddPhone()"
          [attr.aria-label]="'text_add_phone_title' | translate"
        >
          <span class="addphone-banner__icon" aria-hidden="true">
            <ax-icon name="phone"></ax-icon>
          </span>
          <span class="addphone-banner__body">
            <span class="addphone-banner__title">{{ 'text_add_phone_title' | translate }}</span>
            <span class="addphone-banner__sub">{{ 'text_add_phone_desc' | translate }}</span>
          </span>
          <span class="addphone-banner__cta" aria-hidden="true">
            <ax-icon name="chevron-right"></ax-icon>
          </span>
        </button>
        <button
          type="button"
          class="addphone-banner__dismiss"
          (click)="dismiss()"
          [attr.aria-label]="'text_dismiss' | translate"
        >
          <ax-icon name="x"></ax-icon>
        </button>
      </div>
    }
  `,
  styles: [
    `
      .addphone-banner {
        display: flex;
        align-items: stretch;
        gap: 6px;
        width: calc(100% - 32px);
        margin: 12px 16px 4px;
      }
      .addphone-banner__main {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 14px;
        background: var(--ax-color-bg-brand-subtle, #f5ece0);
        border: 1px solid var(--ax-color-border-subtle, #ecdfcb);
        text-align: left;
        cursor: pointer;
      }
      .addphone-banner__icon {
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        border-radius: 11px;
        background: var(--ax-color-bg-brand, #906952);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
      }
      .addphone-banner__body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
      }
      .addphone-banner__title {
        font-size: 14px;
        font-weight: 600;
        color: var(--ax-color-text-primary, #2e241c);
      }
      .addphone-banner__sub {
        font-size: 12.5px;
        line-height: 1.35;
        color: var(--ax-color-text-secondary, #6b6056);
      }
      .addphone-banner__cta {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        font-size: 20px;
        color: var(--ax-color-text-brand, #906952);
      }
      .addphone-banner__dismiss {
        flex-shrink: 0;
        width: 40px;
        border-radius: 14px;
        background: transparent;
        border: 1px solid var(--ax-color-border-subtle, #ecdfcb);
        color: var(--ax-color-text-secondary, #6b6056);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        cursor: pointer;
      }
    `,
  ],
})
export class AddPhoneBannerComponent implements OnInit, OnDestroy {
  show = false;
  private navSub?: Subscription;

  constructor(private router: Router) {}

  ngOnInit(): void {
    void this.recheck();
    // Re-read the cached user on every navigation so the banner clears the
    // moment a number is added (the profile flow persists it to the blob).
    this.navSub = this.router.events.subscribe((e) => {
      if (e instanceof NavigationEnd) {
        void this.recheck();
      }
    });
  }

  ngOnDestroy(): void {
    this.navSub?.unsubscribe();
  }

  private async recheck(): Promise<void> {
    if (dismissed) {
      this.show = false;
      return;
    }
    try {
      const ret = await Preferences.get({ key: 'user' });
      if (!ret.value) {
        this.show = false;
        return;
      }
      const u = JSON.parse(ret.value) as { phone?: unknown; is_phone_verified?: unknown };
      const hasPhone = typeof u?.phone === 'string' && u.phone.trim().length > 0;
      const verified = u?.is_phone_verified === true;
      this.show = !hasPhone && !verified;
    } catch {
      this.show = false;
    }
  }

  goAddPhone(): void {
    void this.router.navigate(['/profile'], { queryParams: { addPhone: 1 } });
  }

  dismiss(): void {
    dismissed = true;
    this.show = false;
  }
}
