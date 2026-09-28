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

  /**
   * Create a reminder. Throws when the API rejects it: the adapter surfaces
   * HTTP errors (422/401/5xx) as an error envelope on the success channel, so
   * without this check a failed save would look like a silent no-op.
   */
  async create(token: string, input: GiftReminderInput): Promise<GiftReminder> {
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /me/gift-reminders', input, { authToken: token }),
    );
    const saved = (res?.response_code === 201 || res?.response_code === 200) && res?.status === 'success'
      ? res.data?.gift_reminder
      : null;
    if (!saved) {
      throw new Error(res?.message || 'Gift reminder could not be saved.');
    }
    return saved;
  }

  /** Update a reminder. Throws when the API rejects it (see create()). */
  async update(token: string, id: number, input: GiftReminderInput): Promise<GiftReminder> {
    const res: any = await firstValueFrom(
      this.adapter.put_v3('PUT /me/gift-reminders/:id', input, { authToken: token, pathParams: { id: String(id) } }),
    );
    const saved = res?.response_code === 200 && res?.status === 'success' ? res.data?.gift_reminder : null;
    if (!saved) {
      throw new Error(res?.message || 'Gift reminder could not be updated.');
    }
    return saved;
  }

  async remove(token: string, id: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.delete_v3('DELETE /me/gift-reminders/:id', { authToken: token, pathParams: { id: String(id) } }),
    );
    return res?.response_code === 204 || res?.response_code === 200;
  }
}
