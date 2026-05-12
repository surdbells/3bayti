import { Component, inject, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { Stores } from '../../class/stores';
import { GlobalComponent } from '../../global-component';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxDropdownDirective,
  AxDropdownItemDirective,
} from '../../shared/overlays';

@Component({
  selector: 'app-stores',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    AxDropdownDirective,
    AxDropdownItemDirective,
  ],
  templateUrl: './stores.component.html',
  styleUrl: './stores.component.css',
})
export class StoresComponent implements OnInit {
  stores?: Stores[];
  private readonly confirm = inject(AxConfirmService);

  ui_controls = {
    is_loading: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_store: false,
  };

  get_data = { id: 0, token: '' };
  activate = { id: 0, token: '', store: 0, status: true };
  delete = { id: 0, token: '', store: 0, status: false };
  deactivate = { id: 0, token: '', store: 0, status: false };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.get_data.id = this.user_session.id;
    this.get_data.token = this.user_session.token;

    this.activate.id = this.user_session.id;
    this.activate.token = this.user_session.token;

    this.deactivate.id = this.user_session.id;
    this.deactivate.token = this.user_session.token;

    this.delete.id = this.user_session.id;
    this.delete.token = this.user_session.token;
    this.get_store();
  }

  goBack() {
    this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_store() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_data, GlobalComponent.getStores).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.stores = response.data;
          this.ui_controls.no_data = !this.stores || this.stores.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  start_activate(store: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name} account will be activated?`,
        confirmLabel: 'Activate',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.activate_store(store, name);
      });
  }

  activate_store(store: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.activate.store = store;
    this.crudService.post_request(this.activate, GlobalComponent.activateStore).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_store();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  start_deactivate(store: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name} will be deactivated?`,
        confirmLabel: 'Deactivate',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((response) => {
        if (response) this.deactivate_store(store, name);
      });
  }

  deactivate_store(store: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.deactivate.store = store;
    this.crudService.post_request(this.deactivate, GlobalComponent.deactivateStore).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_store();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  start_delete(store: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name} will be deleted?`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((response) => {
        if (response) this.delete_store(store, name);
      });
  }

  delete_store(store: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.delete.store = store;
    this.crudService.post_request(this.delete, GlobalComponent.deleteStore).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_store();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  store_orders(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/store_orders'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  store_messages(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/store_messages'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  manage_store(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/manage_store'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  store_tickets(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/store_tickets'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  store_reviews(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/store_reviews'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  store_products(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/store_products'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  store_sales(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/store_sales'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }
}
