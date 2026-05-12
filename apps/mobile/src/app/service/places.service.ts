/* =============================================================================
   PlacesService — wraps the Google Places API (New)
   -----------------------------------------------------------------------------
   Two endpoints used:

     1. POST https://places.googleapis.com/v1/places:autocomplete
        Returns address suggestions matching a search input.
        Cost: free for the first 12 calls in a session that ENDS in a
        Place Details (Essentials) call. After 12, also free.

     2. GET  https://places.googleapis.com/v1/places/{placeId}
        Returns structured address details for a chosen suggestion.
        Cost: 10,000 free per month, then $5 per 1000 (Essentials tier).

   Sessions
     A "session" is one user-typing-an-address interaction. The session
     token (a v4 UUID) groups all autocomplete keystrokes + the final
     details call into one billable unit. After the details call fires,
     the token must be discarded — re-using it makes future calls billed
     individually (no session discount).

   Field mask
     Place Details responses are billed by which fields you ask for via
     the X-Goog-FieldMask header. We request only "Essentials" tier
     fields (addressComponents, formattedAddress, location, id). Adding
     fields like reviews, photos, businessStatus would bump us to Pro/
     Enterprise tiers and 10x+ the price.

   Graceful degradation
     If environment.googlePlaces.apiKey is falsy, every call resolves to
     null/[]. Consumers should fall back to plain text input. This lets
     dev/CI environments build without a key.
   ============================================================================= */

import { Injectable } from '@angular/core';
import { environment } from '../../environments/environment';

const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
const DETAILS_URL_BASE = 'https://places.googleapis.com/v1/places';

const DETAILS_FIELD_MASK = [
  'id',
  'displayName',
  'formattedAddress',
  'addressComponents',
  'location'
].join(',');

/* Address components returned by Google. We map these to the app's
   street/city/area/country fields. */
export interface PlaceAddressComponent {
  longText: string;
  shortText: string;
  types: string[];
  languageCode?: string;
}

/* Trimmed shape of the response from Place Details. We only consume what
   the app needs; ignoring the rest. */
export interface PlaceDetails {
  placeId: string;
  formattedAddress: string;
  addressComponents: PlaceAddressComponent[];
  location: { latitude: number; longitude: number };
  /* Convenience extracted fields — populated via parseAddressComponents()
     in this service. The mapping logic is centralized here so consumers
     don't all have to know which Google component types correspond to
     which app fields. */
  street: string | null;
  city: string | null;
  area: string | null;
  country: string | null;
  postalCode: string | null;
}

/* A single suggestion returned from the Autocomplete endpoint. We strip
   the Google response into just the bits the dropdown UI needs. */
export interface PlaceSuggestion {
  placeId: string;
  /* Full text — e.g. "Sheikh Zayed Road, Dubai - United Arab Emirates" */
  fullText: string;
  /* Main text — e.g. "Sheikh Zayed Road" — bold first line in UI */
  mainText: string;
  /* Secondary text — e.g. "Dubai - United Arab Emirates" — lighter second line */
  secondaryText: string;
}

@Injectable({ providedIn: 'root' })
export class PlacesService {
  /* Current session token. Lives across many autocomplete calls; reset
     after a details call fires (or session is abandoned). */
  private sessionToken: string | null = null;

  /* Begin a new session. Call once when the user starts typing. */
  startSession(): void {
    this.sessionToken = generateUuidV4();
  }

  /* Whether the API key is configured. Consumers can branch on this
     to hide the autocomplete UI when not available. */
  get isAvailable(): boolean {
    return !!environment.googlePlaces?.apiKey;
  }

  /* Fetch autocomplete suggestions for the given input.
     Returns [] on any error (network, key invalid, quota exceeded etc).
     Consumers should NOT show error UI for a failed autocomplete; the
     plain text input continues to work. */
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
      /* Restrict to UAE (per current product scope). To extend, add
         country codes to environment.googlePlaces.regions. */
      includedRegionCodes: environment.googlePlaces.regions || ['AE'],
      /* Bias toward addresses (street, building) rather than businesses. */
      includedPrimaryTypes: ['street_address', 'premise', 'subpremise', 'route'],
      languageCode: 'en'
    };

    try {
      const resp = await fetch(AUTOCOMPLETE_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Goog-Api-Key': environment.googlePlaces.apiKey
        },
        body: JSON.stringify(body)
      });
      if (!resp.ok) {
        return [];
      }
      const data = await resp.json();
      const suggestions = (data?.suggestions || []) as any[];
      return suggestions
        .filter(s => !!s.placePrediction)
        .map(s => {
          const p = s.placePrediction;
          return {
            placeId: p.placeId,
            fullText: p.text?.text || '',
            mainText: p.structuredFormat?.mainText?.text || p.text?.text || '',
            secondaryText: p.structuredFormat?.secondaryText?.text || ''
          };
        });
    } catch {
      return [];
    }
  }

  /* Fetch full details for a chosen suggestion. Terminates the session.
     Returns null on error — consumer should fall back to using the
     suggestion's text as-is. */
  async details(placeId: string): Promise<PlaceDetails | null> {
    if (!this.isAvailable || !placeId) {
      return null;
    }

    /* Build URL with the session token (if any). The token can be missing
       if startSession wasn't called — the details call still works, just
       billed without session benefits. */
    const url = new URL(`${DETAILS_URL_BASE}/${placeId}`);
    if (this.sessionToken) {
      url.searchParams.set('sessionToken', this.sessionToken);
    }

    try {
      const resp = await fetch(url.toString(), {
        method: 'GET',
        headers: {
          'X-Goog-Api-Key': environment.googlePlaces.apiKey,
          'X-Goog-FieldMask': DETAILS_FIELD_MASK
        }
      });
      if (!resp.ok) {
        return null;
      }
      const data = await resp.json();

      /* Parse the structured address components into convenience fields.
         Google returns a list like:
           [
             { types: ["street_number"], longText: "1600", ... },
             { types: ["route"],         longText: "Amphitheatre Pkwy", ... },
             { types: ["locality"],      longText: "Mountain View", ... },
             ...
           ]
         We extract street_number + route -> street, locality -> city,
         sublocality -> area, country -> country, postal_code -> postalCode. */
      const components = (data.addressComponents || []) as PlaceAddressComponent[];
      const parsed = this.parseAddressComponents(components);

      return {
        placeId: data.id || placeId,
        formattedAddress: data.formattedAddress || '',
        addressComponents: components,
        location: {
          latitude: data.location?.latitude || 0,
          longitude: data.location?.longitude || 0
        },
        ...parsed
      };
    } catch {
      return null;
    } finally {
      /* The session is terminated by this details call. Start fresh next time. */
      this.sessionToken = null;
    }
  }

  /* Map Google's typed address components to street/city/area/country.
     Type rules (refined for UAE addresses):
       - street_number + route  -> street (e.g. "1600 Amphitheatre Pkwy")
       - locality OR postal_town -> city  (e.g. "Dubai")
       - sublocality / sublocality_level_1 / neighborhood -> area
       - country               -> country  (long form)
       - postal_code           -> postalCode */
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
        /* First match wins to avoid overwriting with a coarser administrative
           area. UAE Google Places typically tags neighborhoods (e.g. "Dubai
           Marina") as sublocality_level_1 or neighborhood. */
        area = c.longText;
      } else if (types.includes('country')) {
        country = c.longText;
      } else if (types.includes('postal_code')) {
        postalCode = c.longText;
      }
    }

    /* Compose street: "{number} {route}", "{route}", or null */
    let street: string | null = null;
    if (route && streetNumber) {
      street = `${streetNumber} ${route}`;
    } else if (route) {
      street = route;
    } else if (streetNumber) {
      /* Edge case — only number, no route name. Unusual but possible. */
      street = streetNumber;
    }

    return { street, city, area, country, postalCode };
  }
}

/* Generate a v4 UUID without an external dependency. crypto.randomUUID
   is available in modern browsers and Node 19+; if missing we fall back
   to a Math.random-based generator (sufficient for session-token
   uniqueness, which doesn't need cryptographic strength). */
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
