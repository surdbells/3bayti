import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { ToastService } from '../../shared/forms/toast/toast.service';
import { GiftRemindersService, type GiftReminder, type GiftReminderInput } from './gift-reminders.service';

interface ReminderForm {
  recipient_name: string;
  occasion: string;
  remind_date: string;
  // <input type="number"> + ngModel writes a number (or null when cleared),
  // so the budget is not always a string at runtime.
  budget_max: string | number | null;
  note: string;
}

/** Trim a form value that may arrive as a number or null from ngModel. */
function textOf(value: unknown): string {
  return value === null || value === undefined ? '' : String(value).trim();
}

/** Today's date as YYYY-MM-DD in the browser's local timezone (not UTC). */
function localDateStr(d: Date = new Date()): string {
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${mm}-${dd}`;
}

/**
 * "Gift reminders" — the signed-in customer manages saved reminders (who, the
 * occasion, the date + optional budget/note). Each can jump straight into the
 * Ain Gift Finder pre-filled. A cron nudges the customer 14/7/2 days ahead.
 */
@Component({
  selector: 'app-gift-reminders',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, TranslatePipe],
  templateUrl: './gift-reminders-page.html',
  styleUrl: './gift-reminders-page.scss',
})
export class GiftRemindersPageComponent implements OnInit {
  private readonly service = inject(GiftRemindersService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  readonly reminders = signal<GiftReminder[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly showForm = signal(false);
  readonly editingId = signal<number | null>(null);

  form: ReminderForm = this.blankForm();

  readonly todayStr = localDateStr();
  readonly canSave = computed(() => true); // recomputed in template via the getter below

  get formValid(): boolean {
    return !!textOf(this.form.recipient_name) && !!textOf(this.form.occasion) && !!this.form.remind_date;
  }

  async ngOnInit(): Promise<void> {
    await this.reload();
  }

  private async reload(): Promise<void> {
    this.loading.set(true);
    this.reminders.set(await this.service.list());
    this.loading.set(false);
  }

  private blankForm(): ReminderForm {
    return { recipient_name: '', occasion: '', remind_date: '', budget_max: '', note: '' };
  }

  openCreate(): void {
    this.editingId.set(null);
    this.form = this.blankForm();
    this.showForm.set(true);
  }

  openEdit(r: GiftReminder): void {
    this.editingId.set(r.id);
    this.form = {
      recipient_name: r.recipient_name,
      occasion: r.occasion,
      remind_date: r.remind_date,
      budget_max: r.budget_max ?? '',
      note: r.note ?? '',
    };
    this.showForm.set(true);
  }

  cancel(): void {
    this.showForm.set(false);
  }

  async save(): Promise<void> {
    if (!this.formValid || this.saving()) {
      return;
    }
    const input: GiftReminderInput = {
      recipient_name: textOf(this.form.recipient_name),
      occasion: textOf(this.form.occasion),
      remind_date: this.form.remind_date,
      budget_max: textOf(this.form.budget_max) || null,
      note: textOf(this.form.note) || null,
    };
    this.saving.set(true);
    try {
      const id = this.editingId();
      if (id !== null) {
        await this.service.update(id, input);
      } else {
        await this.service.create(input);
      }
      this.showForm.set(false);
      await this.reload();
    } catch {
      // Keep the form open so the user can correct and retry.
      this.toast.error('giftReminders.saveError');
    } finally {
      this.saving.set(false);
    }
  }

  async remove(r: GiftReminder): Promise<void> {
    try {
      await this.service.remove(r.id);
      this.reminders.update((list) => list.filter((x) => x.id !== r.id));
    } catch {
      // ignore; the row stays
    }
  }

  findGifts(r: GiftReminder): void {
    this.service.beacon('gift_reminder_clicked', { gift_reminder_id: r.id });
    void this.router.navigate(['/gift-ain'], {
      queryParams: {
        occasion: r.occasion,
        ...(r.budget_max ? { budget_max: r.budget_max } : {}),
        ...(r.category_slug ? { category_slug: r.category_slug } : {}),
        gift_reminder_id: r.id,
      },
    });
  }
}
