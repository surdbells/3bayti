import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { AxConfirmService } from '../../shared/overlays';
import { IconComponent } from '../../shared/icon/icon.component';

type Audience = 'all' | 'customers' | 'vendors' | 'admins';

/**
 * Admin push broadcast composer — parity with (and a real UI for) the
 * legacy admin/send_notifications.php. Sends a push notification to a
 * chosen audience via POST /admin/notifications and reports the delivery
 * summary returned by the API.
 */
@Component({
  selector: 'app-notifications',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
  templateUrl: './notifications.component.html',
  styleUrl: './notifications.component.css',
})
export class NotificationsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  form = {
    title: '',
    body: '',
    audience: 'all' as Audience,
  };

  audiences: { value: Audience; label: string; hint: string }[] = [
    { value: 'all', label: 'Everyone', hint: 'All app users with notifications enabled' },
    { value: 'customers', label: 'Customers', hint: 'Shoppers only' },
    { value: 'vendors', label: 'Vendors', hint: 'Store owners only' },
    { value: 'admins', label: 'Admins', hint: 'Platform staff only' },
  ];

  sending = false;
  lastResult: { audience: string; recipients: number; sent: number; failed: number } | null = null;

  readonly TITLE_MAX = 120;
  readonly BODY_MAX = 500;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private confirm: AxConfirmService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
  }

  get canSend(): boolean {
    return !this.sending
      && this.form.title.trim().length > 0
      && this.form.body.trim().length > 0;
  }

  audienceLabel(v: Audience): string {
    return this.audiences.find((a) => a.value === v)?.label ?? v;
  }

  submit() {
    if (!this.canSend) {
      this.toast.error('A title and message are both required.');
      return;
    }
    this.confirm.confirm({
      title: 'Send broadcast',
      message: `This will push "${this.form.title.trim()}" to ${this.audienceLabel(this.form.audience)}. This cannot be undone.`,
      confirmLabel: 'Send now',
      cancelLabel: 'Cancel',
      variant: 'default',
    }).then((ok) => { if (ok) this.send(); });
  }

  private send() {
    this.sending = true;
    this.lastResult = null;
    this.adapter.post_v3('POST /admin/notifications', {
      title: this.form.title.trim(),
      body: this.form.body.trim(),
      audience: this.form.audience,
    }).subscribe({
      next: (response: any) => {
        this.sending = false;
        // The send summary may be enveloped under data or returned flat.
        const data = response?.data ?? response;
        if (data) {
          this.lastResult = data;
          if (data.recipients === 0) {
            this.toast.success('No active devices in that audience — nothing was sent.');
          } else {
            this.toast.success(`Sent to ${data.sent} of ${data.recipients} device(s).`);
          }
          // Clear the message but keep the audience for quick re-sends.
          this.form.title = '';
          this.form.body = '';
        } else {
          this.toast.error('Unable to send the broadcast.');
        }
      },
      error: (e) => {
        console.error(e);
        this.sending = false;
        this.toast.error('Unable to send the broadcast at this time.');
      },
    });
  }

  goBack() { this.router.navigate(['/backend']); }
}
