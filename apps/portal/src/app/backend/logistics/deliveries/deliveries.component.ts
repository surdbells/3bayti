import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { prettyOrderStatus } from '../../shared/order-filters';

interface DeliveryOrder {
  id: number;
  order_reference: string;
  customer: string;
  destination: string;
  phone: string;
  items_count: number;
  status: string;
  date: string;
}

@Component({
  selector: 'app-deliveries',
  standalone: true,
  imports: [CommonModule, AdminShellComponent, IconComponent],
  templateUrl: './deliveries.component.html',
  styleUrl: './deliveries.component.css',
})
export class DeliveriesComponent implements OnInit {
  vendorId = '';
  orders: DeliveryOrder[] = [];
  ui = { loading: false, empty: false };

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.vendorId = this.route.snapshot.queryParamMap.get('vendor') ?? '';
    if (!this.vendorId) {
      this.ui.empty = true;
      return;
    }
    this.load();
  }

  load() {
    this.ui.loading = true;
    this.adapter
      .get_v3('GET /admin/orders', { query: { vendor_id: this.vendorId, limit: 100 } })
      .subscribe({
        next: (res: any) => {
          const raw: any[] = res?.orders ?? res?.data ?? [];
          this.orders = raw.map((o) => this.mapRow(o));
          this.ui.empty = this.orders.length === 0;
          this.ui.loading = false;
        },
        error: (err: any) => {
          this.toast.error(apiErrorMessage(err, 'Unable to load deliveries at this time.'));
          this.ui.loading = false;
        },
      });
  }

  private mapRow(o: any): DeliveryOrder {
    const c = o.customer ?? {};
    const name = `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
    const d = o.delivery ?? {};
    const dest = [d.city, d.area].filter(Boolean).join(', ');
    const items: any[] = o.items ?? [];
    return {
      id: o.id,
      order_reference: o.order_reference ?? String(o.id),
      customer: name || c.email || '—',
      destination: dest || '—',
      phone: d.phone ?? '',
      items_count: items.reduce((s, i) => s + (Number(i.quantity) || 0), 0) || items.length,
      status: o.status ?? '',
      date: o.date ?? o.created_at ?? '',
    };
  }

  prettyStatus(s: string): string {
    return prettyOrderStatus(s);
  }

  badgeClass(status: string): string {
    if (status === 'delivered') return 'ax-badge ax-badge-success';
    if (status === 'paid' || status === 'fulfilling' || status === 'shipped') return 'ax-badge ax-badge-info';
    if (status === 'pending_payment') return 'ax-badge ax-badge-warning';
    if (['cancelled', 'refunded', 'failed'].includes(status)) return 'ax-badge ax-badge-danger';
    return 'ax-badge ax-badge-neutral';
  }

  manage(o: DeliveryOrder) {
    this.router.navigate(['/single'], { queryParams: { order: o.id } });
  }

  goBack() {
    this.router.navigate(['/adminlogistics']);
  }
}
