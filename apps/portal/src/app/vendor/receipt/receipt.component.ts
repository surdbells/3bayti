import {Component, inject, OnInit} from '@angular/core';
import {NgIf} from "@angular/common";
import {VendorShellComponent} from "../../partials/vendor-shell/vendor-shell.component";
import {ActivatedRoute, Router} from '@angular/router';
import {PortalCrudAdapter} from '../../services/portal-crud-adapter';
import {HotToastService} from '../../shared/toast/toast.service';
import {GlobalComponent} from '../../global-component';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import { AxConfirmService } from '../../shared/overlays';
import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-receipt',
  standalone: true,
    imports: [
        NgIf,
        VendorShellComponent, IconComponent],
  templateUrl: './receipt.component.html',
  styleUrl: './receipt.component.css'
})
export class ReceiptComponent   implements OnInit{
  private readonly confirm = inject(AxConfirmService);
  private readonly adapter = inject(PortalCrudAdapter);
  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}
  ui_controls = {
    is_loading: false,
    updating_order: false
  };
  session_data: any = ""
  image_url: any = "https://api.3bayti.ae/vendors/products/"
  user_session = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    store_name: "",
    store_address: "",
    store_phone: "",
    store_email: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  };
  data = {
    id: 0,
    token: "",
    order: 0,
    order_ref: "",
    product: 0,
    name: "",
    image: "",
    price: "",
    quantity: 0,
    total: "",
    charges: "",
    vendor_pay: "",
    size: "",
    color: "",
    measurement: {
      bust: "",
      neck: "",
      waist: "",
      length: "",
      hip: "",
      arm: ""
    },
    extra_measurement: "",
    note: "",
    customer_email: "",
    customer_name: "",
    merchantReference: "",
    delivery_name: "",
    delivery_phone: "",
    delivery_email: "",
    delivery_city: "",
    delivery_area: "",
    delivery_street_address: "",
    villa_number: "",
    payment_status: "",
    cart_code: "",
    status: ""
  };
  single = {
    id: 0,
    token: "",
    order: 0,
    product: "",
  };
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.single.id = this.user_session.id;
    this.single.token = this.user_session.token;

    this.single.order =  Number(this.route.snapshot.queryParamMap.get('id'));
    this.single.product =  String(this.route.snapshot.queryParamMap.get('name'));
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
    const orderId = this.single.order;
    this.adapter.get_v3('GET /vendor/orders/:id', { params: { id: String(orderId) } }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.data = this.mapV3Order(response.data);
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.error_notification('Failed to load order.');
      },
    });
  }

  /** Flatten a v3 order into the flat fields the invoice template reads. */
  private mapV3Order(o: any): any {
    const items = o.items ?? [];
    const first = items[0] ?? {};
    const ship = o.shipping_address ?? o.billing_address ?? {};
    const customer = o.customer ?? {};
    return {
      ...o,
      order_ref: o.order_reference ?? o.order_ref,
      order: o.id,
      merchant: o.merchant_reference ?? o.merchantReference ?? '',
      merchantReference: o.merchant_reference ?? '',
      cart_code: o.cart_code ?? o.order_reference ?? '',
      status: o.status,
      // Primary line item (the invoice lists items via data.items too).
      name: first.product_name ?? '',
      size: first.size ?? '',
      color: first.color ?? '',
      quantity: items.reduce((s: number, i: any) => s + (i.quantity ?? 1), 0),
      price: first.unit_price ?? '',
      total: o.total ?? o.subtotal ?? '',
      note: first.note ?? '',
      // Delivery address (flattened from v3 shipping_address).
      delivery_name: `${ship.first_name ?? ''} ${ship.last_name ?? ''}`.trim() ||
        `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim(),
      delivery_street_address: ship.street ?? '',
      villa_number: ship.villa_number ?? '',
      delivery_area: ship.state_province ?? '',
      delivery_city: ship.city ?? '',
      delivery_phone: ship.phone ?? customer.phone ?? '',
      delivery_email: ship.email ?? customer.email ?? '',
      items,
    };
  }
  printInvoice() {
    window.print();
  }
  // Download PDF using html2canvas + jsPDF
  async downloadPdf() {
    const element = document.getElementById('invoiceToPrint');
    if (!element) { return; }

    // improve scale for higher resolution
    const scale = 2;
    const width = element.offsetWidth;
    const height = element.offsetHeight;

    try {
      const canvas = await html2canvas(element, {
        scale,
        useCORS: true,
        logging: false,
        windowWidth: document.documentElement.offsetWidth,
        windowHeight: document.documentElement.offsetHeight
      });

      const imgData = canvas.toDataURL('image/png');
      const pdf = new jsPDF({
        orientation: width > height ? 'landscape' : 'portrait',
        unit: 'px',
        format: [width, height]
      });

      pdf.addImage(imgData, 'PNG', 0, 0, width, height);
      const filename = `invoice_${this.data?.order_ref || this.data?.order || this.data?.id || 'invoice'}.pdf`;
      pdf.save(filename);
    } catch (err) {
      console.error('PDF generation failed', err);
      alert('Could not generate PDF. Please try again or check console for details.');
    }
  }
}
