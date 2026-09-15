import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

export interface GiftReminder {
  id: number;
  recipient_name: string;
  occasion: string;
  remind_date: string;
  note: string | null;
  budget_max: string | null;
  category_slug: string | null;
  last_notified_stage: number | null;
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
 * Owner-scoped CRUD for the signed-in customer's gift reminders via the
 * /me/gift-reminders route-keys. All calls pass the auth token.
 */
@Injectable({ providedIn: 'root' })
export class GiftRemindersService {
  constructor(private adapter: MobileNetworkAdapter) {}

  async list(token: string): Promise<GiftReminder[]> {
    try {
      const res: any = await firstValueFrom(
        this.adapter.get_v3('GET /me/gift-reminders', { authToken: token }),
      );
      if (res?.response_code === 200 && res?.status === 'success') {
        return Array.isArray(res.data?.gift_reminders) ? res.data.gift_reminders : [];
      }
      return [];
    } catch {
      return [];
    }
  }

  async create(token: string, input: GiftReminderInput): Promise<GiftReminder | null> {
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /me/gift-reminders', input, { authToken: token }),
    );
    if ((res?.response_code === 201 || res?.response_code === 200) && res?.status === 'success') {
      return res.data?.gift_reminder ?? null;
    }
    return null;
  }

  async update(token: string, id: number, input: GiftReminderInput): Promise<GiftReminder | null> {
    const res: any = await firstValueFrom(
      this.adapter.put_v3('PUT /me/gift-reminders/:id', input, { authToken: token, pathParams: { id: String(id) } }),
    );
    if (res?.response_code === 200 && res?.status === 'success') {
      return res.data?.gift_reminder ?? null;
    }
    return null;
  }

  async remove(token: string, id: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.delete_v3('DELETE /me/gift-reminders/:id', { authToken: token, pathParams: { id: String(id) } }),
    );
    return res?.response_code === 204 || res?.response_code === 200;
  }
}
