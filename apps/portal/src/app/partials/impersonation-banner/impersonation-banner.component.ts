import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ImpersonationService } from '../../services/impersonation.service';
import { IconComponent } from '../../shared/icon/icon.component';

/**
 * Persistent banner shown while an admin is impersonating a vendor. Mounted at
 * the app root so it's visible on every surface, with a one-tap Exit that
 * restores the admin session. Deliberately loud (amber), impersonation is a
 * special, accountable mode.
 */
@Component({
  selector: 'app-impersonation-banner',
  standalone: true,
  imports: [CommonModule, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div *ngIf="imp.isImpersonating()" class="imp-banner" role="status" aria-live="polite">
      <app-icon name="visibility" class="imp-banner-icon" aria-hidden="true"></app-icon>
      <span class="imp-banner-text">
        Viewing as <strong>{{ imp.impersonatedName() }}</strong> — acting on this vendor's behalf.
      </span>
      <button type="button" class="imp-banner-exit" (click)="imp.exit()">
        <app-icon name="logout" aria-hidden="true"></app-icon> Exit
      </button>
    </div>
  `,
  styles: [`
    .imp-banner {
      position: fixed;
      left: 50%;
      bottom: 1.25rem;
      transform: translateX(-50%);
      z-index: var(--ax-z-toast, 1090);
      display: flex;
      align-items: center;
      gap: 0.6rem;
      max-width: min(34rem, calc(100vw - 2rem));
      padding: 0.6rem 0.9rem;
      border-radius: 999px;
      background: var(--ax-palette-brown-600, #7a5844);
      color: #fff;
      box-shadow: var(--ax-shadow-lg, 0 8px 24px rgba(0,0,0,0.24));
      font-size: var(--ax-fs-sm, 0.875rem);
    }
    .imp-banner-icon { flex-shrink: 0; opacity: 0.9; }
    .imp-banner-text { line-height: 1.35; }
    .imp-banner-text strong { font-weight: 700; }
    .imp-banner-exit {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      margin-left: 0.35rem;
      padding: 0.3rem 0.7rem;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.5);
      background: rgba(255,255,255,0.12);
      color: #fff;
      font-size: var(--ax-fs-xs, 0.8125rem);
      font-weight: 600;
      cursor: pointer;
    }
    .imp-banner-exit:hover { background: rgba(255,255,255,0.22); }
    @media (max-width: 768px) {
      .imp-banner {
        left: 0.75rem;
        right: 0.75rem;
        bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 0.6rem);
        transform: none;
        max-width: none;
      }
    }
  `],
})
export class ImpersonationBannerComponent {
  readonly imp = inject(ImpersonationService);
}
