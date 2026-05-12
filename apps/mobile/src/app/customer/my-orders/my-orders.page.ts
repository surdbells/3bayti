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
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {Products} from "../../class/products";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
type OrderStatus = 'processing' | 'shipping' | 'delivered';
type FilterStatus = 'all' | OrderStatus;
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
    private toast: AxNotificationService,
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
    this.networkService.post_request(this.initial, GlobalComponent.read_orders_listing)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.orders = response.data;
            this.ui_controls.is_loading = false;
          }else {
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
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
    // navigate to order details or modal
  }

  onReview(order: Order) {
    // open review modal / route
  }

  getMoreItems() {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit
    this.networkService.post_request(this.initial, GlobalComponent.read_orders_listing)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.orders.push(...response.data);
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
}
