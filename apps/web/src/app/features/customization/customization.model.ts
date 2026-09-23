/**
 * Web-side shapes for the P5 bespoke-customization workflow.
 *
 * Field-for-field mirror of the API CustomizationRequestSerializer::customerShape
 * (apps/api/src/Http/Serializers/CustomizationRequestSerializer.php).
 */

/** Canonical lifecycle statuses (mirror CustomizationRequest::ALL_STATUSES). */
export type CustomizationStatus =
  | 'pending'
  | 'quoted'
  | 'accepted'
  | 'paid'
  | 'completed'
  | 'declined'
  | 'rejected'
  | 'cancelled';

/** The subset of the embedded product card the customization UI renders. */
export interface CustomizationProduct {
  id: number | null;
  slug: string;
  name: string;
  primary_image: string | null;
  price: string | null;
  sale_price: string | null;
}

export interface CustomizationVendor {
  id: number;
  name: string;
  slug: string;
}

export interface CustomizationQuote {
  amount: string;
  currency: string | null;
  lead_time_days: number | null;
  vendor_notes: string | null;
}

export interface CustomizationTimestamps {
  requested_at: string | null;
  quoted_at: string | null;
  accepted_at: string | null;
  paid_at: string | null;
  completed_at: string | null;
  declined_at: string | null;
  rejected_at: string | null;
  cancelled_at: string | null;
}

export interface CustomizationRequest {
  id: number;
  status: CustomizationStatus;
  product: CustomizationProduct;
  vendor: CustomizationVendor;
  customer_notes: string;
  measurement_snapshot: Record<string, unknown> | null;
  quote: CustomizationQuote | null;
  payment_order_reference: string | null;
  timestamps: CustomizationTimestamps;
  is_terminal: boolean;
}

/** Body for POST /me/customization-requests. */
export interface SubmitCustomizationInput {
  product_slug: string;
  description: string;
  measurement_snapshot?: Record<string, number> | null;
}

/** A page of the customer's own requests. */
export interface CustomizationRequestsPage {
  items: CustomizationRequest[];
  hasMore: boolean;
  total: number;
}
