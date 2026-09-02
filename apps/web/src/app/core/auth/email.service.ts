import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../http/routed-http-client';
import { AuthService } from './auth.service';

/** Response from POST /v3/me/email — same { verification_id } as other OTP sends. */
export interface SetEmailResponse {
  verification_id: string;
}

/** Response from POST /v3/me/email/verify. */
export interface VerifyEmailResponse {
  email: string;
  is_email_verified: boolean;
  needs_email_update: boolean;
}

/**
 * EmailService — set + verify the CURRENT user's email (Bearer /me calls,
 * routed through RoutedHttpClient, NOT the BFF).
 *
 * Backs the "update your email" flow for customers whose email is a
 * non-deliverable Apple private-relay / placeholder address: the flow calls
 * sendOtp(newEmail) then verify(). The OTP is dispatched to the NEW address,
 * proving deliverability before the switch — the active email is untouched
 * until verify() succeeds (pending-email model on the server).
 *
 * Endpoints:
 *   POST /v3/me/email        { email }                  → { verification_id }
 *   POST /v3/me/email/verify { verification_id, code }  → { email, is_email_verified, needs_email_update }
 */
@Injectable({ providedIn: 'root' })
export class EmailService {
  private readonly http = inject(RoutedHttpClient);
  private readonly auth = inject(AuthService);

  /**
   * Request changing the user's email: dispatch an OTP to the NEW address and
   * stash it as pending server-side. Returns the verification_id for verify().
   * 422 VALIDATION_FAILED if the new address is itself non-deliverable; 409
   * CONFLICT_EMAIL_TAKEN if it already belongs to another account.
   */
  async sendOtp(email: string): Promise<SetEmailResponse> {
    const env = await firstValueFrom(
      this.http.post<SetEmailResponse>('POST /me/email', { body: { email } }),
    );
    return env.data;
  }

  /**
   * Confirm the OTP. On success the server promotes the pending email and
   * marks it verified; we mirror that into the cached AuthService user so the
   * reminder banner + profile clear without a /me refetch.
   */
  async verify(verificationId: string, code: string): Promise<VerifyEmailResponse> {
    const env = await firstValueFrom(
      this.http.post<VerifyEmailResponse>('POST /me/email/verify', {
        body: { verification_id: verificationId, code },
      }),
    );
    const data = env.data;
    const current = this.auth.currentUser();
    if (current !== null) {
      this.auth.applyProfile({
        ...current,
        email: data.email,
        is_email_verified: data.is_email_verified,
        needs_email_update: data.needs_email_update,
      });
    }
    return data;
  }
}
