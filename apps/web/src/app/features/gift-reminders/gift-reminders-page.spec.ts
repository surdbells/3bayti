import { describe, it, expect, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { GiftRemindersPageComponent } from './gift-reminders-page';
import { GiftRemindersService, type GiftReminder, type GiftReminderInput } from './gift-reminders.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';

class StubGiftRemindersService {
  createCalls: GiftReminderInput[] = [];
  createThrows: unknown = null;
  async list(): Promise<GiftReminder[]> {
    return [];
  }
  async create(input: GiftReminderInput): Promise<GiftReminder | null> {
    this.createCalls.push(input);
    if (this.createThrows !== null) throw this.createThrows;
    return null;
  }
  async update(): Promise<GiftReminder | null> {
    return null;
  }
  async remove(): Promise<void> {}
  beacon(): void {}
}

class StubToast {
  errors: string[] = [];
  error(m: string): string { this.errors.push(m); return ''; }
  success(): string { return ''; }
  info(): string { return ''; }
  warning(): string { return ''; }
}

function setup(): {
  fixture: ComponentFixture<GiftRemindersPageComponent>;
  service: StubGiftRemindersService;
  toast: StubToast;
} {
  const service = new StubGiftRemindersService();
  const toast = new StubToast();
  TestBed.configureTestingModule({
    imports: [GiftRemindersPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: GiftRemindersService, useValue: service },
      { provide: ToastService, useValue: toast },
    ],
  });
  const fixture = TestBed.createComponent(GiftRemindersPageComponent);
  fixture.detectChanges();
  return { fixture, service, toast };
}

function fillValidForm(c: GiftRemindersPageComponent): void {
  c.openCreate();
  c.form.recipient_name = '  My sister ';
  c.form.occasion = 'Birthday';
  c.form.remind_date = '2099-01-01';
}

describe('GiftRemindersPageComponent — save', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('saves when the number input hands ngModel a numeric budget', async () => {
    const { fixture, service } = setup();
    const c = fixture.componentInstance;
    fillValidForm(c);
    c.form.budget_max = 500; // what <input type="number"> + ngModel writes

    await c.save();

    expect(service.createCalls).toHaveLength(1);
    expect(service.createCalls[0]).toMatchObject({
      recipient_name: 'My sister',
      budget_max: '500',
      note: null,
    });
    expect(c.showForm()).toBe(false);
  });

  it('sends a null budget when the number input was cleared (ngModel null)', async () => {
    const { fixture, service } = setup();
    const c = fixture.componentInstance;
    fillValidForm(c);
    c.form.budget_max = null;

    await c.save();

    expect(service.createCalls[0].budget_max).toBeNull();
  });

  it('keeps the form open and shows an error toast when the API rejects the save', async () => {
    const { fixture, service, toast } = setup();
    service.createThrows = new Error('422');
    const c = fixture.componentInstance;
    fillValidForm(c);

    await c.save();

    expect(toast.errors).toEqual(['giftReminders.saveError']);
    expect(c.showForm()).toBe(true);
    expect(c.saving()).toBe(false);
  });

  it('uses the local calendar date as the earliest selectable date', () => {
    const { fixture } = setup();
    const d = new Date();
    const expected = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    expect(fixture.componentInstance.todayStr).toBe(expected);
  });
});
