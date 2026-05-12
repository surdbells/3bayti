import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Products } from '../../class/products';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { FormsModule } from '@angular/forms';

import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-admin-products',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule],
  templateUrl: './admin-products.component.html',
  styleUrl: './admin-products.component.css',
})
export class AdminProductsComponent implements OnInit {
  products?: Products[];

  ui_controls = {
    is_loading: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  store_name: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  product = { token: '', id: 0 };
  product_S = { token: '', id: 0, status: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.product.token = this.user_session.token;
    this.product.id = this.user_session.id;
    this.product_S.token = this.user_session.token;
    this.product_S.id = this.user_session.id;
    this.get_product();
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

  get_product() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.product, GlobalComponent.getAdminProducts).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.products = response.data ?? [];
          this.ui_controls.no_data = !this.products || this.products.length === 0;
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

  get_product_status(event: Event) {
    this.product_S.status = (event.target as HTMLSelectElement).value;
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.products = [];
    this.crudService.post_request(this.product_S, GlobalComponent.getAdminProducts).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.products = response.data ?? [];
          this.ui_controls.no_data = !this.products || this.products.length === 0;
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

  manage_store(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/manage_store'], { queryParams: { id, name } });
    const fullUrl = this.router.serializeUrl(urlTree);
    window.open(fullUrl, '_blank');
  }

  // Status / stock badge helpers
  stockBadge(status: string): string {
    switch (status) {
      case 'in_stock':     return 'ax-badge ax-badge-success';
      case 'out_of_stock': return 'ax-badge ax-badge-danger';
      case 'on_backorder': return 'ax-badge ax-badge-warning';
      default:             return 'ax-badge ax-badge-neutral';
    }
  }

  stockLabel(status: string): string {
    switch (status) {
      case 'in_stock':     return 'In stock';
      case 'out_of_stock': return 'Out of stock';
      case 'on_backorder': return 'On backorder';
      default:             return status;
    }
  }

  statusBadge(status: string): string {
    switch (status) {
      case 'published': return 'ax-badge ax-badge-info';
      case 'draft':     return 'ax-badge ax-badge-warning';
      case 'deleted':   return 'ax-badge ax-badge-danger';
      default:          return 'ax-badge ax-badge-neutral';
    }
  }
}
