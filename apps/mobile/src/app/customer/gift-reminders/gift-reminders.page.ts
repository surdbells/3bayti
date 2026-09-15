import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonSpinner,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { GiftRemindersService, type GiftReminder, type GiftReminderInput } from '../../core/services/gift-reminders.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';

interface StoredUser {
  id: number;
  token: string;
}

interface ReminderForm {
  recipient_name: string;
  occasion: string;
  remind_date: string;
  budget_max: string;
  note: string;
}

/**
 * Mobile "Gift reminders" — the signed-in customer manages saved gift dates.
 * A cron nudges them 14/7/2 days ahead; "Find gifts" jumps into the pre-filled
 * Gift Finder. v3 ids only.
 */
@Component({
  selector: 'app-gift-reminders',
  templateUrl: './gift-reminders.page.html',
  styleUrls: ['./gift-reminders.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonSpinner,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
  ],
})
export class GiftRemindersPage implements OnInit {
  reminders: GiftReminder[] = [];
  loading = true;
  saving = false;
  showForm = false;
  editingId: number | null = null;

  form: ReminderForm = this.blankForm();
  readonly todayStr = new Date().toISOString().slice(0, 10);

  private user: StoredUser | null = null;
  private sessionId = '';

  constructor(
    private nav: NavController,
    private router: Router,
    private service: GiftRemindersService,
    private networkAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private toast: AxNotificationService,
  ) {}

  async ngOnInit(): Promise<void> {
    const ret = await Preferences.get({ key: 'user' });
    if (!ret.value) {
      this.router.navigate(['/', 'login']);
      return;
    }
    try {
      this.user = JSON.parse(ret.value) as StoredUser;
    } catch {
      this.user = null;
    }
    this.sessionId = await this.ensureSession();
    await this.load();
  }

  goBack(): void {
    this.nav.back();
  }

  private blankForm(): ReminderForm {
    return { recipient_name: '', occasion: '', remind_date: '', budget_max: '', note: '' };
  }

  get formValid(): boolean {
    return !!this.form.recipient_name.trim() && !!this.form.occasion.trim() && !!this.form.remind_date;
  }

  private async load(): Promise<void> {
    if (!this.user?.token) {
      return;
    }
    this.loading = true;
    this.reminders = await this.service.list(this.user.token);
    this.loading = false;
  }

  openCreate(): void {
    this.editingId = null;
    this.form = this.blankForm();
    this.showForm = true;
  }

  openEdit(r: GiftReminder): void {
    this.editingId = r.id;
    this.form = {
      recipient_name: r.recipient_name,
      occasion: r.occasion,
      remind_date: r.remind_date,
      budget_max: r.budget_max ?? '',
      note: r.note ?? '',
    };
    this.showForm = true;
  }

  cancel(): void {
    this.showForm = false;
  }

  async save(): Promise<void> {
    if (!this.formValid || this.saving || !this.user?.token) {
      return;
    }
    const input: GiftReminderInput = {
      recipient_name: this.form.recipient_name.trim(),
      occasion: this.form.occasion.trim(),
      remind_date: this.form.remind_date,
      budget_max: this.form.budget_max.trim() || null,
      note: this.form.note.trim() || null,
    };
    this.saving = true;
    try {
      if (this.editingId !== null) {
        await this.service.update(this.user.token, this.editingId, input);
      } else {
        const created = await this.service.create(this.user.token, input);
        if (created) {
          this.beacon('gift_reminder_created', { gift_reminder_id: created.id });
        }
      }
      this.showForm = false;
      await this.load();
    } catch {
      this.toast.error(this.i18n.t('gift_reminders_error'), { position: 'top-center' });
    } finally {
      this.saving = false;
    }
  }

  async remove(r: GiftReminder): Promise<void> {
    if (!this.user?.token) {
      return;
    }
    const ok = await this.service.remove(this.user.token, r.id);
    if (ok) {
      this.reminders = this.reminders.filter((x) => x.id !== r.id);
    }
  }

  findGifts(r: GiftReminder): void {
    this.beacon('gift_reminder_clicked', { gift_reminder_id: r.id });
    this.router.navigate(['/', 'gift-ain'], {
      queryParams: {
        occasion: r.occasion,
        ...(r.budget_max ? { budget_max: r.budget_max } : {}),
        ...(r.category_slug ? { category_slug: r.category_slug } : {}),
        gift_reminder_id: r.id,
      },
    });
  }

  private beacon(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId, ...extra };
      const opts = this.user?.token ? { authToken: this.user.token } : {};
      this.networkAdapter.post_v3('POST /ai/events', body, opts).subscribe({ next: () => {}, error: () => {} });
    } catch {
      // analytics must never break the page
    }
  }

  private async ensureSession(): Promise<string> {
    const got = await Preferences.get({ key: 'ain_session' });
    if (got.value) {
      return got.value;
    }
    const id =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : 'm-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
    await Preferences.set({ key: 'ain_session', value: id });
    return id;
  }
}
