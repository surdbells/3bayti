import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';

import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonAvatar,
  IonButton,
  IonButtons,
  IonChip,
  IonContent,
  IonHeader,
  IonLabel,
  IonRefresher,
  IonRefresherContent,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Router, RouterLink} from "@angular/router";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import {Cart} from "../../class/cart";
import {ActionSheetController} from "@ionic/angular";
import {Preferences} from "@capacitor/preferences";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
@Component({
  selector: 'app-orders',
  templateUrl: './orders.page.html',
  styleUrls: ['./orders.page.scss'],
  standalone: true,
  imports: [IonContent, IonHeader, IonTitle, IonToolbar, CommonModule, FormsModule, IonButtons, IonRefresher, IonRefresherContent, RouterLink, IonButton, IonLabel, IonChip, IonAvatar, TranslatePipe, AxIconComponent, AxLoaderComponent, AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class OrdersPage implements OnInit, OnDestroy {
  orders: Cart[] = [];
  isOnline = true;
  isOrderOpen = false; // or control this as you like
  private sub: Subscription;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private toast: AxNotificationService
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_loading_details: false,
    is_empty: false
  }
  rqst_param = {
    id: 0,
    token: ""
  }
  request = {
    id: 0,
    token: "",
    product: 0
  }
  remove = {
    id: 0,
    token: "",
    item: 0,
  }
  increase = {
    id: 0,
    token: "",
    item: 0,
    quantity: 0,
  }
  decrease = {
    id: 0,
    token: "",
    item: 0,
    quantity: 0,
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
  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }
  single_product = {
    id: 0,
    token: "",
    item: 0,
    product: 0,
    name: "",
    image: "",
    quantity: 0,
    price: "",
    total: "",
    size: "",
    color: "",
    note: "",
    extra_measurement: "",
    status: ""
  };
  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as CustomEvent).detail.register(100, () => {
      this.nav.navigateRoot('/account');
    });
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
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
      this.request.id = this.single_user.id
      this.request.token = this.single_user.token
      this.load_orders();
    }
  }
  load_orders() {
    this.orders = [];
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.request.id = this.single_user.id;
    this.networkService.post_request(this.request, GlobalComponent.customerOrder)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200) {
            this.orders = response.data;
            this.ui_controls.is_loading = false;
          }else{
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  load_order_details(product: number) {
    this.ui_controls.is_loading_details = true;
    this.request.product = product;
    this.networkService.post_request(this.request, GlobalComponent.orderDetails)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200) {
            this.single_product = response.data;
            this.ui_controls.is_loading_details = false;
          }
        }
      }))
  }
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_orders() {
    this.router.navigate(['/', 'orders']);
  }
  user_messages() {
    this.router.navigate(['/', 'messages']);
  }
  handleRefresh(event: any) {
    setTimeout(() => {
      this.load_orders();
      event.target.complete();
    }, 200);
  }

  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: "top-center"
    });
  }

  check_out() {
    this.router.navigate(['/', 'checkout']);
  }
  triggerBack() {
    this.nav.back();
  }

  open_details(product: number) {
    this.isOrderOpen = true;
    this.load_order_details(product)
  }
}
