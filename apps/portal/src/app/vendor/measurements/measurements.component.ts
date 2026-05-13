import { Component, inject, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NgClass, NgIf } from '@angular/common';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
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
    this.crudService.post_request(this.create, GlobalComponent.createMeasurement).subscribe({
      next: (response: any) => {
        this.ui_controls.is_creating = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.ui_controls.is_empty = false;
          this.success_notification(response.message);
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
    this.crudService.post_request(this.read, GlobalComponent.readMeasurement).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.measurements = response.data;
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
    this.crudService.post_request(this.delete_measure, GlobalComponent.deleteMeasurement).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
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
    this.crudService.post_request(this.update, GlobalComponent.updateMeasurement).subscribe({
      next: (response: any) => {
        this.ui_controls.is_updating = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.ui_controls.editing = false;
          this.success_notification(response.message);
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
