import { Component, inject } from '@angular/core';

import { SessionManager } from '../../services/session-manager.service';

/**
 * Idle-session warning modal. Appears when the user has been inactive long
 * enough that their session is about to end, with a live countdown. They can
 * stay signed in (refreshes the token) or log out now. If the countdown runs
 * out, SessionManager signs them out — so a session never ends abruptly while
 * they're still working, only after an explicit warning.
 *
 * Rendered once in the app shell; visibility is driven by SessionManager
 * signals, so it costs nothing until an idle timeout approaches.
 */
@Component({
  selector: 'app-idle-modal',
  standalone: true,
  template: `
    @if (session.warningVisible()) {
      <div class="idle-overlay" role="dialog" aria-modal="true" aria-labelledby="idle-title">
        <div class="idle-card">
          <div class="idle-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>
            </svg>
          </div>
          <h2 id="idle-title" class="idle-title">Still there?</h2>
          <p class="idle-text">
            You've been inactive for a while. For your security you'll be signed out in
            <strong>{{ session.countdown() }}</strong> second{{ session.countdown() === 1 ? '' : 's' }}.
          </p>
          <div class="idle-actions">
            <button type="button" class="idle-btn idle-btn-ghost" (click)="session.logoutNow('user')">Log out</button>
            <button type="button" class="idle-btn idle-btn-primary" (click)="session.stayActive()" autofocus>Stay signed in</button>
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .idle-overlay {
      position: fixed; inset: 0; z-index: 10000;
      background: rgba(0, 0, 0, 0.45);
      display: flex; align-items: center; justify-content: center;
      padding: 1rem;
    }
    .idle-card {
      background: var(--ax-color-bg-surface, #fff);
      color: var(--ax-color-text-primary, #1a1a1a);
      border-radius: 16px;
      padding: 28px 26px;
      max-width: 380px; width: 100%;
      text-align: center;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    }
    .idle-icon {
      width: 52px; height: 52px; border-radius: 50%;
      background: var(--ax-color-bg-brand-subtle, #efe7df);
      color: var(--ax-color-text-brand, #7c2108);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 14px;
    }
    .idle-title { font-size: 19px; font-weight: 700; margin: 0 0 8px; }
    .idle-text { font-size: 14px; color: var(--ax-color-text-secondary, #666); margin: 0 0 20px; line-height: 1.5; }
    .idle-text strong { color: var(--ax-color-text-primary, #1a1a1a); font-variant-numeric: tabular-nums; }
    .idle-actions { display: flex; gap: 10px; justify-content: center; }
    .idle-btn {
      border-radius: 10px; padding: 10px 18px; font-size: 14px; font-weight: 600;
      cursor: pointer; border: 1px solid transparent; transition: transform 0.12s ease, background 0.15s ease;
    }
    .idle-btn:active { transform: scale(0.97); }
    .idle-btn-ghost { background: transparent; border-color: var(--ax-color-border, #ddd); color: var(--ax-color-text-primary, #1a1a1a); }
    .idle-btn-ghost:hover { background: var(--ax-color-bg-muted, #f5f4f2); }
    .idle-btn-primary { background: var(--ax-color-text-brand, #7c2108); color: #fff; }
    .idle-btn-primary:hover { filter: brightness(1.06); }
  `],
})
export class IdleModalComponent {
  readonly session = inject(SessionManager);
}
