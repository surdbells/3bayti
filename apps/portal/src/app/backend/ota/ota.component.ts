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
import { apiErrorMessage } from '../../shared/http/api-error';
import {
  OTA_MAX_BYTES,
  compareOtaVersions,
  isOtaVersion,
  isPlainZipHeader,
  otaBuiltinReachWarning,
  otaChecksumError,
  otaNativeRangeError,
  otaNativeRangeLabel,
  otaRangeMatchesNoApp,
  otaRollbackImpact,
  otaSessionKeyError,
  otaUnsignedError,
  servedOtaBundleIds,
} from './ota-rules';

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
   * Ids of the bundles some app build is actually offered: per native
   * version, the highest-version active row whose native range covers it (the
   * update endpoint picks by version, not publish time). Every other row can
   * be rolled back to.
   */
  readonly servedIds = computed(() => servedOtaBundleIds(this.bundles()));

  /** "1.8" or "1.8 – 1.9" for the bundles table. */
  readonly nativeRange = otaNativeRangeLabel;

  /**
   * Rows whose native range ends at 0 (legacy rows published with the old
   * 0.0.0 Min native default): no app is offered them, so they are never
   * live and can't be rolled back to.
   */
  readonly matchesNoApp = otaRangeMatchesNoApp;

  /**
   * Upload form model. min_native is required (no default: a bundle only
   * reaches app builds inside [min, max]); an empty max_native defaults to
   * min_native on the server, so the bundle targets exactly that app version.
   */
  form: OtaUploadMeta & { channel: string; min_native: string; max_native: string } = {
    platform: 'android',
    version: '',
    channel: 'production',
    min_native: '',
    max_native: '',
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
    } catch (e: unknown) {
      this.toast.error(apiErrorMessage(e, 'Unable to load OTA bundles.'));
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
    if (this.file.size > OTA_MAX_BYTES) {
      this.toast.error(`Bundle too large (max ${OTA_MAX_BYTES / 1024 / 1024} MB).`);
      return;
    }
    if (!isOtaVersion(this.form.version)) {
      this.toast.error('Version must be numeric, e.g. 1.0.7 (no pre-release suffix).');
      return;
    }
    const nativeError = otaNativeRangeError(this.form.min_native, this.form.max_native);
    if (nativeError) {
      this.toast.error(nativeError);
      return;
    }
    // Signed bundles carry the ivSessionKey, the ENCRYPTED checksum and the
    // *_encrypted.zip; unsigned ones are android + non-production only.
    const signed = !!this.form.session_key?.trim();
    const signingError = signed
      ? otaSessionKeyError(this.form.session_key ?? '')
      : otaUnsignedError(this.form.platform, this.form.channel || 'production');
    if (signingError) {
      this.toast.error(signingError);
      return;
    }
    const checksumError = otaChecksumError(this.form.checksum ?? '', signed);
    if (checksumError) {
      this.toast.error(checksumError);
      return;
    }
    // Not an error (devices already on a lower OTA bundle still take it), but
    // fresh installs / store updates of the top of the range never would.
    const reachWarning = otaBuiltinReachWarning(this.form.version, this.form.min_native, this.form.max_native);
    if (reachWarning) {
      const ok = await this.confirm.confirm({
        title: 'Version not above Max native',
        message: `${reachWarning} Publish anyway?`,
        confirmLabel: 'Publish anyway',
        cancelLabel: 'Cancel',
      });
      if (!ok) return;
    }

    this.uploading.set(true);
    this.uploadProgress.set(0);
    try {
      // `bundle encrypt` leaves the plain zip next to its *_encrypted.zip;
      // catch the wrong one before uploading (the API refuses it too).
      const plainZip = isPlainZipHeader(new Uint8Array(await this.file.slice(0, 4).arrayBuffer()));
      if (signed && plainZip) {
        this.toast.error('This is the PLAIN .zip. For a signed bundle, upload the *_encrypted.zip written by `bundle encrypt`.');
        return;
      }
      if (!signed && !plainZip) {
        this.toast.error('This is not a plain .zip. An encrypted bundle needs its Session key and Checksum.');
        return;
      }

      const published = await this.ota.upload(
        this.file,
        {
          platform: this.form.platform,
          version: this.form.version,
          channel: this.form.channel,
          min_native: this.form.min_native,
          max_native: this.form.max_native.trim() || undefined,
          session_key: this.form.session_key || undefined,
          checksum: this.form.checksum || undefined,
          notes: this.form.notes || undefined,
        },
        (pct) => this.uploadProgress.set(pct),
      );
      const platforms = published.map((b) => b.platform).join(' + ') || this.form.platform;
      const version = published[0]?.version ?? this.form.version;
      this.toast.success(`Published ${platforms} ${version}.`);
      // Reset the file + version; keep platform/channel/native range for the next one.
      this.file = null;
      this.form.version = '';
      this.form.session_key = '';
      this.form.checksum = '';
      this.form.notes = '';
      const fileInput = document.getElementById('ota-file') as HTMLInputElement | null;
      if (fileInput) fileInput.value = '';
      await this.load();
    } catch (e: unknown) {
      this.toast.error(apiErrorMessage(e, 'Upload failed.'));
    } finally {
      this.uploading.set(false);
      this.uploadProgress.set(0);
    }
  }

  async toggleActive(bundle: OtaBundle): Promise<void> {
    const next = !bundle.is_active;
    if (next && this.matchesNoApp(bundle)) {
      // The API refuses this too: an active row no app is offered.
      this.toast.error(
        `${bundle.platform} ${bundle.version} is published for native app version ${this.nativeRange(bundle)}, which no installed app reports, ` +
          'so activating it would serve no device. Widen its Max native first (OTA-DEPLOY.md) or upload it again with the right Min native.',
      );
      return;
    }
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
    } catch (e: unknown) {
      this.toast.error(apiErrorMessage(e, 'Unable to update the bundle.'));
    }
  }

  /**
   * One-click rollback to `bundle`. Previews the version the server will
   * assign (next after the highest ever published for this platform/channel);
   * the server computes the real one and the toast reports it.
   */
  async rollbackTo(bundle: OtaBundle): Promise<void> {
    if (this.rollingBackId() !== null) return;
    const content = bundle.rollback_of_version ?? bundle.version;
    const impact = otaRollbackImpact(bundle, this.bundles());
    const range = this.nativeRange(bundle);
    if (impact.matchesNoApp) {
      // The button is disabled for these and the API refuses them: the
      // rollback would reach no device while still pulling the live bundle.
      this.toast.error(
        `${content} is published for native app version ${range}, which no installed app reports, so a rollback to it would reach no device. ` +
          'Widen its Max native first (OTA-DEPLOY.md) or upload that content again as a new version with the right Min native.',
      );
      return;
    }
    const nextVersion = this.previewRollbackVersion(bundle);
    const label = (b: OtaBundle) => `${b.version} (app ${this.nativeRange(b)})`;
    const pulled = impact.deactivated.length
      ? `deactivates the ${bundle.platform} / ${bundle.channel} ${impact.deactivated.length === 1 ? 'bundle' : 'bundles'} ` +
        `for overlapping app versions (${impact.deactivated.map(label).join(', ')}); bundles for other app versions stay active`
      : `deactivates no other bundle (none is active for overlapping app versions)`;
    const stranded = impact.strandedLive.length
      ? ` ${impact.strandedLive.map(label).join(', ')} also ${impact.strandedLive.length === 1 ? 'serves' : 'serve'} ` +
        `app versions outside ${range}: those app builds are then offered only another active bundle that covers them, if any.`
      : '';
    const ok = await this.confirm.confirm({
      title: `Roll back ${bundle.platform} to ${content}`,
      message:
        `This republishes the ${content} bundle as version ${nextVersion} and ${pulled}. It keeps its native range ` +
        `(app version ${range}): only those app builds get it.${stranded} Devices download it the next time the app is opened ` +
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
    } catch (e: unknown) {
      this.toast.error(apiErrorMessage(e, 'Unable to roll back.'));
    } finally {
      this.rollingBackId.set(null);
    }
  }

  /** The next version after the highest published for the bundle's platform/channel. */
  private previewRollbackVersion(bundle: OtaBundle): string {
    const scoped = this.bundles().filter(
      (b) => b.app_id === bundle.app_id && b.platform === bundle.platform && b.channel === bundle.channel,
    );
    const highest = scoped.reduce((max, b) => (compareOtaVersions(b.version, max) > 0 ? b.version : max), bundle.version);
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
    } catch (e: unknown) {
      this.toast.error(apiErrorMessage(e, 'Unable to delete the bundle.'));
    }
  }

  shortChecksum(checksum: string): string {
    return checksum ? checksum.slice(0, 10) + '…' : '—';
  }

  goBack(): void {
    this.navHistory.back('/account');
  }
}
