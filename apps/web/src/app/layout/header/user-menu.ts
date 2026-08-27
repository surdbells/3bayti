import {
  Component,
  ChangeDetectionStrategy,
  Input,
  Output,
  EventEmitter,
  inject,
  signal,
  HostListener,
  ElementRef,
  OnInit,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../core/auth/auth.service';
import { MessagesService } from '../../features/messages/messages.service';
import type { AuthUser } from '../../core/auth/auth.types';

/**
 * User menu, the dropdown that appears when an authenticated user
 * clicks the avatar/name in the header.
 *
 * Contents:
 *   - Display name + email at the top (read-only)
 *   - 'My Account' link (placeholder; Y.4 will wire to /account)
 *   - 'Orders' link (placeholder; Y.2/Y.5 will wire to /account/orders)
 *   - 'Sign out' button
 *
 * UX:
 *   - Opens on trigger click; closes on outside click, Escape key,
 *     or after a menu-item navigation.
 *   - Focus moves into the menu on open and returns to trigger on
 *     close (standard listbox pattern, though we use a simple
 *     <button>-driven dropdown rather than full listbox semantics
 *     since there are no selectable options).
 *
 * a11y:
 *   - Trigger button has aria-expanded + aria-haspopup='menu'.
 *   - Menu has role='menu'; each item is role='menuitem'.
 *   - Escape and click-outside dismiss the menu.
 */
@Component({
  selector: 'app-user-menu',
  standalone: true,
  imports: [NgIf, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="user-menu" [class.user-menu--open]="open()">
      <button
        type="button"
        class="user-menu__trigger"
        (click)="toggle()"
        [attr.aria-expanded]="open()"
        aria-haspopup="menu"
        data-testid="user-menu-trigger"
      >
        <span class="user-menu__avatar" aria-hidden="true">
          <img
            *ngIf="user.avatar_url; else avatarInitials"
            class="user-menu__avatar-img"
            [src]="user.avatar_url"
            alt=""
            data-testid="user-menu-avatar-img"
          />
          <ng-template #avatarInitials>{{ initials() }}</ng-template>
        </span>
        <span class="user-menu__name">{{ displayName() }}</span>
        <span class="user-menu__chevron" aria-hidden="true">▾</span>
      </button>

      <div
        *ngIf="open()"
        class="user-menu__panel"
        role="menu"
        data-testid="user-menu-panel"
      >
        <div class="user-menu__header">
          <p class="user-menu__display-name">{{ displayName() }}</p>
          <p class="user-menu__email">{{ user.email }}</p>
        </div>
        <a
          routerLink="/account"
          role="menuitem"
          class="user-menu__item"
          (click)="close()"
          data-testid="user-menu-account"
        >
          {{ 'header.userMenu.account' | translate }}
        </a>
        <a
          routerLink="/account/orders"
          role="menuitem"
          class="user-menu__item"
          (click)="close()"
          data-testid="user-menu-orders"
        >
          {{ 'header.userMenu.orders' | translate }}
        </a>
        <a
          routerLink="/account/messages"
          role="menuitem"
          class="user-menu__item user-menu__item--with-badge"
          (click)="close()"
          data-testid="user-menu-messages"
        >
          <span>{{ 'header.userMenu.messages' | translate }}</span>
          <span
            *ngIf="unreadMessages() > 0"
            class="user-menu__badge"
            [attr.aria-label]="'account.messages.list.unreadAria' | translate: { count: unreadMessages() }"
            data-testid="user-menu-messages-badge"
          >{{ unreadMessages() }}</span>
        </a>
        <button
          type="button"
          role="menuitem"
          class="user-menu__item user-menu__item--danger"
          (click)="onSignOut()"
          data-testid="user-menu-signout"
        >
          {{ 'header.userMenu.signOut' | translate }}
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .user-menu {
        position: relative;
      }

      .user-menu__trigger {
        appearance: none;
        background: transparent;
        border: 1px solid transparent;
        padding: 6px 10px 6px 6px;
        border-radius: 999px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: inherit;
        color: inherit;
        transition: background-color 0.15s ease, border-color 0.15s ease;

        &:hover,
        &:focus-visible {
          background: rgba(46, 36, 28, 0.05);
          outline: none;
          border-color: rgba(46, 36, 28, 0.12);
        }
      }

      .user-menu__avatar {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--color-brand-500, #b18f1f);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 600;
        overflow: hidden;
      }

      .user-menu__avatar-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
        border-radius: 50%;
      }

      .user-menu__name {
        font-size: 14px;
        font-weight: 500;
        max-width: 120px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;

        @media (max-width: 720px) {
          display: none;
        }
      }

      .user-menu__chevron {
        font-size: 10px;
        opacity: 0.7;
      }

      .user-menu__panel {
        position: absolute;
        top: calc(100% + 6px);
        inset-inline-end: 0;
        min-width: 240px;
        background: #fff;
        border: 1px solid var(--color-border-default, #d8cfc1);
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(46, 36, 28, 0.12);
        padding: 6px;
        z-index: 100;
      }

      .user-menu__header {
        padding: 10px 12px 12px;
        border-bottom: 1px solid var(--color-border-subtle, #e2dccc);
        margin-bottom: 4px;
      }

      .user-menu__display-name {
        margin: 0 0 2px 0;
        font-size: 14px;
        font-weight: 600;
        color: var(--color-text-primary, #2e241c);
      }

      .user-menu__email {
        margin: 0;
        font-size: 12px;
        color: var(--color-text-muted, #6b6056);
        word-break: break-all;
      }

      .user-menu__item {
        display: block;
        width: 100%;
        text-align: start;
        padding: 9px 12px;
        font-size: 14px;
        color: var(--color-text-primary, #2e241c);
        text-decoration: none;
        border-radius: 6px;
        cursor: pointer;
        appearance: none;
        background: transparent;
        border: 0;
        font-family: inherit;

        &:hover,
        &:focus-visible {
          background: rgba(177, 143, 31, 0.08);
          outline: none;
        }

        &--danger {
          color: var(--color-danger-600, #b42218);
          border-top: 1px solid var(--color-border-subtle, #e2dccc);
          margin-top: 4px;
          padding-top: 11px;
        }

        &--with-badge {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
        }
      }

      .user-menu__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: var(--color-brand-500, #b18f1f);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
      }
    `,
  ],
})
export class UserMenuComponent implements OnInit {
  @Input({ required: true }) user!: AuthUser;
  @Output() signedOut = new EventEmitter<void>();

  protected readonly open = signal(false);

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly hostEl = inject(ElementRef);
  private readonly messages = inject(MessagesService);

  /** Unread order-chat total, drives the Messages item badge. */
  protected readonly unreadMessages = this.messages.unreadTotal;

  ngOnInit(): void {
    /* The menu only renders for signed-in users, so it's safe to ask
       for the unread count here. Best-effort; the service swallows
       failures and the badge stays hidden. */
    void this.messages.refreshUnreadCount();
  }

  protected displayName(): string {
    const first = this.user.first_name?.trim() ?? '';
    const last = this.user.last_name?.trim() ?? '';
    const full = [first, last].filter(s => s !== '').join(' ');
    return full !== '' ? full : this.user.email;
  }

  protected initials(): string {
    const first = this.user.first_name?.trim() ?? '';
    const last = this.user.last_name?.trim() ?? '';
    if (first !== '' && last !== '') return (first[0] + last[0]).toUpperCase();
    if (first !== '') return first.substring(0, 2).toUpperCase();
    /* Fall back to the first two chars of the email's local part. */
    const localPart = this.user.email.split('@')[0] ?? '';
    return localPart.substring(0, 2).toUpperCase();
  }

  protected toggle(): void {
    this.open.update(v => !v);
  }

  protected close(): void {
    this.open.set(false);
  }

  protected async onSignOut(): Promise<void> {
    this.close();
    await this.auth.logout();
    this.signedOut.emit();
    await this.router.navigateByUrl('/');
  }

  @HostListener('document:click', ['$event'])
  protected onDocumentClick(event: MouseEvent): void {
    if (!this.open()) return;
    const target = event.target as Node | null;
    if (target !== null && !(this.hostEl.nativeElement as HTMLElement).contains(target)) {
      this.close();
    }
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.open()) this.close();
  }
}
