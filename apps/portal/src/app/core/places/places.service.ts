/**
 * PlacesService — wraps the Google Places API (New) for the portal.
 *
 * Ported from apps/web/src/app/core/places/places.service.ts (itself ported
 * from mobile). Pure fetch() against the Places API (New) — no Google Maps JS
 * SDK / script loader. Reads the key from ./places.config.
 *
 *   1. POST https://places.googleapis.com/v1/places:autocomplete  — suggestions
 *   2. GET  https://places.googleapis.com/v1/places/{placeId}      — details
 *
 * A session token (v4 UUID) groups autocomplete keystrokes + the final details
 * call into one billable unit; discarded after details. If the API key is
 * empty, every call resolves to []/null so consumers fall back to plain input.
 */

import { Injectable } from '@angular/core';
import { GOOGLE_PLACES } from './places.config';

const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
const DETAILS_URL_BASE = 'https://places.googleapis.com/v1/places';

const DETAILS_FIELD_MASK = [
  'id',
  'displayName',
  'formattedAddress',
  'addressComponents',
  'location',
].join(',');

export interface PlaceAddressComponent {
  longText: string;
  shortText: string;
  types: string[];
  languageCode?: string;
}

export interface PlaceDetails {
  placeId: string;
  formattedAddress: string;
  addressComponents: PlaceAddressComponent[];
  location: { latitude: number; longitude: number };
  street: string | null;
  city: string | null;
  area: string | null;
  country: string | null;
  postalCode: string | null;
}

export interface PlaceSuggestion {
  placeId: string;
  fullText: string;
  mainText: string;
  secondaryText: string;
}

@Injectable({ providedIn: 'root' })
export class PlacesService {
  private sessionToken: string | null = null;

  startSession(): void {
    this.sessionToken = generateUuidV4();
  }

  get isAvailable(): boolean {
    return !!GOOGLE_PLACES?.apiKey;
  }

  async autocomplete(input: string): Promise<PlaceSuggestion[]> {
    if (!this.isAvailable || !input || input.trim().length < 2) {
      return [];
    }
    if (!this.sessionToken) {
      this.startSession();
    }

    const body = {
      input: input.trim(),
      sessionToken: this.sessionToken,
      includedRegionCodes: GOOGLE_PLACES.regions || ['AE'],
      // Bias toward addresses (street/building) over businesses.
      includedPrimaryTypes: ['street_address', 'premise', 'subpremise', 'route'],
      languageCode: 'en',
    };

    try {
      const resp = await fetch(AUTOCOMPLETE_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Goog-Api-Key': GOOGLE_PLACES.apiKey,
        },
        body: JSON.stringify(body),
      });
      if (!resp.ok) {
        return [];
      }
      const data = (await resp.json()) as any;
      const suggestions = (data?.suggestions || []) as any[];
      return suggestions
        .filter((s) => !!s.placePrediction)
        .map((s) => {
          const p = s.placePrediction;
          return {
            placeId: p.placeId,
            fullText: p.text?.text || '',
            mainText: p.structuredFormat?.mainText?.text || p.text?.text || '',
            secondaryText: p.structuredFormat?.secondaryText?.text || '',
          };
        });
    } catch {
      return [];
    }
  }

  async details(placeId: string): Promise<PlaceDetails | null> {
    if (!this.isAvailable || !placeId) {
      return null;
    }

    const url = new URL(`${DETAILS_URL_BASE}/${placeId}`);
    if (this.sessionToken) {
      url.searchParams.set('sessionToken', this.sessionToken);
    }

    try {
      const resp = await fetch(url.toString(), {
        method: 'GET',
        headers: {
          'X-Goog-Api-Key': GOOGLE_PLACES.apiKey,
          'X-Goog-FieldMask': DETAILS_FIELD_MASK,
        },
      });
      if (!resp.ok) {
        return null;
      }
      const data = (await resp.json()) as any;

      const components = (data.addressComponents || []) as PlaceAddressComponent[];
      const parsed = this.parseAddressComponents(components);

      return {
        placeId: data.id || placeId,
        formattedAddress: data.formattedAddress || '',
        addressComponents: components,
        location: {
          latitude: data.location?.latitude || 0,
          longitude: data.location?.longitude || 0,
        },
        ...parsed,
      };
    } catch {
      return null;
    } finally {
      this.sessionToken = null;
    }
  }

  private parseAddressComponents(components: PlaceAddressComponent[]): {
    street: string | null;
    city: string | null;
    area: string | null;
    country: string | null;
    postalCode: string | null;
  } {
    let streetNumber: string | null = null;
    let route: string | null = null;
    let city: string | null = null;
    let area: string | null = null;
    let country: string | null = null;
    let postalCode: string | null = null;

    for (const c of components) {
      const types = c.types || [];
      if (types.includes('street_number')) {
        streetNumber = c.longText;
      } else if (types.includes('route')) {
        route = c.longText;
      } else if (types.includes('locality') || types.includes('postal_town')) {
        city = c.longText;
      } else if (
        !area &&
        (types.includes('sublocality') ||
          types.includes('sublocality_level_1') ||
          types.includes('neighborhood') ||
          types.includes('administrative_area_level_2'))
      ) {
        area = c.longText;
      } else if (types.includes('country')) {
        country = c.longText;
      } else if (types.includes('postal_code')) {
        postalCode = c.longText;
      }
    }

    let street: string | null = null;
    if (route && streetNumber) {
      street = `${streetNumber} ${route}`;
    } else if (route) {
      street = route;
    } else if (streetNumber) {
      street = streetNumber;
    }

    return { street, city, area, country, postalCode };
  }
}

function generateUuidV4(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}
