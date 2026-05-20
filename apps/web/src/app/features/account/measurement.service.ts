import { Injectable, signal, Signal, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

const V3_BASE = 'https://api-v3.3bayti.ae';

/**
 * The canonical tailoring measurement field set for the default
 * measurement profile. The API stores `values` as a free-form
 * map<string, number> (cm), so the web side owns the field list and
 * ordering. Per the Y.5 plan (Q5.4 = standard tailoring set).
 */
export const MEASUREMENT_FIELDS = [
  'bust_chest',
  'waist',
  'hips',
  'shoulder',
  'sleeve_length',
  'height',
] as const;

export type MeasurementField = (typeof MEASUREMENT_FIELDS)[number];

/** A persisted measurement set. Mirrors MeasurementSerializer::publicShape. */
export interface Measurement {
  id: number;
  /** null for the default set; an int for a per-category set. */
  category_id: number | null;
  /** Free-form map of field name → value in cm. */
  values: Record<string, number>;
  notes: string | null;
  updated_at: string;
}

/** Body for PUT /v3/me/measurements/default. */
export interface MeasurementUpsert {
  values: Record<string, number>;
  notes?: string | null;
}

/**
 * MeasurementService — read / upsert / clear the user's DEFAULT body
 * measurement set. Authenticated account data, so it uses the
 * Bearer-bound direct HttpClient (auth attached by the interceptor),
 * consistent with AddressService / OrderService.
 *
 * Endpoints (no `data` envelope — body is `{ measurements: ... }`):
 *   GET    /v3/me/measurements/default → { measurements: Measurement | null }
 *   PUT    /v3/me/measurements/default → { measurements: Measurement }
 *   DELETE /v3/me/measurements/default → 204
 *
 * A missing set is `{ measurements: null }` (the API never 404s the
 * default GET), so the form starts empty and a first save creates it.
 *
 * Per-category sets exist server-side but aren't surfaced in the web
 * UI yet (Q4.3-style deferral); this service covers the default set.
 */
@Injectable({ providedIn: 'root' })
export class MeasurementService {
  private readonly http = inject(HttpClient);

  private readonly _isLoading = signal<boolean>(false);
  private readonly _isSaving = signal<boolean>(false);
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();
  readonly isSaving: Signal<boolean> = this._isSaving.asReadonly();

  /** Fetch the default measurement set, or null if none saved yet. */
  async getDefault(): Promise<Measurement | null> {
    this._isLoading.set(true);
    try {
      const res = await firstValueFrom(
        this.http.get<{ measurements: Measurement | null }>(
          `${V3_BASE}/v3/me/measurements/default`,
        ),
      );
      return res.measurements ?? null;
    } finally {
      this._isLoading.set(false);
    }
  }

  /** Create or replace the default measurement set. */
  async upsertDefault(input: MeasurementUpsert): Promise<Measurement> {
    this._isSaving.set(true);
    try {
      const res = await firstValueFrom(
        this.http.put<{ measurements: Measurement }>(
          `${V3_BASE}/v3/me/measurements/default`,
          input,
        ),
      );
      return res.measurements;
    } finally {
      this._isSaving.set(false);
    }
  }

  /** Delete the default measurement set. */
  async clearDefault(): Promise<void> {
    this._isSaving.set(true);
    try {
      await firstValueFrom(
        this.http.delete<void>(`${V3_BASE}/v3/me/measurements/default`),
      );
    } finally {
      this._isSaving.set(false);
    }
  }
}
