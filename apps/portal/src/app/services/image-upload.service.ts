import { Injectable } from '@angular/core';
import { HttpClient, HttpEvent, HttpEventType, HttpHeaders, HttpResponse } from '@angular/common/http';
import { lastValueFrom, tap } from 'rxjs';
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
   *
   * @param onProgress optional callback invoked with the upload percentage
   *        (0–100) as bytes are sent, so callers can render a progress bar.
   */
  async upload(
    file: File,
    context: UploadContext = 'product',
    onProgress?: (percent: number) => void,
  ): Promise<UploadedImage> {
    const token = this.adapter.getToken();
    const form  = new FormData();
    form.append('image', file, file.name);

    const baseUrl = this.adapter.getV3BaseUrl();
    const url     = `${baseUrl}/v3/upload?context=${context}`;

    const headers = new HttpHeaders({ Authorization: `Bearer ${token}` });

    // observe:'events' + reportProgress streams UploadProgress events so we can
    // surface real byte-level progress; the final Response carries the body.
    let body: any = null;
    await lastValueFrom(
      this.http
        .post(url, form, { headers, observe: 'events', reportProgress: true })
        .pipe(
          tap((event: HttpEvent<any>) => {
            if (event.type === HttpEventType.UploadProgress) {
              const total = event.total ?? file.size;
              if (onProgress && total > 0) {
                onProgress(Math.min(100, Math.round((event.loaded / total) * 100)));
              }
            } else if (event.type === HttpEventType.Response) {
              body = (event as HttpResponse<any>).body;
            }
          }),
        ),
    );

    const data = body?.data;
    if (!data?.url) {
      throw new Error('Upload response missing url field');
    }
    return data as UploadedImage;
  }
}
