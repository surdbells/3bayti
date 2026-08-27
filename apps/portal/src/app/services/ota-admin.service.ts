import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders, HttpRequest, HttpEventType } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { PortalCrudAdapter } from './portal-crud-adapter';

export interface OtaBundle {
  id: number;
  app_id: string;
  platform: 'android' | 'ios';
  channel: string;
  version: string;
  url: string;
  checksum: string;
  min_native_version: string;
  signed: boolean;
  is_active: boolean;
  created_at: string;
}

export interface OtaUploadMeta {
  /** `both` publishes the same bundle to android + ios in one upload. */
  platform: 'android' | 'ios' | 'both';
  version: string;
  channel?: string;
  min_native?: string;
  /** Signed bundles only, the ivSessionKey from `@capgo/cli encrypt`. */
  session_key?: string;
  /** Signed bundles only, the checksum from `@capgo/cli encrypt`. */
  checksum?: string;
}

/**
 * Admin client for the self-hosted OTA endpoints (/v3/admin/ota/bundles).
 * Direct HttpClient calls, like ImageUploadService, because the upload is
 * multipart and these routes aren't in the generated typed route-key registry.
 * The bearer token + base URL are read from PortalCrudAdapter so auth stays in
 * one place.
 */
@Injectable({ providedIn: 'root' })
export class OtaAdminService {
  constructor(
    private http: HttpClient,
    private adapter: PortalCrudAdapter,
  ) {}

  private headers(): HttpHeaders {
    return new HttpHeaders({ Authorization: `Bearer ${this.adapter.getToken()}` });
  }

  private base(): string {
    return this.adapter.getV3BaseUrl();
  }

  async list(): Promise<OtaBundle[]> {
    const res: any = await firstValueFrom(
      this.http.get(`${this.base()}/v3/admin/ota/bundles`, { headers: this.headers() }),
    );
    return (res?.bundles ?? []) as OtaBundle[];
  }

  /**
   * Upload + publish a bundle. Streams `onProgress` (0-100) via HttpClient
   * upload events for the UI progress bar. Returns the created bundle rows -
   * one for a single platform, two when `platform: 'both'`.
   */
  async upload(
    file: File,
    meta: OtaUploadMeta,
    onProgress?: (percent: number) => void,
  ): Promise<OtaBundle[]> {
    const form = new FormData();
    form.append('file', file, file.name);

    const q = new URLSearchParams();
    q.set('platform', meta.platform);
    q.set('version', meta.version.trim());
    if (meta.channel) q.set('channel', meta.channel.trim());
    if (meta.min_native) q.set('min_native', meta.min_native.trim());
    if (meta.session_key) q.set('session_key', meta.session_key.trim());
    if (meta.checksum) q.set('checksum', meta.checksum.trim());

    const req = new HttpRequest(
      'POST',
      `${this.base()}/v3/admin/ota/bundles?${q.toString()}`,
      form,
      { headers: this.headers(), reportProgress: true },
    );

    return new Promise<OtaBundle[]>((resolve, reject) => {
      this.http.request(req).subscribe({
        next: (event) => {
          if (event.type === HttpEventType.UploadProgress) {
            const pct = event.total ? Math.round((event.loaded / event.total) * 100) : 0;
            onProgress?.(pct);
          } else if (event.type === HttpEventType.Response) {
            const body: any = event.body;
            // Server returns {bundle} for one platform, {bundles:[…]} for both.
            const list: OtaBundle[] = body?.bundles ?? (body?.bundle ? [body.bundle] : []);
            resolve(list);
          }
        },
        error: (err) => reject(err),
      });
    });
  }

  async setActive(id: number, isActive: boolean): Promise<OtaBundle> {
    const res: any = await firstValueFrom(
      this.http.patch(
        `${this.base()}/v3/admin/ota/bundles/${id}`,
        { is_active: isActive },
        { headers: this.headers() },
      ),
    );
    return res?.bundle as OtaBundle;
  }

  async remove(id: number): Promise<void> {
    await firstValueFrom(
      this.http.delete(`${this.base()}/v3/admin/ota/bundles/${id}`, { headers: this.headers() }),
    );
  }
}
