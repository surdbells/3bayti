/**
 * Address domain types, mirror apps/api Address DTOs.
 *
 * The API returns addresses for the authenticated user via
 * `/v3/me/addresses`. Each address has a recipient (name + phone),
 * an emirate + area (UAE-centric for Y.2), and optional street/building/
 * postal/label fields. Default-flag flips are done via a PATCH route
 * (one operation, not part of update).
 */

export interface Address {
  id: number;
  recipient_name: string;
  /** E.164 with leading '+', e.g. '+971501234567'. */
  recipient_phone: string;
  emirate: string;
  area: string;
  street_address: string | null;
  building_details: string | null;
  postal_code: string | null;
  /** User-defined label e.g. 'Home', 'Office'. */
  label: string | null;
  is_default_shipping: boolean;
  is_default_billing: boolean;
  /** ISO 8601 timestamps. */
  created_at: string;
  updated_at: string;
}

export interface CreateAddressInput {
  recipient_name: string;
  recipient_phone: string;
  emirate: string;
  area: string;
  street_address?: string | null;
  building_details?: string | null;
  postal_code?: string | null;
  label?: string | null;
  /** When true, this becomes the new default shipping address. */
  is_default: boolean;
}

/** PUT /v3/me/addresses/:id, same fields as create except is_default is
 *  handled via the separate /default endpoint to keep semantics clean. */
export interface UpdateAddressInput {
  recipient_name: string;
  recipient_phone: string;
  emirate: string;
  area: string;
  street_address?: string | null;
  building_details?: string | null;
  postal_code?: string | null;
  label?: string | null;
}

export interface SetDefaultInput {
  shipping?: boolean;
  billing?: boolean;
}

/** UAE emirates list, used in the address form's dropdown. The API
 *  doesn't constrain emirate to this list (it's a free string), but we
 *  curate the dropdown for UX. Mobile uses the same list. */
export const UAE_EMIRATES: readonly string[] = [
  'Abu Dhabi',
  'Dubai',
  'Sharjah',
  'Ajman',
  'Umm Al-Quwain',
  'Ras Al Khaimah',
  'Fujairah',
];

/** Address-related error codes. */
export const ADDRESS_ERROR_CODES = {
  NOT_FOUND: 'ADDRESS_NOT_FOUND',
  VALIDATION_FAILED: 'VALIDATION_FAILED',
  ADDRESS_IN_USE: 'ADDRESS_IN_USE',
} as const;

export type AddressErrorCode = (typeof ADDRESS_ERROR_CODES)[keyof typeof ADDRESS_ERROR_CODES];
