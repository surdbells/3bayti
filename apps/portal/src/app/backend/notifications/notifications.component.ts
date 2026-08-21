import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { AxConfirmService } from '../../shared/overlays';
import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';

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
    template_id: null as number | null,
  };

  /** Active templates for the picker + the {{variable}} catalog. */
  templates: { id: number; name: string; title: string; body: string; image_url: string | null; deep_link: string | null }[] = [];
  variables: { key: string; label: string; sample: string }[] = [];
  private lastField: 'title' | 'body' = 'body';

  audiences: { value: Audience; label: string; hint: string }[] = [
    { value: 'all', label: 'Everyone', hint: 'All app users with notifications enabled' },
    { value: 'customers', label: 'Customers', hint: 'Shoppers only' },
    { value: 'vendors', label: 'Vendors', hint: 'Store owners only' },
    { value: 'admins', label: 'Admins', hint: 'Platform staff only' },
  ];

  sending = false;
  lastResult: {
    audience: string; recipients: number; sent: number; failed: number;
    failure_kinds?: Record<string, number>; error_sample?: string | null;
    queued?: boolean; id?: number;
  } | null = null;

  readonly TITLE_MAX = 120;
  readonly BODY_MAX = 500;

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private confirm: AxConfirmService,
  ) {}

  /** Live audience summary (count + device split) for the selected audience. */
  audiencePreview: { total: number; android: number; ios: number } | null = null;
  audiencePreviewLoading = false;
  /** Broadcast id from the last send — links the summary to its detail page. */
  lastBroadcastId: number | null = null;

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.loadAudiencePreview();
    this.loadTemplates();
    this.loadVariables();
  }

  private loadTemplates() {
    this.adapter.get_v3('GET /admin/notification-templates', { query: { status: 'active', limit: 200 } }).subscribe({
      next: (res: any) => { this.templates = Array.isArray(res?.data) ? res.data : []; },
      error: () => { this.templates = []; },
    });
  }

  private loadVariables() {
    this.adapter.get_v3('GET /admin/notification-templates/variables').subscribe({
      next: (res: any) => { this.variables = Array.isArray(res?.data) ? res.data : []; },
      error: () => { this.variables = []; },
    });
  }

  /** Populate the composer from a chosen template. '' clears the selection. */
  applyTemplate(idStr: string) {
    const id = Number(idStr) || null;
    this.form.template_id = id;
    if (!id) return;
    const t = this.templates.find((x) => x.id === id);
    if (t) { this.form.title = t.title; this.form.body = t.body; }
  }

  rememberField(f: 'title' | 'body') { this.lastField = f; }

  insertVariable(key: string) {
    const token = `{{${key}}}`;
    const el = document.getElementById(this.lastField === 'title' ? 'notif-title' : 'notif-body') as HTMLInputElement | HTMLTextAreaElement | null;
    if (el && typeof el.selectionStart === 'number') {
      const start = el.selectionStart ?? 0;
      const end = el.selectionEnd ?? start;
      const cur = this.form[this.lastField];
      this.form[this.lastField] = cur.slice(0, start) + token + cur.slice(end);
      setTimeout(() => { el.focus(); const pos = start + token.length; el.setSelectionRange(pos, pos); }, 0);
    } else {
      this.form[this.lastField] = (this.form[this.lastField] || '') + token;
    }
  }

  /** Preview with sample variable values substituted. */
  resolvePreview(text: string): string {
    return (text || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, (_m, k) => {
      const v = this.variables.find((x) => x.key === String(k).toLowerCase());
      return v ? v.sample : '';
    });
  }

  get canSend(): boolean {
    return !this.sending
      && this.form.title.trim().length > 0
      && this.form.body.trim().length > 0;
  }

  audienceLabel(v: Audience): string {
    return this.audiences.find((a) => a.value === v)?.label ?? v;
  }

  /** Switch audience + refresh the preview count. */
  selectAudience(v: Audience) {
    this.form.audience = v;
    this.loadAudiencePreview();
  }

  private loadAudiencePreview() {
    this.audiencePreviewLoading = true;
    this.adapter.get_v3('GET /admin/notifications/audience-preview', { query: { audience: this.form.audience } })
      .subscribe({
        next: (res: any) => {
          const d = res?.data ?? res;
          this.audiencePreview = d ? { total: d.total ?? 0, android: d.android ?? 0, ios: d.ios ?? 0 } : null;
          this.audiencePreviewLoading = false;
        },
        error: () => { this.audiencePreview = null; this.audiencePreviewLoading = false; },
      });
  }

  viewLastBroadcast() {
    if (this.lastBroadcastId) this.router.navigate(['/admin/notification-broadcasts', this.lastBroadcastId]);
  }

  viewHistory() {
    this.router.navigate(['/admin/notification-broadcasts']);
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
      template_id: this.form.template_id,
    }).subscribe({
      next: (response: any) => {
        this.sending = false;
        // The send summary may be enveloped under data or returned flat.
        const data = response?.data ?? response;
        if (data) {
          this.lastResult = data;
          this.lastBroadcastId = data.id ?? null;
          if (data.queued) {
            this.toast.success(`Broadcast queued to ${data.recipients} device(s) — sending in the background.`);
          } else if (data.recipients === 0) {
            this.toast.success('No active devices in that audience — nothing was sent.');
          } else if (data.sent === 0 && data.failed > 0) {
            const kinds = data.failure_kinds ? Object.keys(data.failure_kinds).join(', ') : 'unknown';
            this.toast.error(`Delivery failed for all ${data.failed} device(s) [${kinds}]. ${data.error_sample ?? ''}`.trim());
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
        this.toast.error(apiErrorMessage(e, 'Unable to send the broadcast at this time.'));
      },
    });
  }

  goBack() { this.navHistory.back('/backend'); }
}
