import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { HotToastService } from '../../shared/toast/toast.service';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../shared/forms/ax-combobox.component';
import { OtaAdminService, OtaBundle, OtaUploadMeta } from '../../services/ota-admin.service';

/**
 * Admin page to manage self-hosted OTA (over-the-air) mobile web bundles -
 * upload a built .zip, publish it, roll it back, or delete it, all without
 * touching the server shell. Backed by /v3/admin/ota/bundles.
 */
@Component({
  selector: 'app-ota',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, AxComboboxComponent],
  templateUrl: './ota.component.html',
  styleUrl: './ota.component.css',
})
export class OtaComponent implements OnInit {
  readonly platformOptions: AxComboboxOption[] = [
    { id: 'android', label: 'Android' },
    { id: 'ios', label: 'iOS' },
    { id: 'both', label: 'Both (iOS + Android)' },
  ];
  private readonly confirm = inject(AxConfirmService);
  private readonly navHistory = inject(NavigationHistoryService);

  readonly loading = signal(true);
  readonly uploading = signal(false);
  /** 0-100 upload progress for the bar shown while a bundle is uploading. */
  readonly uploadProgress = signal(0);
  readonly bundles = signal<OtaBundle[]>([]);
  /** Id of the bundle whose rollback is in flight (disables its button). */
  readonly rollingBackId = signal<number | null>(null);

  /**
   * Ids of the bundle each platform/channel is actually serving: the newest
   * active row (the update endpoint orders by publish time). Every other row
   * can be rolled back to.
   */
  readonly servedIds = computed(() => {
    const newest = new Map<string, OtaBundle>();
    for (const b of this.bundles()) {
      if (!b.is_active) continue;
      const key = `${b.app_id}|${b.platform}|${b.channel}`;
      const current = newest.get(key);
      if (!current || Date.parse(b.created_at) > Date.parse(current.created_at)) {
        newest.set(key, b);
      }
    }
    return new Set([...newest.values()].map((b) => b.id));
  });

  /** Upload form model. */
  form: OtaUploadMeta & { channel: string; min_native: string } = {
    platform: 'android',
    version: '',
    channel: 'production',
    min_native: '0.0.0',
    session_key: '',
    checksum: '',
    notes: '',
  };
  file: File | null = null;

  constructor(
    private router: Router,
    private ota: OtaAdminService,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    try {
      this.bundles.set(await this.ota.list());
    } catch {
      this.toast.error('Unable to load OTA bundles.');
    } finally {
      this.loading.set(false);
    }
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file = input.files && input.files.length ? input.files[0] : null;
  }

  async upload(): Promise<void> {
    if (this.uploading()) return;
    if (!this.file) {
      this.toast.error('Choose a bundle .zip first.');
      return;
    }
    if (!/^\d+(\.\d+){0,3}$/.test(this.form.version.trim())) {
      this.toast.error('Version must be semver, e.g. 1.0.7.');
      return;
    }
    // Signed bundles carry both the ivSessionKey and the encrypt-command checksum.
    if (this.form.session_key?.trim() && !this.form.checksum?.trim()) {
      this.toast.error('A signed bundle also needs the checksum from `capgo encrypt`.');
      return;
    }

    this.uploading.set(true);
    this.uploadProgress.set(0);
    try {
      const published = await this.ota.upload(
        this.file,
        {
          platform: this.form.platform,
          version: this.form.version,
          channel: this.form.channel,
          min_native: this.form.min_native,
          session_key: this.form.session_key || undefined,
          checksum: this.form.checksum || undefined,
          notes: this.form.notes || undefined,
        },
        (pct) => this.uploadProgress.set(pct),
      );
      const platforms = published.map((b) => b.platform).join(' + ') || this.form.platform;
      const version = published[0]?.version ?? this.form.version;
      this.toast.success(`Published ${platforms} ${version}.`);
      // Reset the file + version; keep platform/channel/min_native for the next one.
      this.file = null;
      this.form.version = '';
      this.form.session_key = '';
      this.form.checksum = '';
      this.form.notes = '';
      const fileInput = document.getElementById('ota-file') as HTMLInputElement | null;
      if (fileInput) fileInput.value = '';
      await this.load();
    } catch (e: any) {
      this.toast.error(e?.error?.error?.message || e?.error?.message || 'Upload failed.');
    } finally {
      this.uploading.set(false);
      this.uploadProgress.set(0);
    }
  }

  async toggleActive(bundle: OtaBundle): Promise<void> {
    const next = !bundle.is_active;
    if (!next) {
      const ok = await this.confirm.confirm({
        title: 'Deactivate bundle',
        message:
          `Stop offering ${bundle.platform} ${bundle.version} to devices that haven't downloaded it yet? ` +
          `Devices that already installed it keep it. To move them back, use "Roll back to this" on the bundle you want them on.`,
        confirmLabel: 'Deactivate',
        cancelLabel: 'Cancel',
        variant: 'danger',
      });
      if (!ok) return;
    }
    try {
      await this.ota.setActive(bundle.id, next);
      this.toast.success(next ? 'Bundle activated.' : 'Bundle deactivated.');
      await this.load();
    } catch {
      this.toast.error('Unable to update the bundle.');
    }
  }

  /**
   * One-click rollback to `bundle`. Previews the version the server will
   * assign (next after the highest ever published for this platform/channel);
   * the server computes the real one and the toast reports it.
   */
  async rollbackTo(bundle: OtaBundle): Promise<void> {
    if (this.rollingBackId() !== null) return;
    const nextVersion = this.previewRollbackVersion(bundle);
    const content = bundle.rollback_of_version ?? bundle.version;
    const ok = await this.confirm.confirm({
      title: `Roll back ${bundle.platform} to ${content}`,
      message:
        `This republishes the ${content} bundle as version ${nextVersion} and deactivates every other ` +
        `${bundle.platform} / ${bundle.channel} bundle. Devices download it the next time the app is opened ` +
        `and switch to it after a restart. No upload needed.`,
      confirmLabel: 'Roll back',
      cancelLabel: 'Cancel',
      variant: 'danger',
    });
    if (!ok) return;

    this.rollingBackId.set(bundle.id);
    try {
      const result = await this.ota.rollback(bundle.id);
      this.toast.success(`Rolled back ${result.bundle.platform} to ${content} (served as ${result.bundle.version}).`);
      await this.load();
    } catch (e: any) {
      this.toast.error(e?.error?.error?.message || e?.error?.message || 'Unable to roll back.');
    } finally {
      this.rollingBackId.set(null);
    }
  }

  /** The next version after the highest published for the bundle's platform/channel. */
  private previewRollbackVersion(bundle: OtaBundle): string {
    const scoped = this.bundles().filter(
      (b) => b.app_id === bundle.app_id && b.platform === bundle.platform && b.channel === bundle.channel,
    );
    const highest = scoped.reduce((max, b) => (compareVersions(b.version, max) > 0 ? b.version : max), bundle.version);
    const parts = highest.split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
    while (parts.length < 3) parts.push(0);
    parts[parts.length - 1]++;
    return parts.join('.');
  }

  async remove(bundle: OtaBundle): Promise<void> {
    const ok = await this.confirm.confirm({
      title: 'Delete bundle',
      message: `Permanently delete ${bundle.platform} ${bundle.version} and its file? This cannot be undone.`,
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
      variant: 'danger',
    });
    if (!ok) return;
    try {
      await this.ota.remove(bundle.id);
      this.toast.success('Bundle deleted.');
      await this.load();
    } catch {
      this.toast.error('Unable to delete the bundle.');
    }
  }

  shortChecksum(checksum: string): string {
    return checksum ? checksum.slice(0, 10) + '…' : '—';
  }

  goBack(): void {
    this.navHistory.back('/account');
  }
}

/** Numeric semver compare (mirrors the API's OtaVersion::compare). */
function compareVersions(a: string, b: string): number {
  const pa = a.split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
  const pb = b.split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (d !== 0) return d;
  }
  return 0;
}
