import {
  Component,
  OnInit,
  OnDestroy,
  ChangeDetectorRef,
  ChangeDetectionStrategy
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonContent,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButton,
  IonButtons,
  IonRefresher,
  IonRefresherContent,
  IonSpinner,
  NavController
} from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';
import { Preferences } from '@capacitor/preferences';

import { ChatService } from '../../service/chat.service';
import { VendorConversation, OrderStatus } from '../../models/chat.models';
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
@Component({
  selector: 'app-vendor-chat-list',
  templateUrl: './vendor-chat-list.page.html',
  styleUrls: ['./vendor-chat-list.page.scss'],
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
    IonSpinner,
    TranslatePipe, AxIconComponent]
})
export class VendorChatListPage implements OnInit, OnDestroy {
  conversations: VendorConversation[] = [];
  isLoading = true;
  isLoadingMore = false;
  hasMore = false;
  totalUnread = 0;

  private userId = 0;
  private userToken = '';
  private offset = 0;
  private limit = 20;
  private subscriptions: Subscription[] = [];
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
    is_store_active: false,
    is_store_approved: false,
    is_customer: false
  }
  constructor(
    private chatService: ChatService,
    private router: Router,
    private nav: NavController,
    private cdr: ChangeDetectorRef
  ) {}

  ngOnInit() {
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
      return;
    }else{
      this.single_user = JSON.parse(ret.value);
      this.loadUserAndConversations();
    }
  }
  ngOnDestroy() {
    this.subscriptions.forEach(sub => sub.unsubscribe());
  }

  async loadUserAndConversations() {
    this.userId = this.single_user.id;
    this.userToken = this.single_user.token;
    // Check if user is a vendor
    if (!this.single_user.is_vendor || !this.single_user.is_store_active) {
      this.router.navigate(['/account']);
      return;
    }

    this.loadConversations();
  }

  loadConversations(append = false) {
    if (!append) {
      this.isLoading = true;
      this.offset = 0;
    } else {
      this.isLoadingMore = true;
    }
    this.cdr.markForCheck();

    const sub = this.chatService.getVendorConversations(
      this.userId,
      this.userToken,
      this.limit,
      this.offset
    ).subscribe({
      next: (response) => {
        if (append) {
          this.conversations = [...this.conversations, ...response.conversations];
        } else {
          this.conversations = response.conversations;
        }

        this.totalUnread = response.total_unread;
        this.hasMore = response.has_more;
        this.offset += response.conversations.length;

        this.isLoading = false;
        this.isLoadingMore = false;
        this.cdr.markForCheck();
      },
      error: (err) => {
        console.error('Error loading conversations:', err);
        this.isLoading = false;
        this.isLoadingMore = false;
        this.cdr.markForCheck();
      }
    });

    this.subscriptions.push(sub);
  }

  loadMore() {
    if (!this.isLoadingMore && this.hasMore) {
      this.loadConversations(true);
    }
  }

  openConversation(conv: VendorConversation) {
    this.router.navigate(['/chat'], {
      queryParams: {
        order_item_id: conv.order_item_id
      }
    });
  }

  getInitials(name: string): string {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
  }

  formatTime(dateString: string | null): string {
    if (!dateString) return '';

    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m`;
    if (diffHours < 24) return `${diffHours}h`;
    if (diffDays < 7) return `${diffDays}d`;

    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
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

  handleRefresh(event: any) {
    this.loadConversations();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  goBack() {
    this.nav.back();
  }
}
