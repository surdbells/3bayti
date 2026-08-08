import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { HotToastService } from '../../shared/toast/toast.service';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { OtaAdminService, OtaBundle, OtaUploadMeta } from '../../services/ota-admin.service';

/**
 * Admin page to manage self-hosted OTA (over-the-air) mobile web bundles —
 * upload a built .zip, publish it, roll it back, or delete it, all without
 * touching the server shell. Backed by /v3/admin/ota/bundles.
 */
@Component({
  selector: 'app-ota',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
  templateUrl: './ota.component.html',
  styleUrl: './ota.component.css',
})
export class OtaComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  readonly loading = signal(true);
  readonly uploading = signal(false);
  /** 0-100 upload progress for the bar shown while a bundle is uploading. */
  readonly uploadProgress = signal(0);
  readonly bundles = signal<OtaBundle[]>([]);

  /** Upload form model. */
  form: OtaUploadMeta & { channel: string; min_native: string } = {
    platform: 'android',
    version: '',
    channel: 'production',
    min_native: '0.0.0',
    session_key: '',
    checksum: '',
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
        message: `Stop serving ${bundle.platform} ${bundle.version}? Devices will fall back to the previous active bundle.`,
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
    this.router.navigate(['/account']);
  }
}
