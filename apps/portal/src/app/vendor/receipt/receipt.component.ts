import {Component, inject, OnInit} from '@angular/core';
import {NgIf} from "@angular/common";
import {VendorShellComponent} from "../../partials/vendor-shell/vendor-shell.component";
import {ActivatedRoute, Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import { AxConfirmService } from '../../shared/overlays';
@Component({
  selector: 'app-receipt',
  standalone: true,
    imports: [
        NgIf,
        VendorShellComponent
    ],
  templateUrl: './receipt.component.html',
  styleUrl: './receipt.component.css'
})
export class ReceiptComponent   implements OnInit{
  private readonly confirm = inject(AxConfirmService);
  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private crudService: CrudService,
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
  update_order = {
    id: 0,
    order: 0,
    token: "",
    status: "",
    email: ""
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

    this.update_order.id = this.user_session.id;
    this.update_order.token = this.user_session.token;
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
    this.crudService.post_request(this.single, GlobalComponent.getOrderById)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.data =  response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
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
  startStatusChange(order: number, status: string, email: string) {
    this.confirm
      .confirm({
        title: 'Confirm status',
        message: 'Your order will be sent to delivery partner for pickup and delivery and customer will be notified',
        confirmLabel: 'Proceed',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response){
        this.updateOrderStatus(order, status, email);
        }
      });
  }
  updateOrderStatus(order: number, status: string, email: string){
    this.ui_controls.updating_order = true;
    this.update_order.order = order;
    this.update_order.status = status;
    this.update_order.email = email;
    this.crudService.post_request(this.update_order, GlobalComponent.updateOrderStatus)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.updating_order = false;
            this.success_notification(response.message);
            this.get_order_by_id();
          }
        }
      }))
  }
}
