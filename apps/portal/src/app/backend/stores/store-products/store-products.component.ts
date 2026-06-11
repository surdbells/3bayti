import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { Products } from '../../../class/products';

import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-store-products',
  standalone: true,
  imports: [AdminShellComponent, CommonModule],
  templateUrl: './store-products.component.html',
  styleUrl: './store-products.component.css',
})
export class StoreProductsComponent implements OnInit {
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

  product = { token: '', id: 0, store: 0 };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    const storeId = Number(this.route.snapshot.queryParamMap.get('id'));
    this.store_name = this.route.snapshot.queryParamMap.get('name');

    this.product.token = this.user_session.token;
    this.product.id = this.user_session.id;
    this.product.store = storeId;
    this.get_vendor_product();
  }

  goBack() {
    this.router.navigate(['/stores']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_vendor_product() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    const spId = this.product.store ?? this.product.id;
    this.adapter.get_v3('GET /vendors/by-legacy-id/:id/products', { params: { id: String(spId) }, query: { limit: 50, offset: 0 } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.products = response.data ?? response.products ?? [];
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

  openProduct(id: number, _name: string) {
    this.router
      .navigate(['/', 'adminviewproduct'], { queryParams: { id } })
      .then(r => console.log(r));
  }

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
}
