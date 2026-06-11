import { Component, inject, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NgClass, NgIf } from '@angular/common';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { TranslatePipe } from '../../translate.pipe';

import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
export interface Measurements {
  id: number;
  token: number;
  measurement: number;
  size: string;
  bust: number;
  waist: number;
  hip: number;
  length: number;
  neck: number;
  arm: number;
  armhole: number;
  shoulder: number;
}

@Component({
  selector: 'app-measurements',
  standalone: true,
  imports: [VendorShellComponent, FormsModule, NgIf, NgClass, TranslatePipe],
  templateUrl: './measurements.component.html',
  styleUrl: './measurements.component.css',
})
export class MeasurementsComponent implements OnInit {
  measurements?: Measurements[];
  private readonly confirm = inject(AxConfirmService);

  constructor(
    private router: Router,
    private crudService: CrudService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_empty: false,
    editing: false,
    is_updating: false,
    deleting: false,
  };

  create = {
    id: 0, token: '',
    size: '',
    bust: 0, waist: 0, hip: 0, length: 0,
    neck: 0, arm: 0, armhole: 0, shoulder: 0,
  };

  update = {
    id: 0, token: '',
    size: '', measurement: 0,
    bust: 0, waist: 0, hip: 0, length: 0,
    neck: 0, arm: 0, armhole: 0, shoulder: 0,
  };

  delete_measure = { id: 0, token: '', measurement: 0 };

  read = { id: 0, store: 0, token: '' };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '', avatar: '',
    id_front: '', id_back: '', license_doc: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
    this.read_measurements();
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

  create_measurements() {
    this.create.id = this.user_session.id;
    this.create.token = this.user_session.token;
    if (this.create.size.length === 0) {
      this.error_notification('Size is required');
      return;
    }
    this.ui_controls.is_empty = false;
    this.ui_controls.is_creating = true;
    const msValues = { size: this.create.size, bust: this.create.bust, waist: this.create.waist, hip: this.create.hip, length: this.create.length, neck: this.create.neck, arm: this.create.arm, armhole: this.create.armhole, shoulder: this.create.shoulder };
    this.adapter.post_v3('POST /vendor/measurements', { values: msValues }).subscribe({
      next: (response: any) => {
        this.ui_controls.is_creating = false;
        if (response?.data?.id || response?.response_code === 200) {
          this.ui_controls.is_empty = false;
          this.success_notification('Measurement saved');
          this.read_measurements();
        } else if ([503, 401, 402, 400].includes(response.response_code) && response.status === 'failed') {
          this.ui_controls.is_empty = true;
          this.error_notification(response.message);
          if (response.response_code === 402) this.read_measurements();
        }
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_creating = false;
      },
    });
  }

  read_measurements() {
    this.read.id = this.user_session.id;
    this.read.store = this.user_session.id;
    this.read.token = this.user_session.token;
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = true;
    this.adapter.get_v3('GET /vendor/measurements').subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.measurements = Array.isArray(response.data) ? response.data : [];
          this.ui_controls.is_empty = !this.measurements || this.measurements.length === 0;
        } else if ([503, 401, 400].includes(response.response_code) && response.status === 'failed') {
          this.ui_controls.is_empty = true;
          this.error_notification(response.message);
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.is_empty = true;
      },
    });
  }

  edit_measurement(
    size: string, measurement: number,
    bust: number, waist: number, hip: number, length: number,
    neck: number, arm: number, armhole: number, shoulder: number,
  ) {
    this.update = {
      id: this.user_session.id,
      token: this.user_session.token,
      measurement, size, bust, waist, hip, length,
      neck, arm, armhole, shoulder,
    };
    this.ui_controls.editing = true;
  }

  start_delete_measurement(measurement: number) {
    this.confirm
      .confirm({
        title: 'Confirm delete',
        message: 'Your measurement will be deleted permanently',
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((response) => {
        if (response) this.delete_measurement(measurement);
      });
  }

  delete_measurement(id: number) {
    this.ui_controls.deleting = true;
    this.delete_measure.id = this.user_session.id;
    this.delete_measure.token = this.user_session.token;
    this.delete_measure.measurement = id;
    const delMid = this.delete_measure.measurement ?? this.delete_measure.id;
    this.adapter.delete_v3('DELETE /vendor/measurements/:id', { params: { id: String(delMid) } }).subscribe({
      next: (response: any) => {
        if (response?.data || response?.response_code === 200) {
          this.success_notification('Measurement deleted');
          this.read_measurements();
        }
        this.ui_controls.deleting = false;
      },
      error: () => {
        this.ui_controls.deleting = false;
      },
    });
  }

  update_measurements() {
    this.ui_controls.is_updating = true;
    const updMid = this.update.measurement ?? this.update.id;
    const updValues = { size: this.update.size, bust: this.update.bust, waist: this.update.waist, hip: this.update.hip, length: this.update.length, neck: this.update.neck, arm: this.update.arm, armhole: this.update.armhole, shoulder: this.update.shoulder };
    this.adapter.put_v3('PUT /vendor/measurements/:id', { values: updValues }, { params: { id: String(updMid) } }).subscribe({
      next: (response: any) => {
        this.ui_controls.is_updating = false;
        if (response?.data?.id || response?.response_code === 200) {
          this.ui_controls.editing = false;
          this.success_notification('Measurement updated');
          this.read_measurements();
        } else if ([401, 400].includes(response.response_code) && response.status === 'failed') {
          this.ui_controls.editing = false;
          this.error_notification(response.message);
        }
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_updating = false;
        this.ui_controls.editing = false;
      },
    });
  }

  cancel_edit() {
    this.ui_controls.editing = false;
  }
}
