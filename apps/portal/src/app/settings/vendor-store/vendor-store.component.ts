import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import imageCompression from 'browser-image-compression';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { ImageUploadService } from '../../services/image-upload.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AxRichEditorComponent } from '../../shared/rich/ax-rich-editor.component';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { CfImagePipe } from '../../shared/cf-image.pipe';
import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';
@Component({
  selector: 'app-vendor-store',
  standalone: true,
  imports: [CfImagePipe, VendorShellComponent, CommonModule, FormsModule, AxRichEditorComponent, IconComponent],
  templateUrl: './vendor-store.component.html',
  styleUrl: './vendor-store.component.css',
})
export class VendorStoreComponent implements OnInit {
  base64String: any;

  ui_controls = {
    is_loading: false,
    is_saving_basic: false,
    is_saving_status: false,
    is_uploading_logo: false,
    is_uploading_cover: false,
  };

  private static readonly PLACEHOLDER = 'assets/img/placeholder-1.png';

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  store_single = {
    store_name: '',
    store_address: '',
    store_status: false,
    store_approved: false,
    store_email: '',
    store_phone: '',
    store_description: '',
    store_logo: 'assets/img/placeholder-1.png',
    store_cover: 'assets/img/placeholder-1.png',
  };

  get_single = { id: 0, token: '' };

  update_store = {
    id: 0, token: '',
    store_name: '',
    store_address: '',
    store_email: '',
    store_phone: '',
    store_description: '',
    store_logo: 'assets/img/placeholder-1.png',
    store_cover: 'assets/img/placeholder-1.png',
  };

  update_status = {
    id: 0, token: '',
    store_status: false,
  };

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private imageUpload: ImageUploadService,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
    this.get_single.id = this.user_session.id;
    this.get_single.token = this.user_session.token;
    this.get_data();
  }

  goBack() {
    this.navHistory.back('/account');
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  get_data() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/store').subscribe({
      next: (response: any) => {
        if (response?.data) {
          const d = response.data;
          this.store_single = {
            ...this.store_single,
            store_name: d.name ?? d.store_name ?? '',
            store_description: d.description ?? d.store_description ?? '',
            store_email: d.contact_email ?? d.store_email ?? '',
            store_phone: d.contact_phone ?? d.store_phone ?? '',
            // Fall back to the placeholder (not '') so an unset image renders a
            // real placeholder instead of a broken <img>.
            store_logo: d.logo_url || d.store_logo || VendorStoreComponent.PLACEHOLDER,
            store_cover: d.cover_image_url || d.store_cover || VendorStoreComponent.PLACEHOLDER,
            store_status: !!d.is_active,
            // Approval state drives the badge + the visibility controls. The
            // vendor lifecycle `status` is 'pending' | 'approved' | 'suspended';
            // only an approved store may change its own visibility.
            store_approved: (d.status ?? d.store_status) === 'approved',
          };
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_loading = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_loading = false;
      },
    });
  }

  update_changes() {
    this.update_store.id = this.user_session.id;
    this.update_store.token = this.user_session.token;
    this.update_store.store_name = this.store_single.store_name;
    this.update_store.store_address = this.store_single.store_address;
    this.update_store.store_email = this.store_single.store_email;
    this.update_store.store_phone = this.store_single.store_phone;
    this.update_store.store_description = this.store_single.store_description;
    this.update_store.store_logo = this.store_single.store_logo;
    this.update_store.store_cover = this.store_single.store_cover;

    this.ui_controls.is_saving_basic = true;
    const isRealImage = (u: string) => !!u && !u.includes('placeholder');
    const body: Record<string, unknown> = {
      store_name: this.store_single.store_name,
      store_description: this.store_single.store_description,
      store_email: this.store_single.store_email,
      store_phone: this.store_single.store_phone,
    };
    // Only send real uploaded image URLs — never the local placeholder path
    // (it would be stored as the logo). Omitting the field is a no-op PATCH, so
    // an existing image is left untouched.
    if (isRealImage(this.store_single.store_logo)) body['store_logo'] = this.store_single.store_logo;
    if (isRealImage(this.store_single.store_cover)) body['store_cover'] = this.store_single.store_cover;
    this.adapter.patch_v3('PATCH /vendor/store', body).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.success_notification('Store updated successfully');
          this.get_data();
        }
        this.ui_controls.is_saving_basic = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_saving_basic = false;
      },
    });
  }

  change_store_status() {
    this.update_status.id = this.user_session.id;
    this.update_status.token = this.user_session.token;
    this.update_status.store_status = this.store_single.store_status;

    this.ui_controls.is_saving_status = true;
    this.adapter.patch_v3('PATCH /vendor/store/status', { store_status: this.store_single.store_status }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.success_notification('Store status updated.');
          this.get_data();
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving_status = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_saving_status = false;
      },
    });
  }

  select_logo(event: any) {
    this.uploadImage(event, 'vendor_logo', 512, 'is_uploading_logo', (url) => (this.store_single.store_logo = url));
  }

  select_cover(event: any) {
    this.uploadImage(event, 'vendor_cover', 1600, 'is_uploading_cover', (url) => (this.store_single.store_cover = url));
  }

  /**
   * Compress the picked file and upload it via POST /v3/upload, then store the
   * returned URL. The API caps logo/cover at 500 chars, so the previous base64
   * data-URL approach always failed validation — uploads return a short CDN URL
   * that saves cleanly. The URL is applied to the preview; "Save changes"
   * persists it.
   */
  private async uploadImage(
    event: any,
    context: 'vendor_logo' | 'vendor_cover',
    maxWidthOrHeight: number,
    flag: 'is_uploading_logo' | 'is_uploading_cover',
    apply: (url: string) => void,
  ): Promise<void> {
    const file: File | undefined = event?.target?.files?.[0];
    if (!file) return;

    this.ui_controls[flag] = true;
    try {
      const compressed = await imageCompression(file, {
        maxSizeMB: 1,
        maxWidthOrHeight,
        useWebWorker: true,
      });
      const result = await this.imageUpload.upload(compressed, context);
      apply(result.url);
      this.success_notification('Image uploaded — click Save changes to apply.');
    } catch (err: any) {
      this.error_notification(apiErrorMessage(err, 'Could not upload the image. Please try again.'));
    } finally {
      this.ui_controls[flag] = false;
      // Allow re-picking the same file after an error.
      if (event?.target) event.target.value = '';
    }
  }
}
