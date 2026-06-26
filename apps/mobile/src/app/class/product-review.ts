/**
 * A public product review as returned by ReviewSerializer::publicShape
 * (GET /v3/products/:productId/reviews → ReviewService). Approved-only;
 * never carries status or the reviewer's email.
 */
export interface ProductReview {
  id: number;
  product_id: number;
  product_name: string | null;
  /** Reviewer display name (may be null → render anonymously). */
  reviewer: string | null;
  /** 1–5. v3 stores star as a string; coerce to a number when reading. */
  star: number;
  title: string | null;
  comment: string | null;
  vendor_reply: string | null;
  is_verified: boolean;
  helpful_count: number;
  /** ISO-8601 (ATOM) timestamp. */
  created_at: string;
}

/** A page of approved product reviews + total for "load more". */
export interface ProductReviewPage {
  reviews: ProductReview[];
  total: number;
  hasMore: boolean;
}
