import { Injectable, signal } from '@angular/core';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { GlobalComponent } from '../global-component';

/**
 * Holds the current admin user's effective permissions for UI gating.
 *
 * Source of truth is the backend (every endpoint is enforced regardless of the
 * UI); this only decides what controls to *show*. Super admins (is_admin) hold
 * every permission, mirrored from both the session flag and the /me roles
 * array, so can() short-circuits true for them.
 */
@Injectable({ providedIn: 'root' })
export class PermissionService {
  private readonly _permissions = signal<string[]>([]);
  private readonly _superAdmin = signal<boolean>(false);
  private readonly _loaded = signal<boolean>(false);
  private fetching = false;

  readonly permissions = this._permissions.asReadonly();
  readonly loaded = this._loaded.asReadonly();

  constructor(private adapter: PortalCrudAdapter) {}

  /** Load the current user's effective permissions once. Safe to call repeatedly. */
  load(force = false): void {
    if (this.fetching || (this._loaded() && !force)) {
      return;
    }
    // Super-admin (is_admin) from the session → immediate bypass even before /me resolves.
    try {
      const s = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
      if (s?.is_admin) {
        this._superAdmin.set(true);
      }
    } catch {
      /* ignore malformed session */
    }

    this.fetching = true;
    this.adapter.get_v3('GET /me/profile').subscribe({
      next: (res: any) => {
        const u = res?.user ?? res?.data ?? res ?? {};
        this._permissions.set(Array.isArray(u.permissions) ? u.permissions : []);
        const roles = Array.isArray(u.roles) ? u.roles : [];
        if (roles.includes('admin')) {
          this._superAdmin.set(true);
        }
        this._loaded.set(true);
        this.fetching = false;
      },
      error: () => {
        this._loaded.set(true);
        this.fetching = false;
      },
    });
  }

  can(permission: string): boolean {
    return this._superAdmin() || this._permissions().includes(permission);
  }

  canAny(permissions: string[]): boolean {
    return this._superAdmin() || permissions.some((p) => this._permissions().includes(p));
  }

  canAll(permissions: string[]): boolean {
    return this._superAdmin() || permissions.every((p) => this._permissions().includes(p));
  }

  isSuperAdmin(): boolean {
    return this._superAdmin();
  }
}
