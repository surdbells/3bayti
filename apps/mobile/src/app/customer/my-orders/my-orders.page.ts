import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonContent,
  IonHeader,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {TranslatePipe} from "../../translate.pipe";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router} from "@angular/router";
import {ActionSheetController, InfiniteScrollCustomEvent} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {Products} from "../../class/products";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
// M3.1.7-I: widen OrderStatus to include v3 backend's full enum so
// the cancel button can show conditionally on pending_payment. The
// FilterStatus chips still reflect the customer-friendly subset.
type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'processing'
  | 'fulfilling'
  | 'shipping'
  | 'shipped'
  | 'delivered'
  | 'cancelled'
  | 'refunded'
  | 'failed';
type FilterStatus = 'all' | 'processing' | 'shipping' | 'delivered';
interface OrderItem {
  product_id: number;
  store: number;
  product_name: string;
  price: number;
  quantity: number;
  status: string;
  product_image: string;
}

interface Order {
  id: string;
  date: Date;
  status: OrderStatus;
  items: OrderItem[];  // list of items in the order
  total: number;
  showItems?: boolean;// order total
}

@Component({
  selector: 'app-my-orders',
  templateUrl: './my-orders.page.html',
  styleUrls: ['./my-orders.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AppTabBarComponent
  ]
})
export class MyOrdersPage implements OnInit {
  orders: Order[] = [];
  // filter chips
  statuses: FilterStatus[] = ['all', 'processing', 'shipping', 'delivered'];
  selectedStatus: FilterStatus = 'all';
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private mobileAdapter: MobileNetworkAdapter,
  ) {}
  ui_controls = {
    is_empty: false,
    is_loading: false,
    is_creating: false
  }
  ngOnInit() {
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.initial.id = this.single_user.id;
      this.initial.token = this.single_user.token;
      this.order_listing();
    }
  }
  initial = {
    id: 0,
    token: "",
    limit: 10,
    offset: 0
  }
  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  }
  // mock orders
  order_listing() {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.limit = 10;
    this.initial.offset = 0;
    this.networkAdapter.post_request(this.initial, GlobalComponent.read_orders_listing)
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            // Dual-shape support for M3.1.6 strangler-fig migration:
            //   Legacy: response.data = orders[] (direct array)
            //   v3 (post-transform): response.data = {orders, pagination}
            this.orders = this.extractOrders(response.data);
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.orders.length === 0;
          } else {
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  // Extract orders array from either legacy (data=array) or v3
  // (data={orders, pagination}) response shapes. Defensive against
  // null/malformed inputs — returns [] rather than throwing.
  private extractOrders(data: any): any[] {
    if (Array.isArray(data)) return data;
    if (data && typeof data === 'object' && Array.isArray(data.orders)) {
      return data.orders;
    }
    return [];
  }
  toggleItems(index: number) {
    this.orders[index].showItems = !this.orders[index].showItems;
  }
  selectStatus(s: FilterStatus) {
    this.selectedStatus = s;
  }

  goBack() {
    this.nav.back();
  }

  onView(order: Order) {
    this.router.navigate(['/', 'orders', order.id]);
  }

  onReview(order: Order) {
    // open review modal / route
  }

  getMoreItems() {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit
    this.networkAdapter.post_request(this.initial, GlobalComponent.read_orders_listing)
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.orders.push(...this.extractOrders(response.data));
          }else{
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  onIonInfinite(event: InfiniteScrollCustomEvent) {
    this.getMoreItems();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }
  user_messages() {
    this.router.navigate(['/', 'messages']);
  }
  search() {
    this.router.navigate(['/', 'search']);
  }
  open_vendor(id: number, name: string) {
    this.router.navigate(
      ['/', 'vendor-reviews'],
      { queryParams: { id, name } }
    );
  }

  // ===================================================================
  // M3.1.7-F/I — Customer self-serve order cancellation
  // ===================================================================
  // Only orders in 'pending_payment' can be cancelled by the customer
  // themselves (paid orders require admin / support contact). The
  // template shows the button conditionally; this method runs the
  // confirmation + API call.
  //
  // On success:
  //   - Backend marks the order CANCELLED + audits the override
  //   - We update the local order's status optimistically so the UI
  //     reflects the change without a re-fetch round-trip
  //   - Toast confirms the cancellation
  //
  // On failure (network / 422 cancellation_not_allowed / 502 gateway):
  //   - Toast surfaces the message
  //   - Local order stays in its original state
  // ===================================================================
  canCancel(order: Order): boolean {
    return order.status === 'pending_payment';
  }

  async confirmCancel(order: Order) {
    const sheet = await this.actionSheetCtrl.create({
      header: 'Cancel this order?',
      subHeader: 'You have not been charged for pending orders.',
      buttons: [
        {
          text: 'Yes, cancel order',
          role: 'destructive',
          handler: () => {
            this.executeCancel(order);
            return true;
          },
        },
        {
          text: 'Keep order',
          role: 'cancel',
        },
      ],
    });
    await sheet.present();
  }

  private executeCancel(order: Order) {
    this.mobileAdapter
      .post_v3('POST /orders/:id/cancel', { reason: 'customer self-serve' }, {
        authToken: this.single_user.token,
        pathParams: { id: String(order.id) },
      })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            // Optimistic local update + success toast.
            order.status = 'cancelled';
            const wasIdempotent = response.data?.cancellation?.was_already_cancelled === true;
            this.toast.success(
              wasIdempotent
                ? 'This order was already cancelled.'
                : 'Order cancelled.',
            );
          } else {
            this.toast.error(response.message || 'Unable to cancel order.');
          }
        },
        error: () => {
          this.toast.error('Network error. Please try again.');
        },
      });
  }
}
