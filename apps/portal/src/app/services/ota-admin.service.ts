import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
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
  platform: 'android' | 'ios';
  version: string;
  channel?: string;
  min_native?: string;
  /** Signed bundles only — the ivSessionKey from `@capgo/cli encrypt`. */
  session_key?: string;
  /** Signed bundles only — the checksum from `@capgo/cli encrypt`. */
  checksum?: string;
}

/**
 * Admin client for the self-hosted OTA endpoints (/v3/admin/ota/bundles).
 * Direct HttpClient calls — like ImageUploadService — because the upload is
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

  async upload(file: File, meta: OtaUploadMeta): Promise<OtaBundle> {
    const form = new FormData();
    form.append('file', file, file.name);

    const q = new URLSearchParams();
    q.set('platform', meta.platform);
    q.set('version', meta.version.trim());
    if (meta.channel) q.set('channel', meta.channel.trim());
    if (meta.min_native) q.set('min_native', meta.min_native.trim());
    if (meta.session_key) q.set('session_key', meta.session_key.trim());
    if (meta.checksum) q.set('checksum', meta.checksum.trim());

    const res: any = await firstValueFrom(
      this.http.post(`${this.base()}/v3/admin/ota/bundles?${q.toString()}`, form, { headers: this.headers() }),
    );
    return res?.bundle as OtaBundle;
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
