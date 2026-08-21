import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { TranslatePipe } from '../../translate.pipe';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

interface VendorNotification {
  id: number;
  message: string;
  is_read: boolean;
  order_id: string | null;
  template: string;
  sent_at: string;
}

@Component({
  selector: 'app-vendor-notifications',
  imports: [VendorShellComponent, FormsModule, CommonModule, TranslatePipe, IconComponent],
  standalone: true,
  templateUrl: './vendor-notifications.component.html',
  styleUrl: './vendor-notifications.component.css',
})
export class VendorNotificationsComponent implements OnInit {
  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    is_saving: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  store_single = {
    store_notify_order_received: false,
    store_notify_order_cancelled: false,
    store_notify_order_return_refund_initiated: false,
    store_notify_product_listing_approved_rejected: false,
    store_notify_payment_scheduled: false,
    store_notify_payment_completed: false,
    store_notify_negative_review: false,
    store_notify_monthly_performance: false,
    store_notify_weekly_performance: false,
    store_notify_low_engagement: false,
    store_notify_custom_order: false,
  };

  get_single = { id: 0, token: '' };

  // ── In-app notification feed (the inbox) ─────────────────────
  active_tab: 'inbox' | 'preferences' = 'inbox';
  feed: VendorNotification[] = [];
  feed_unread = 0;
  feed_loading = false;
  feed_marking = false;

  update_notifications = {
    id: 0, token: '',
    store_notify_order_received: false,
    store_notify_order_cancelled: false,
    store_notify_order_return_refund_initiated: false,
    store_notify_product_listing_approved_rejected: false,
    store_notify_payment_scheduled: false,
    store_notify_payment_completed: false,
    store_notify_negative_review: false,
    store_notify_monthly_performance: false,
    store_notify_weekly_performance: false,
    store_notify_low_engagement: false,
    store_notify_custom_order: false,
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
    this.get_single.id = this.user_session.id;
    this.get_single.token = this.user_session.token;
    this.get_data();
    this.loadFeed();
  }

  goBack() {
    this.navHistory.back('/account');
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_data() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/store/notifications').subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.store_single = response.data;
        } else if (false) {
          this.error_notification(response.message);
        } else if (false) {
          this.error_notification(response.message);
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
        this.ui_controls.is_loading = false;
      },
    });
  }

  setTab(tab: 'inbox' | 'preferences'): void {
    this.active_tab = tab;
    if (tab === 'inbox' && this.feed.length === 0 && !this.feed_loading) {
      this.loadFeed();
    }
  }

  loadFeed(): void {
    this.feed_loading = true;
    this.adapter.get_v3('GET /vendor/notifications', { query: { limit: 50 } }).subscribe({
      next: (response: any) => {
        this.feed = (response?.data || []) as VendorNotification[];
        this.feed_unread = response?.meta?.unread ?? this.feed.filter(n => !n.is_read).length;
        this.feed_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification(apiErrorMessage(e, 'Unable to load notifications.'));
        this.feed_loading = false;
      },
    });
  }

  openNotification(n: VendorNotification): void {
    if (!n.is_read) {
      n.is_read = true;
      this.feed_unread = Math.max(0, this.feed_unread - 1);
      this.markRead([n.id]);
    }
  }

  markAllRead(): void {
    if (this.feed_unread === 0 || this.feed_marking) {
      return;
    }
    this.markRead(null, () => {
      this.feed.forEach(n => (n.is_read = true));
      this.feed_unread = 0;
    });
  }

  private markRead(ids: number[] | null, onDone?: () => void): void {
    this.feed_marking = true;
    const body = ids ? { ids } : {};
    this.adapter.post_v3('POST /vendor/notifications/mark-read', body).subscribe({
      next: () => {
        onDone?.();
        this.feed_marking = false;
      },
      error: (e: any) => {
        console.error(e);
        this.feed_marking = false;
      },
    });
  }

  timeAgo(iso: string): string {
    const then = new Date(iso).getTime();
    if (isNaN(then)) {
      return '';
    }
    const secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (secs < 60) {
      return 'just now';
    }
    const mins = Math.floor(secs / 60);
    if (mins < 60) {
      return `${mins}m ago`;
    }
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) {
      return `${hrs}h ago`;
    }
    const days = Math.floor(hrs / 24);
    if (days < 7) {
      return `${days}d ago`;
    }
    return new Date(iso).toLocaleDateString();
  }

  iconFor(template: string): string {
    if (template.startsWith('order.placed')) {
      return 'shopping_bag';
    }
    if (template.startsWith('order.cancelled')) {
      return 'cancel';
    }
    if (template.startsWith('return')) {
      return 'undo';
    }
    if (template.startsWith('compliance')) {
      return 'verified_user';
    }
    return 'notifications';
  }

  save_change() {
    this.update_notifications.id = this.user_session.id;
    this.update_notifications.token = this.user_session.token;
    this.update_notifications.store_notify_order_received = this.store_single.store_notify_order_received;
    this.update_notifications.store_notify_order_cancelled = this.store_single.store_notify_order_cancelled;
    this.update_notifications.store_notify_order_return_refund_initiated = this.store_single.store_notify_order_return_refund_initiated;
    this.update_notifications.store_notify_product_listing_approved_rejected = this.store_single.store_notify_product_listing_approved_rejected;
    this.update_notifications.store_notify_payment_scheduled = this.store_single.store_notify_payment_scheduled;
    this.update_notifications.store_notify_payment_completed = this.store_single.store_notify_payment_completed;
    this.update_notifications.store_notify_negative_review = this.store_single.store_notify_negative_review;
    this.update_notifications.store_notify_monthly_performance = this.store_single.store_notify_monthly_performance;
    this.update_notifications.store_notify_weekly_performance = this.store_single.store_notify_weekly_performance;
    this.update_notifications.store_notify_low_engagement = this.store_single.store_notify_low_engagement;
    this.update_notifications.store_notify_custom_order = this.store_single.store_notify_custom_order;

    this.ui_controls.is_saving = true;
    this.adapter.patch_v3('PATCH /vendor/store/notifications', this.update_notifications).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.get_data();
          this.success_notification('Notification preferences saved.');
        } else if (false) {
          this.error_notification(response.message);
        } else if (false) {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
        this.ui_controls.is_saving = false;
      },
    });
  }
}
