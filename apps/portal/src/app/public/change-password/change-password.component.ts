import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { readSession, homeRouteFor, patchSession, clearSession } from '../../core/auth/session.util';

/**
 * Forced "set a new password" screen for accounts provisioned with a temporary
 * password (must_change_password). The guards redirect here and keep the user
 * here until they complete it; there is intentionally no way to skip.
 *
 * Calls PATCH /me/password (current_password + new_password). That endpoint
 * rotates the token pair, so on success we patch the session with the fresh
 * tokens and clear the flag, then send the user to their real home.
 */
@Component({
  selector: 'app-change-password',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './change-password.component.html',
  styleUrl: './change-password.component.css',
})
export class ChangePasswordComponent {
  private readonly router = inject(Router);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);

  readonly email = readSession()?.email ?? '';
  current_password = '';
  new_password = '';
  confirm_password = '';
  show = false;
  saving = false;

  /** Escape hatch so a user who can't proceed isn't trapped on this screen. */
  signOut(): void {
    clearSession();
    this.router.navigate(['/login']);
  }

  submit(): void {
    if (this.saving) return;
    if (!this.current_password) { this.toast.error('Enter your temporary password.'); return; }
    if (this.new_password.length < 8) { this.toast.error('New password must be at least 8 characters.'); return; }
    if (this.new_password !== this.confirm_password) { this.toast.error('Passwords do not match.'); return; }
    if (this.new_password === this.current_password) {
      this.toast.error('Your new password must be different from the temporary one.');
      return;
    }

    this.saving = true;
    this.adapter.patch_v3('PATCH /me/password', {
      current_password: this.current_password,
      new_password: this.new_password,
    }).subscribe({
      next: (res: any) => {
        // The endpoint rotates tokens, carry the fresh pair into the session
        // and clear the must-change flag so the guards let the user through.
        patchSession({
          token: res?.access_token,
          refresh_token: res?.refresh_token,
          access_token_expires_at: res?.access_token_expires_at,
          refresh_token_expires_at: res?.refresh_token_expires_at,
          must_change_password: false,
        });
        this.saving = false;
        this.toast.success('Password updated — welcome to 3bayti!');
        this.router.navigateByUrl(homeRouteFor(readSession()));
      },
      error: (err: any) => {
        this.saving = false;
        this.toast.error(this.apiErrorMessage(err, 'Could not update your password. Please try again.'));
      },
    });
  }

  /** First field-level validation message, else the top-level message, else fallback. */
  private apiErrorMessage(err: any, fallback: string): string {
    const body = err?.error?.error ?? err?.error;
    const details = body?.details;
    if (details && typeof details === 'object') {
      for (const key of Object.keys(details)) {
        const v = (details as Record<string, unknown>)[key];
        const msg = Array.isArray(v) ? v[0] : v;
        if (msg) return String(msg);
      }
    }
    return body?.message || fallback;
  }
}
