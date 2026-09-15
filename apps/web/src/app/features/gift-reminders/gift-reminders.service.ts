import { Injectable, PLATFORM_ID, inject } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';

export interface GiftReminder {
  id: number;
  recipient_name: string;
  occasion: string;
  remind_date: string;
  note: string | null;
  budget_max: string | null;
  category_slug: string | null;
  last_notified_stage: number | null;
  created_at: string;
  updated_at: string;
}

export interface GiftReminderInput {
  recipient_name: string;
  occasion: string;
  remind_date: string;
  note?: string | null;
  budget_max?: string | null;
  category_slug?: string | null;
}

/**
 * Owner-scoped CRUD for the signed-in user's gift reminders (auth is attached
 * by the refresh interceptor). Also fires the gift_reminder analytics beacons.
 */
@Injectable({ providedIn: 'root' })
export class GiftRemindersService {
  private readonly http = inject(RoutedHttpClient);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  private static readonly SESSION_KEY = 'ain_session';

  async list(): Promise<GiftReminder[]> {
    try {
      const env = await firstValueFrom(
        this.http.get<{ gift_reminders: GiftReminder[] }>('GET /me/gift-reminders'),
      );
      return Array.isArray(env.data?.gift_reminders) ? env.data.gift_reminders : [];
    } catch {
      return [];
    }
  }

  async create(input: GiftReminderInput): Promise<GiftReminder | null> {
    const env = await firstValueFrom(
      this.http.post<{ gift_reminder: GiftReminder }>('POST /me/gift-reminders', { body: input }),
    );
    const created = env.data?.gift_reminder ?? null;
    if (created) {
      this.beacon('gift_reminder_created', { gift_reminder_id: created.id });
    }
    return created;
  }

  async update(id: number, input: GiftReminderInput): Promise<GiftReminder | null> {
    const env = await firstValueFrom(
      this.http.put<{ gift_reminder: GiftReminder }>('PUT /me/gift-reminders/:id', { params: { id }, body: input }),
    );
    return env.data?.gift_reminder ?? null;
  }

  async remove(id: number): Promise<void> {
    await firstValueFrom(this.http.delete('DELETE /me/gift-reminders/:id', { params: { id } }));
  }

  /** Best-effort analytics beacon; never throws. */
  beacon(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId(), ...extra };
      this.http.post('POST /ai/events', { body }).subscribe({ next: () => undefined, error: () => undefined });
    } catch {
      // analytics must never break the page
    }
  }

  private sessionId(): string {
    if (!this.isBrowser) {
      return 'ssr';
    }
    try {
      const existing = localStorage.getItem(GiftRemindersService.SESSION_KEY);
      if (existing) {
        return existing;
      }
      const id = this.uuid();
      localStorage.setItem(GiftRemindersService.SESSION_KEY, id);
      return id;
    } catch {
      return this.uuid();
    }
  }

  private uuid(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
    return 'w-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
  }
}
