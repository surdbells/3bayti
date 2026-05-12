import { Component, inject, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { Labels } from '../../class/labels';
import { FormsModule } from '@angular/forms';
import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
@Component({
  selector: 'app-labels',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule],
  templateUrl: './label-component.html',
  styleUrl: './label.component.css',
})
export class LabelComponent implements OnInit {
  labels?: Labels[];
  private readonly confirm = inject(AxConfirmService);

  ui_controls = {
    is_loading: false,
    is_creating_label: false,
    is_saving_label_id: 0 as number,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  vendor_labels = { id: 0, token: '' };
  vendor_label_create = { id: 0, token: '', label: '' };
  update = { id: 0, token: '', label_id: 0, label: '' };
  delete = { id: 0, token: '', label_id: 0 };

  /** Per-row edit state: label_id -> new text. */
  editMap: Record<number, string> = {};

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
    this.vendor_labels.id = this.user_session.id;
    this.vendor_labels.token = this.user_session.token;
    this.delete.id = this.user_session.id;
    this.delete.token = this.user_session.token;
    this.update.id = this.user_session.id;
    this.update.token = this.user_session.token;
    this.vendor_label_create.id = this.user_session.id;
    this.vendor_label_create.token = this.user_session.token;
    this.get_vendor_labels();
  }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  get_vendor_labels() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.vendor_labels, GlobalComponent.readLabel).subscribe({
      next: (response: any) => {
        this.labels = response.data;
        // Seed edit map with current values
        this.editMap = {};
        for (const l of this.labels ?? []) {
          this.editMap[l.id] = l.label;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  create_vendor_labels() {
    if (!this.vendor_label_create.label.trim()) {
      this.error_notification('Label name is required.');
      return;
    }
    this.ui_controls.is_creating_label = true;
    this.crudService.post_request(this.vendor_label_create, GlobalComponent.createLabel).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.vendor_label_create.label = '';
          this.success_notification(response.message);
          this.get_vendor_labels();
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_creating_label = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_creating_label = false;
      },
    });
  }

  update_label(id: number) {
    const newText = (this.editMap[id] ?? '').trim();
    if (!newText) {
      this.error_notification('Label cannot be empty.');
      return;
    }
    this.update.label_id = id;
    this.update.label = newText;
    this.ui_controls.is_saving_label_id = id;
    this.crudService.post_request(this.update, GlobalComponent.updateLabel).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_vendor_labels();
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving_label_id = 0;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_saving_label_id = 0;
      },
    });
  }

  start_delete_label(id: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm delete',
        message: `Your label "${name}" will be deleted permanently.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((response) => {
        if (response) this.deleteLabel(id);
      });
  }

  deleteLabel(id: number) {
    this.ui_controls.is_loading = true;
    this.delete.label_id = id;
    this.crudService.post_request(this.delete, GlobalComponent.deleteLabel).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_vendor_labels();
        }
      },
    });
  }
}
