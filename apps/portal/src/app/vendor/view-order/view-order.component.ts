import { Component, inject, OnInit } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import jsPDF from 'jspdf';
import html2canvas from 'html2canvas';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import {
  AxActivityFeedComponent,
  AxActivityItem,
  AxActivityVariant,
} from '../../shared/rich/ax-activity-feed.component';

/** A single event from GET /v3/vendor/orders/{id}/timeline (X.17-D). */
interface ApiTimelineEvent {
  type: string;
  actor_type: string;
  actor_label: string;
  description: string;
  created_at: string;
  payload?: Record<string, unknown>;
}
import { AxCopyToClipboardDirective } from '../../shared/rich/ax-copy-to-clipboard.directive';

import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
@Component({
  selector: 'app-view-order',
  standalone: true,
  imports: [
    VendorShellComponent,
    FormsModule,
    CommonModule,
    AxActivityFeedComponent,
    AxCopyToClipboardDirective,
  ],
  templateUrl: './view-order.component.html',
  styleUrl: './view-order.component.css',
})
export class ViewOrderComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  private readonly http = inject(HttpClient);

  /** Richer timeline from GET /v3/vendor/orders/{id}/timeline (X.17-D).
   *  Loaded after the legacy order fetch resolves. Falls back gracefully
   *  to the locally-derived timeline when the API call fails. */
  apiTimeline: AxActivityItem[] = [];
  timelineLoading = false;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    updating_order: false,
  };

  session_data: any = '';
  image_url: any = 'https://api.3bayti.ae/vendors/products/';

  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false,
  };

  data = {
    id: 0,
    token: '',
    order: 0,
    order_ref: '',
    product: 0,
    name: '',
    image: '',
    price: '',
    quantity: 0,
    total: '',
    charges: '',
    vendor_pay: '',
    size: '',
    color: '',
    measurement: {
      bust: '',
      neck: '',
      waist: '',
      length: '',
      hip: '',
      arm: '',
    },
    extra_measurement: '',
    note: '',
    customer_email: '',
    customer_name: '',
    merchantReference: '',
    delivery_name: '',
    delivery_phone: '',
    delivery_email: '',
    delivery_city: '',
    delivery_area: '',
    delivery_street_address: '',
    villa_number: '',
    payment_status: '',
    cart_code: '',
    status: '',
  };

  update_order = {
    id: 0,
    order: 0,
    token: '',
    status: '',
    email: '',
  };

  single = {
    id: 0,
    token: '',
    order: 0,
    product: '',
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.single.id = this.user_session.id;
    this.single.token = this.user_session.token;
    this.update_order.id = this.user_session.id;
    this.update_order.token = this.user_session.token;
    this.single.order = Number(this.route.snapshot.queryParamMap.get('id'));
    this.single.product = String(this.route.snapshot.queryParamMap.get('name'));
    this.get_order_by_id();
  }

  goBack() {
    this.router.navigate(['/orders']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_order_by_id() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.single, GlobalComponent.getOrderById).subscribe({
      next: (response) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.data = response.data;
          this.ui_controls.is_loading = false;
          this.loadApiTimeline();
        }
      },
    });
  }

  printInvoice() {
    window.print();
  }

  async downloadPdf() {
    const element = document.getElementById('invoiceToPrint');
    if (!element) return;

    const scale = 2;
    const width = element.offsetWidth;
    const height = element.offsetHeight;

    try {
      const canvas = await html2canvas(element, {
        scale,
        useCORS: true,
        logging: false,
        windowWidth: document.documentElement.offsetWidth,
        windowHeight: document.documentElement.offsetHeight,
      });

      const imgData = canvas.toDataURL('image/png');
      const pdf = new jsPDF({
        orientation: width > height ? 'landscape' : 'portrait',
        unit: 'px',
        format: [width, height],
      });

      pdf.addImage(imgData, 'PNG', 0, 0, width, height);
      const filename = `invoice_${this.data?.order_ref || this.data?.order || this.data?.id || 'invoice'}.pdf`;
      pdf.save(filename);
    } catch (err) {
      console.error('PDF generation failed', err);
      alert('Could not generate PDF. Please try again or check console for details.');
    }
  }

  startStatusChange(order: number, status: string, email: string) {
    this.confirm
      .confirm({
        title: 'Confirm status',
        message: 'Your order will be sent to delivery partner for pickup and delivery and customer will be notified',
        confirmLabel: 'Proceed',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) {
        this.updateOrderStatus(order, status, email);
        }
      });
  }

  updateOrderStatus(order: number, status: string, email: string) {
    this.ui_controls.updating_order = true;
    this.update_order.order = order;
    this.update_order.status = status;
    this.update_order.email = email;
    this.crudService.post_request(this.update_order, GlobalComponent.updateOrderStatus).subscribe({
      next: (response) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.ui_controls.updating_order = false;
          this.success_notification(response.message);
          this.get_order_by_id();
        }
      },
    });
  }

  /** Builds the status timeline. Each status in the set is either
   *  reached (active), upcoming, or skipped (for returns/cancellations). */
  get timeline(): AxActivityItem[] {
    const s = this.data.status || '';
    const reached = (check: string) => {
      const order = ['pending', 'Accepted', 'Ready for Delivery', 'Delivered'];
      const current = order.indexOf(s);
      const target = order.indexOf(check);
      return current >= target && current >= 0 && target >= 0;
    };

    const items: AxActivityItem[] = [
      {
        icon: 'shopping_bag',
        title: 'Order placed',
        variant: reached('pending') || s !== '' ? 'brand' : undefined,
      },
      {
        icon: 'check',
        title: 'Accepted by vendor',
        variant: reached('Accepted') ? 'success' : undefined,
      },
      {
        icon: 'inventory',
        title: 'Ready for delivery',
        variant: reached('Ready for Delivery') ? 'success' : undefined,
      },
      {
        icon: 'local_shipping',
        title: 'Delivered',
        variant: reached('Delivered') ? 'success' : undefined,
      },
    ];

    // Override with terminal states
    if (['Return Requested', 'Returned'].includes(s)) {
      items.push({
        icon: 'keyboard_return',
        title: s === 'Return Requested' ? 'Return requested' : 'Returned',
        variant: 'warning',
      });
    }
    if (['Cancelled', 'Refunded'].includes(s)) {
      items.push({
        icon: 'cancel',
        title: s,
        variant: 'danger',
      });
    }

    return items;
  }

  statusBadgeClass(): string {
    const s = this.data.status;
    switch (s) {
      case 'Accepted':
      case 'Ready for Delivery':
      case 'Delivered':
        return 'ax-badge-success';
      case 'Return Requested':
        return 'ax-badge-warning';
      case 'pending':
        return 'ax-badge-danger';
      case 'Returned':
      case 'Cancelled':
      case 'Refunded':
      default:
        return 'ax-badge-neutral';
    }
  }

  /**
   * Fetch the rich aggregated timeline from GET /v3/vendor/orders/{id}/timeline
   * (X.17-D). Converts the API events to AxActivityItem[] for display.
   * On any error the apiTimeline remains [] and the template falls back
   * to the locally-derived timeline (get timeline() above), so the page
   * never regresses below its pre-W.6 behaviour.
   */
  loadApiTimeline(): void {
    const orderId = this.single.order;
    if (!orderId || !this.user_session.token) return;
    this.timelineLoading = true;
    const headers = new HttpHeaders({ Authorization: `Bearer ${this.user_session.token}` });
    this.http
      .get<{ data: ApiTimelineEvent[]; meta: { total: number } }>(
        `https://api-v3.3bayti.ae/v3/vendor/orders/${orderId}/timeline`,
        { headers },
      )
      .subscribe({
        next: (res) => {
          this.apiTimeline = (res.data ?? []).map((e) => ({
            icon: this.timelineIcon(e.type),
            variant: this.timelineVariant(e.type),
            title: e.description,
            body: e.actor_label
              ? `<span style="font-size:12px;color:#6c757d">${e.actor_label}</span>`
              : undefined,
          } as AxActivityItem));
          this.timelineLoading = false;
        },
        error: () => {
          this.timelineLoading = false;
          // silent: falls back to get timeline()
        },
      });
  }

  private timelineIcon(type: string): string {
    const map: Record<string, string> = {
      'order.placed': 'shopping_bag',
      'order.paid': 'payments',
      'order.payment_failed': 'payment',
      'order.fulfilled': 'inventory',
      'item.shipped': 'local_shipping',
      'item.delivered': 'check_circle',
      'order.cancelled': 'cancel',
      'order.refunded': 'currency_exchange',
      'note.added': 'note',
      'status.changed': 'sync',
    };
    return map[type] ?? 'history';
  }

  private timelineVariant(type: string): AxActivityVariant {
    if (type.includes('paid') || type.includes('delivered')) return 'success';
    if (type.includes('failed') || type.includes('cancelled')) return 'danger';
    if (type.includes('refunded')) return 'warning';
    if (type.includes('placed') || type.includes('fulfilled')) return 'brand';
    return 'default';
  }
}
