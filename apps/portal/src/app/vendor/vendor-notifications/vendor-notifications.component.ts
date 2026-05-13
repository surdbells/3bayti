import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { TranslatePipe } from '../../translate.pipe';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
@Component({
  selector: 'app-vendor-notifications',
  imports: [VendorShellComponent, FormsModule, CommonModule, TranslatePipe],
  standalone: true,
  templateUrl: './vendor-notifications.component.html',
  styleUrl: './vendor-notifications.component.css',
})
export class VendorNotificationsComponent implements OnInit {
  constructor(
    private router: Router,
    private crudService: CrudService,
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
  }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_data() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.get_single, GlobalComponent.getVendorNotifications).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.store_single = response.data;
        } else if (response.response_code === 200 && response.status === 'failed') {
          this.error_notification(response.message);
        } else if (response.response_code === 400 && response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
      },
    });
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
    this.crudService.post_request(this.update_notifications, GlobalComponent.updateStoreNotifications).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.get_data();
          this.success_notification(response.message);
        } else if (response.response_code === 200 && response.status === 'failed') {
          this.error_notification(response.message);
        } else if (response.response_code === 400 && response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_saving = false;
      },
    });
  }
}
