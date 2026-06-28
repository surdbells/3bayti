import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../http/routed-http-client';
import { AuthService } from './auth.service';

/** Response from POST /v3/me/phone — same { verification_id } as register OTP. */
export interface SetPhoneResponse {
  verification_id: string;
}

/** Response from POST /v3/me/phone/verify. */
export interface VerifyPhoneResponse {
  phone: string;
  is_phone_verified: boolean;
}

/**
 * PhoneService — set + verify the CURRENT user's phone (Bearer /me calls,
 * routed through RoutedHttpClient, NOT the BFF).
 *
 * Backs the phone-after-social gate: a Google/Apple sign-up arrives with
 * is_phone_verified === false; the gate calls sendOtp() then verify().
 *
 * Endpoints:
 *   POST /v3/me/phone        { phone }                  → { verification_id }
 *   POST /v3/me/phone/verify { verification_id, code }  → { phone, is_phone_verified }
 */
@Injectable({ providedIn: 'root' })
export class PhoneService {
  private readonly http = inject(RoutedHttpClient);
  private readonly auth = inject(AuthService);

  /**
   * Set (or change) the user's phone and dispatch an OTP to it. The number
   * is E.164 (leading '+'). Returns the verification_id for verify().
   * 409 CONFLICT_PHONE_TAKEN propagates if the number belongs to another user.
   */
  async sendOtp(phone: string): Promise<SetPhoneResponse> {
    const env = await firstValueFrom(
      this.http.post<SetPhoneResponse>('POST /me/phone', { body: { phone } }),
    );
    return env.data;
  }

  /**
   * Confirm the OTP. On success marks the user's phone verified server-side;
   * we mirror that into the cached AuthService user so guards / banners that
   * read is_phone_verified update without a /me refetch.
   */
  async verify(verificationId: string, code: string): Promise<VerifyPhoneResponse> {
    const env = await firstValueFrom(
      this.http.post<VerifyPhoneResponse>('POST /me/phone/verify', {
        body: { verification_id: verificationId, code },
      }),
    );
    const data = env.data;
    const current = this.auth.currentUser();
    if (current !== null && data.is_phone_verified) {
      this.auth.applyProfile({ ...current, phone: data.phone, is_phone_verified: true });
    }
    return data;
  }
}
