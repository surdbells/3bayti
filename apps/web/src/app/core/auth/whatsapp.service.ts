import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../http/routed-http-client';
import { AuthService } from './auth.service';

/** Response from POST /v3/me/whatsapp/link. */
export interface LinkWhatsAppResponse {
  verification_id: string;
}

/** Response from POST /v3/me/whatsapp/link/verify. */
export interface VerifyWhatsAppResponse {
  whatsapp_phone: string;
}

/**
 * WhatsAppService — link / verify / unlink the CURRENT user's WhatsApp number
 * (Bearer /me calls, routed through RoutedHttpClient), for the WhatsApp
 * Commerce channel. Mirrors {@link PhoneService}.
 *
 * Endpoints:
 *   POST   /v3/me/whatsapp/link         { phone }                  → { verification_id }
 *   POST   /v3/me/whatsapp/link/verify  { verification_id, code }  → { whatsapp_phone }
 *   DELETE /v3/me/whatsapp/link                                    → { whatsapp_phone: null }
 *
 * On verify/unlink the cached AuthService user is patched so the account
 * settings reflect the change without a /me refetch.
 */
@Injectable({ providedIn: 'root' })
export class WhatsAppService {
  private readonly http = inject(RoutedHttpClient);
  private readonly auth = inject(AuthService);

  /**
   * Send an OTP to the WhatsApp number (E.164, leading '+') to prove control
   * of it. Returns the verification_id for verify(). A 409 CONFLICT_PHONE_TAKEN
   * propagates if the number is already linked to another account.
   */
  async sendLink(phone: string): Promise<LinkWhatsAppResponse> {
    const env = await firstValueFrom(
      this.http.post<LinkWhatsAppResponse>('POST /me/whatsapp/link', { body: { phone } }),
    );
    return env.data;
  }

  /** Confirm the OTP and persist the linked number; mirror it into the user. */
  async verifyLink(verificationId: string, code: string): Promise<VerifyWhatsAppResponse> {
    const env = await firstValueFrom(
      this.http.post<VerifyWhatsAppResponse>('POST /me/whatsapp/link/verify', {
        body: { verification_id: verificationId, code },
      }),
    );
    const data = env.data;
    const current = this.auth.currentUser();
    if (current !== null) {
      this.auth.applyProfile({ ...current, whatsapp_phone: data.whatsapp_phone });
    }
    return data;
  }

  /** Remove the linked number; mirror the clear into the user. */
  async unlink(): Promise<void> {
    await firstValueFrom(this.http.delete<{ whatsapp_phone: null }>('DELETE /me/whatsapp/link'));
    const current = this.auth.currentUser();
    if (current !== null) {
      this.auth.applyProfile({ ...current, whatsapp_phone: null });
    }
  }
}
