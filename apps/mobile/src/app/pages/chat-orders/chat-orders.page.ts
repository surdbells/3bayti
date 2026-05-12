import {
  Component,
  OnInit,
  OnDestroy,
  ChangeDetectorRef,
  ChangeDetectionStrategy
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonContent,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButton,
  IonButtons,
  IonRefresher,
  IonRefresherContent,
  NavController
} from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';
import { Preferences } from '@capacitor/preferences';

import { ChatService } from '../../service/chat.service';
import { ChatOrder, OrderStatus } from '../../models/chat.models';
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
@Component({
  selector: 'app-chat-orders',
  templateUrl: './chat-orders.page.html',
  styleUrls: ['./chat-orders.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    IonContent,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButton,
    IonButtons,
    IonRefresher,
    IonRefresherContent,
    TranslatePipe,
    AxIconComponent,
  ]
})
export class ChatOrdersPage implements OnInit, OnDestroy {
  orders: ChatOrder[] = [];
  isLoading = true;
  storeName = '';
  vendorId = 0;

  private userId = 0;
  private userToken = '';
  private subscriptions: Subscription[] = [];

  constructor(
    private chatService: ChatService,
    private route: ActivatedRoute,
    private router: Router,
    private nav: NavController,
    private cdr: ChangeDetectorRef
  ) {}

  ngOnInit() {
    this.route.queryParams.subscribe(params => {
      this.vendorId = Number(params['vendor_id']);
      this.storeName = params['store_name'] || 'Store';

      if (!this.vendorId) {
        this.router.navigate(['/chat-vendors']);
        return;
      }

      this.loadUserAndOrders();
    });
  }

  ngOnDestroy() {
    this.subscriptions.forEach(sub => sub.unsubscribe());
  }

  async loadUserAndOrders() {
    const userData = await Preferences.get({ key: 'user' });

    if (!userData.value) {
      this.router.navigate(['/login']);
      return;
    }

    const user = JSON.parse(userData.value);
    this.userId = user.id;
    this.userToken = user.token;

    this.loadOrders();
  }

  loadOrders() {
    this.isLoading = true;
    this.cdr.markForCheck();

    const sub = this.chatService.getVendorOrders(this.userId, this.userToken, this.vendorId)
      .subscribe({
        next: (response) => {
          this.orders = response.orders;
          this.isLoading = false;
          this.cdr.markForCheck();

          // If only one order, skip selection and go directly to chat
          if (response.skip_selection && response.orders.length === 1) {
          //  this.selectOrder(response.orders[0]);
          }
        },
        error: (err) => {
          console.error('Error loading orders:', err);
          this.orders = [];
          this.isLoading = false;
          this.cdr.markForCheck();
        }
      });

    this.subscriptions.push(sub);
  }

  selectOrder(order: ChatOrder) {
    // Navigate to chat page with order_item_id
    this.router.navigate(['/chat'], {
      queryParams: {
        order_item_id: order.order_item_id
      }
    });
  }

  getStatusClass(status: OrderStatus): string {
    const statusMap: Record<OrderStatus, string> = {
      'Pending': 'status-pending',
      'Accepted': 'status-processing',
      'Processing': 'status-processing',
      'Ready for Delivery': 'status-ready',
      'Delivered': 'status-delivered',
      'Return Requested': 'status-return',
      'Returned': 'status-return',
      'Cancelled': 'status-cancelled',
      'Refunded': 'status-cancelled'
    };
    return statusMap[status] || 'status-pending';
  }

  onImageError(event: Event) {
    const img = event.target as HTMLImageElement;
    img.src = 'assets/img/placeholder-product.png';
  }

  handleRefresh(event: any) {
    this.loadOrders();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  goBack() {
    this.nav.back();
  }
}
