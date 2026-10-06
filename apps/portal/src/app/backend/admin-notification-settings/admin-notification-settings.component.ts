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

/**
 * Admin "Notification settings" — platform-wide switches for customer
 * notifications. Today it holds one toggle:
 *
 *   suppress_vendor_item_status — when ON, customers stop getting an email +
 *   push every time a VENDOR moves an individual order item (accepted /
 *   preparing / shipped / delivered / rejected). On multi-vendor orders that
 *   per-item stream floods the customer; order-level updates and changes an
 *   admin makes still notify.
 *
 * Backed by GET/PUT /v3/admin/settings/notifications (settings.view /
 * settings.edit). Takes effect on the next status change — no redeploy.
 */
@Component({
  selector: 'app-admin-notification-settings',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, IconComponent, AxCanDirective],
  templateUrl: './admin-notification-settings.component.html',
  styleUrl: './admin-notification-settings.component.css',
})
export class AdminNotificationSettingsComponent implements OnInit {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly perms = inject(PermissionService);

  readonly loading = signal(true);
  readonly saving = signal(false);
  /**
   * True when the last GET failed. The setting is then UNKNOWN, so the page
   * shows an error + Retry instead of the default (false / "sending") state —
   * otherwise an admin could be told updates are sending when they are paused,
   * and could save a value on top of state that never loaded.
   */
  readonly loadError = signal(false);

  /** Current (possibly unsaved) value of the toggle. */
  readonly suppressVendorItemStatus = signal(false);
  /** Last value confirmed by the server — drives the "unsaved changes" state. */
  private readonly savedSuppressVendorItemStatus = signal(false);

  /** Whether the current admin may change the setting (vs. read-only view). */
  readonly canEdit = computed(() => this.perms.can('settings.edit'));

  /** True when the switch differs from what the server last returned. */
  readonly dirty = computed(() => this.suppressVendorItemStatus() !== this.savedSuppressVendorItemStatus());

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.loadError.set(false);
    this.adapter.get_v3('GET /admin/settings/notifications').subscribe({
      next: (res: any) => {
        this.apply(res);
        this.loading.set(false);
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load notification settings.'));
        this.loadError.set(true);
        this.loading.set(false);
      },
    });
  }

  toggleSuppress(): void {
    if (!this.canEdit() || this.loadError()) return;
    this.suppressVendorItemStatus.update((v) => !v);
  }

  save(): void {
    if (this.saving() || this.loading() || this.loadError() || !this.canEdit()) return;
    this.saving.set(true);
    const payload = { suppress_vendor_item_status: this.suppressVendorItemStatus() };
    this.adapter.put_v3('PUT /admin/settings/notifications', payload).subscribe({
      next: (res: any) => {
        this.apply(res, payload.suppress_vendor_item_status);
        this.toast.success(
          this.suppressVendorItemStatus()
            ? 'Saved. Per-item order updates to customers are paused.'
            : 'Saved. Customers get per-item order updates again.',
        );
        this.saving.set(false);
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to save notification settings.'));
        this.saving.set(false);
      },
    });
  }

  /** Read the settings body defensively (raw body or a { data } envelope). */
  private apply(res: any, fallback = false): void {
    const body = res?.data ?? res;
    const raw = body?.suppress_vendor_item_status;
    const value = typeof raw === 'boolean' ? raw : fallback;
    this.suppressVendorItemStatus.set(value);
    this.savedSuppressVendorItemStatus.set(value);
  }

  goBack(): void {
    this.navHistory.back('/backend');
  }
}
