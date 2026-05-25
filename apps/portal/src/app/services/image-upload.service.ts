import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { PortalCrudAdapter } from './portal-crud-adapter';

export type UploadContext = 'product' | 'vendor_logo' | 'vendor_cover';

export interface UploadedImage {
  storage_path: string;
  url: string;
  mime_type: string;
  size_bytes: number;
}

/**
 * Thin service that POSTs a File to POST /v3/upload and returns
 * the canonical storage URL. All components that handle image
 * picking go through here so the base-URL and auth header are
 * wired in one place.
 *
 * Usage:
 *   const result = await this.imageUpload.upload(file, 'product');
 *   // result.url === "https://api-v3.3bayti.ae/uploads/products/my-store/01J....jpg"
 *   // Pass result.url directly as primary_image_url / image_urls[].
 */
@Injectable({ providedIn: 'root' })
export class ImageUploadService {

  constructor(
    private http: HttpClient,
    private adapter: PortalCrudAdapter,
  ) {}

  /**
   * Upload a single File to the v3 API.
   * Throws on HTTP error or API-level error (non-2xx).
   */
  async upload(file: File, context: UploadContext = 'product'): Promise<UploadedImage> {
    const token = this.adapter.getToken();
    const form  = new FormData();
    form.append('image', file, file.name);

    const baseUrl = this.adapter.getV3BaseUrl();
    const url     = `${baseUrl}/v3/upload?context=${context}`;

    const headers = new HttpHeaders({ Authorization: `Bearer ${token}` });

    const response: any = await firstValueFrom(
      this.http.post(url, form, { headers })
    );

    const data = response?.data;
    if (!data?.url) {
      throw new Error('Upload response missing url field');
    }
    return data as UploadedImage;
  }
}
