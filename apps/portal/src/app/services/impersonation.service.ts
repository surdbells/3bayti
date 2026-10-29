import { Injectable, inject } from '@angular/core';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { PortalAuthService } from './portal-auth.service';
import { HotToastService } from '../shared/toast/toast.service';

/**
 * Admin "sign in as vendor" (impersonation).
 *
 * start() mints a session for the vendor's owner server-side (tagged with the
 * admin's id via the token's imp_by claim), swaps the stored session, and does
 * a hard navigation so every component re-reads the new session as the vendor.
 * exit() ends the impersonation session server-side and restores the stashed
 * admin session. A persistent banner
 * (ImpersonationBannerComponent) surfaces the state and the exit control.
 */
@Injectable({ providedIn: 'root' })
export class ImpersonationService {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly auth = inject(PortalAuthService);
  private readonly toast = inject(HotToastService);

  starting = false;
  /** Guards a double-tap on Exit while the logout call settles. */
  private exiting = false;

  /** Begin impersonating a vendor by store id. */
  start(vendorId: number, storeName?: string): void {
    if (this.starting) return;
    this.starting = true;
    this.adapter
      .post_v3('POST /admin/vendors/:id/impersonate', {}, { params: { id: String(vendorId) } })
      .subscribe({
        next: (res: any) => {
          const label =
            res?.impersonation?.store_name || storeName || res?.impersonation?.user_name || 'vendor';
          this.auth.startImpersonation(res, label);
          // Hard navigation → full reload so the whole app runs as the vendor.
          window.location.assign('/account');
        },
        error: (err: any) => {
          this.starting = false;
          this.toast.error(this.apiError(err, 'Could not sign in as this vendor.'));
        },
      });
  }

  isImpersonating(): boolean {
    return this.auth.isImpersonating();
  }

  impersonatedName(): string {
    return this.auth.impersonatedName();
  }

  /**
   * End impersonation and return to the admin session. The impersonation
   * session is ended server-side first (fire-and-forget, only that session,
   * never the vendor's own), read before the swap so it can't hit the admin's.
   * A refresh of the impersonation session still in flight can't touch the
   * restored admin session either (the adapter compare-and-swaps on the
   * refresh token it sent). The hard navigation waits briefly for the logout
   * call, since a page reload would otherwise abort it in flight.
   */
  exit(): void {
    if (this.exiting) return;
    this.exiting = true;
    const revoked = this.auth.revokeCurrentSession();
    let target = '/backend';
    if (!this.auth.stopImpersonation()) {
      // Admin backup missing (e.g. cleared), sign out to a clean state.
      sessionStorage.clear();
      target = '/';
    }
    void revoked.then(() => this.hardNavigate(target));
  }

  /** Full page load, so every component re-reads the restored session. */
  private hardNavigate(url: string): void {
    window.location.assign(url);
  }

  private apiError(err: any, fallback: string): string {
    return err?.error?.error?.message ?? err?.error?.message ?? fallback;
  }
}
