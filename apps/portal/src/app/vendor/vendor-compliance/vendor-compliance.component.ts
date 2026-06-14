import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { TranslatePipe } from '../../translate.pipe';
import imageCompression from 'browser-image-compression';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxAccordionComponent,
  AxAccordionItemComponent,
} from '../../shared/overlays';

@Component({
  selector: 'app-vendor-compliance',
  standalone: true,
  imports: [
    VendorShellComponent,
    FormsModule,
    CommonModule,
    TranslatePipe,
    AxAccordionComponent,
    AxAccordionItemComponent, IconComponent],
  templateUrl: './vendor-compliance.component.html',
  styleUrl: './vendor-compliance.component.css',
})
export class VendorComplianceComponent implements OnInit {
  base64String: any;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    is_submitting: false,
  };

  compliance = {
    id: 0,
    token: '',
    front: 'assets/img/placeholder-1.png',
    back: 'assets/img/placeholder-1.png',
    license_doc: 'assets/img/placeholder-1.png',
  };

  get_single = { id: 0, token: '' };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '', avatar: '',
    id_front: '', id_back: '', license_doc: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  compliance_status = 'pending';
  review_note: string | null = null;

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }

    // Documents are loaded fresh from v3 (get_data), not the session blob.
    this.get_single.id = this.user_session.id;
    this.get_single.token = this.user_session.token;
    this.get_data();
  }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_data() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/compliance').subscribe({
      next: (response: any) => {
        const d = response?.data ?? {};
        const ph = 'assets/img/placeholder-1.png';
        this.compliance.front = d.front || ph;
        this.compliance.back = d.back || ph;
        this.compliance.license_doc = d.license_doc || ph;
        this.compliance_status = d.compliance_status ?? 'pending';
        this.review_note = d.review_note ?? null;
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
      },
    });
  }

  upload_id() {
    if (this.compliance.front.length < 40) {
      this.error_notification('ID Front is required');
      return;
    }
    if (this.compliance.back.length < 40) {
      this.error_notification('ID Back is required');
      return;
    }

    this.ui_controls.is_submitting = true;
    this.adapter.patch_v3('PATCH /vendor/compliance', {
      front: this.compliance.front,
      back: this.compliance.back,
      license_doc: this.compliance.license_doc,
    }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.success_notification('Compliance submitted.');
          this.get_data();
        } else {
          this.error_notification('Unable to submit compliance.');
        }
        this.ui_controls.is_submitting = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_submitting = false;
      },
    });
  }

  async select_front(event: any) {
    const file: File = event.target.files[0];
    if (!file) return;
    try {
      const compressed = await imageCompression(file, {
        maxSizeMB: 1,
        maxWidthOrHeight: 650,
        useWebWorker: true,
      });
      const reader = new FileReader();
      reader.onloadend = () => {
        this.compliance.front = reader.result as string;
      };
      reader.readAsDataURL(compressed);
    } catch (error) {
      this.error_notification('Image compression failed: ' + error);
    }
  }

  async select_back(event: any) {
    const file: File = event.target.files[0];
    if (!file) return;
    try {
      const compressed = await imageCompression(file, {
        maxSizeMB: 1,
        maxWidthOrHeight: 650,
        useWebWorker: true,
      });
      const reader = new FileReader();
      reader.onloadend = () => {
        this.compliance.back = reader.result as string;
      };
      reader.readAsDataURL(compressed);
    } catch (error) {
      this.error_notification('Image compression failed: ' + error);
    }
  }

  async select_license_doc(event: any) {
    const file: File = event.target.files[0];
    if (!file) return;
    try {
      const compressed = await imageCompression(file, {
        maxSizeMB: 1,
        maxWidthOrHeight: 650,
        useWebWorker: true,
      });
      const reader = new FileReader();
      reader.onloadend = () => {
        this.compliance.license_doc = reader.result as string;
      };
      reader.readAsDataURL(compressed);
    } catch (error) {
      this.error_notification('Image compression failed: ' + error);
    }
  }
}
