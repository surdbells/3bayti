import { Component, OnDestroy, OnInit } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { Subscription } from 'rxjs';

import { TranslatePipe } from '../translate.pipe';
import { AxIconComponent } from './ax-mobile/icon';

/**
 * Session-only dismissal, shared across every instance (home + account) via a
 * module-level flag. Resets when the app process restarts / the webview
 * reloads, so the banner returns next session until the user updates their
 * email.
 */
let dismissed = false;

/**
 * "Update your email" banner.
 *
 * Shows for a signed-in user whose email can't receive our transactional mail
 * (Apple private-relay / social-placeholder addresses). The signal is
 * `needs_email_update === true` on the cached `user` blob written at login by
 * transformV3LoginResponse (and refreshed on dashboard load). Tapping it opens
 * the profile page's change-email OTP flow via ?addEmail=1.
 *
 * Renders nothing for guests, for users with a deliverable email, or once
 * dismissed. Re-reads the blob on every navigation so it disappears as soon as
 * the email is updated.
 */
@Component({
  selector: 'app-add-email-banner',
  standalone: true,
  imports: [TranslatePipe, AxIconComponent],
  template: `
    @if (show) {
      <div class="addemail-banner">
        <button
          type="button"
          class="addemail-banner__main"
          (click)="goUpdateEmail()"
          [attr.aria-label]="'text_add_email_title' | translate"
        >
          <span class="addemail-banner__icon" aria-hidden="true">
            <ax-icon name="mail"></ax-icon>
          </span>
          <span class="addemail-banner__body">
            <span class="addemail-banner__title">{{ 'text_add_email_title' | translate }}</span>
            <span class="addemail-banner__sub">{{ 'text_add_email_desc' | translate }}</span>
          </span>
          <span class="addemail-banner__cta" aria-hidden="true">
            <ax-icon name="chevron-right"></ax-icon>
          </span>
        </button>
        <button
          type="button"
          class="addemail-banner__dismiss"
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
      .addemail-banner {
        display: flex;
        align-items: stretch;
        gap: 6px;
        width: calc(100% - 32px);
        margin: 12px 16px 4px;
      }
      .addemail-banner__main {
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
      .addemail-banner__icon {
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
      .addemail-banner__body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
      }
      .addemail-banner__title {
        font-size: 14px;
        font-weight: 600;
        color: var(--ax-color-text-primary, #2e241c);
      }
      .addemail-banner__sub {
        font-size: 12.5px;
        line-height: 1.35;
        color: var(--ax-color-text-secondary, #6b6056);
      }
      .addemail-banner__cta {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        font-size: 20px;
        color: var(--ax-color-text-brand, #906952);
      }
      .addemail-banner__dismiss {
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
export class AddEmailBannerComponent implements OnInit, OnDestroy {
  show = false;
  private navSub?: Subscription;

  constructor(private router: Router) {}

  ngOnInit(): void {
    void this.recheck();
    // Re-read the cached user on every navigation so the banner clears the
    // moment the email is updated (the profile flow persists it to the blob).
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
      const u = JSON.parse(ret.value) as { needs_email_update?: unknown };
      this.show = u?.needs_email_update === true;
    } catch {
      this.show = false;
    }
  }

  goUpdateEmail(): void {
    void this.router.navigate(['/profile'], { queryParams: { addEmail: 1 } });
  }

  dismiss(): void {
    dismissed = true;
    this.show = false;
  }
}
