import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import { PermissionService } from '../../services/permission.service';

/** One OTP provider row in the admin ordering UI. */
interface ProviderRow {
  key: string;
  label: string;
  enabled: boolean;
  /** Server has the credentials wired to actually use this provider. */
  configured: boolean;
}

/**
 * Admin "OTP Providers" — choose which SMS-OTP providers are active and in what
 * PRIORITY order the backend tries them. The router tries the enabled providers
 * top-to-bottom and automatically fails over to the next when one errors (e.g.
 * MessageCentral returning "Pricing not found"), so one provider's outage no
 * longer blocks login/verification codes.
 *
 * Backed by GET/PUT /v3/admin/otp/providers (settings.view / settings.edit).
 * Changes take effect on the NEXT send — no redeploy. Email OTP is unaffected.
 */
@Component({
  selector: 'app-admin-otp-providers',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, IconComponent, AxCanDirective],
  templateUrl: './admin-otp-providers.component.html',
  styleUrl: './admin-otp-providers.component.css',
})
export class AdminOtpProvidersComponent implements OnInit {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly perms = inject(PermissionService);

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly rows = signal<ProviderRow[]>([]);
  readonly defaultProvider = signal<string>('messagecentral');

  /** Whether the current admin may change the config (vs. read-only view). */
  readonly canEdit = computed(() => this.perms.can('settings.edit'));

  /** At least one provider enabled — the Save precondition the API enforces. */
  readonly hasEnabled = computed(() => this.rows().some((r) => r.enabled));

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.adapter.get_v3('GET /admin/otp/providers').subscribe({
      next: (res: any) => {
        const providers: any[] = res?.providers ?? res?.data?.providers ?? [];
        this.rows.set(
          providers.map((p) => ({
            key: String(p.key),
            label: String(p.label ?? p.key),
            enabled: !!p.enabled,
            configured: !!p.configured,
          })),
        );
        this.defaultProvider.set(String(res?.default_provider ?? res?.data?.default_provider ?? 'messagecentral'));
        this.loading.set(false);
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load OTP providers.'));
        this.loading.set(false);
      },
    });
  }

  isDefault(key: string): boolean {
    return key === this.defaultProvider();
  }

  toggle(index: number): void {
    if (!this.canEdit()) return;
    this.rows.update((rows) => {
      const next = [...rows];
      next[index] = { ...next[index], enabled: !next[index].enabled };
      return next;
    });
  }

  moveUp(index: number): void {
    if (!this.canEdit() || index <= 0) return;
    this.swap(index, index - 1);
  }

  moveDown(index: number): void {
    if (!this.canEdit() || index >= this.rows().length - 1) return;
    this.swap(index, index + 1);
  }

  private swap(a: number, b: number): void {
    this.rows.update((rows) => {
      const next = [...rows];
      [next[a], next[b]] = [next[b], next[a]];
      return next;
    });
  }

  save(): void {
    if (this.saving() || !this.canEdit()) return;
    if (!this.hasEnabled()) {
      this.toast.error('Enable at least one OTP provider before saving.');
      return;
    }
    this.saving.set(true);
    const payload = {
      providers: this.rows().map((r) => ({ key: r.key, enabled: r.enabled })),
    };
    this.adapter.put_v3('PUT /admin/otp/providers', payload).subscribe({
      next: (res: any) => {
        const providers: any[] = res?.providers ?? res?.data?.providers ?? [];
        if (providers.length) {
          this.rows.set(
            providers.map((p) => ({
              key: String(p.key),
              label: String(p.label ?? p.key),
              enabled: !!p.enabled,
              configured: !!p.configured,
            })),
          );
        }
        this.toast.success('OTP provider settings saved.');
        this.saving.set(false);
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to save OTP provider settings.'));
        this.saving.set(false);
      },
    });
  }

  goBack(): void {
    this.navHistory.back('/backend');
  }
}
