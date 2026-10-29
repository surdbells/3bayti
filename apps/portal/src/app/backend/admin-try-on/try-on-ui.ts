/**
 * Pure presentation logic for the Virtual Try-On admin surfaces (the
 * admin/try-on page and the product page's Try-On card). No Angular, no I/O:
 * everything takes plain values and a translate function so it can be unit
 * tested without compiling a component.
 */
import {
  TRYON_IMAGE_INELIGIBLE,
  TryOnBulkResult,
  TryOnEligibility,
  TryOnIneligibleReason,
  TryOnSkipReason,
  TryOnStatus,
  tryOnApiError,
} from '../../services/try-on-admin.service';

/** `I18nService.t`-compatible translate function. */
export type Translate = (key: string, params?: Record<string, unknown>) => string;

/** Value of the Enabled / All filter tab ('' = all). */
export type TryOnFilterTab = 'true' | '';

export interface EligibilityBadge {
  readonly labelKey: string;
  readonly badgeClass: string;
}

/** Badge for an eligibility (null = the API didn't say). */
export function eligibilityBadge(e: TryOnEligibility | null): EligibilityBadge {
  if (!e) return { labelKey: 'tryon.elig.unknown', badgeClass: 'ax-badge ax-badge-neutral' };
  if (e.eligible) return { labelKey: 'tryon.elig.eligible', badgeClass: 'ax-badge ax-badge-success' };
  switch (e.reason) {
    case 'unsupported_format': return { labelKey: 'tryon.elig.unsupported_format', badgeClass: 'ax-badge ax-badge-danger' };
    case 'no_image': return { labelKey: 'tryon.elig.no_image', badgeClass: 'ax-badge ax-badge-warning' };
    case 'external_image': return { labelKey: 'tryon.elig.external_image', badgeClass: 'ax-badge ax-badge-warning' };
    default: return { labelKey: 'tryon.elig.ineligible', badgeClass: 'ax-badge ax-badge-warning' };
  }
}

/** Sentence explaining why the image can't be used (null reason = generic). */
export function reasonMessageKey(reason: TryOnIneligibleReason | null): string {
  switch (reason) {
    case 'unsupported_format': return 'tryon.reason.unsupported_format';
    case 'no_image': return 'tryon.reason.no_image';
    case 'external_image': return 'tryon.reason.external_image';
    default: return 'tryon.reason.ineligible';
  }
}

/** Tooltip for an eligibility badge: the rule when eligible/unknown, else why not. */
export function eligibilityHintKey(e: TryOnEligibility | null): string {
  return !e || e.eligible ? 'tryon.rule' : reasonMessageKey(e.reason);
}

/** Product lifecycle statuses that read as "live" (no badge shown). */
const LIVE_STATUSES = new Set(['', 'active', 'published']);

/** Whether to badge a product's status in the list (drafts, deleted, …). */
export function showProductStatus(status: string | null | undefined): boolean {
  return !LIVE_STATUSES.has(String(status ?? '').toLowerCase());
}

/**
 * Turning Try-On ON is allowed unless the API said the image is ineligible.
 * Unknown eligibility (null) is left to the server, which re-validates.
 */
export function canEnable(e: TryOnEligibility | null): boolean {
  return !e || e.eligible;
}

export interface ToggleState {
  readonly disabled: boolean;
  /** i18n key explaining why the switch is disabled (null when not, or while saving). */
  readonly hintKey: string | null;
}

/**
 * Whether the Try-On switch can be flipped. Disabling is always allowed (with
 * products.edit); enabling needs an eligible image.
 */
export function toggleState(enabled: boolean, e: TryOnEligibility | null, canEdit: boolean, busy: boolean): ToggleState {
  if (!canEdit) return { disabled: true, hintKey: 'tryon.toggle.read_only' };
  if (busy) return { disabled: true, hintKey: null };
  if (!enabled && !canEnable(e)) return { disabled: true, hintKey: reasonMessageKey(e?.reason ?? null) };
  return { disabled: false, hintKey: null };
}

/** Default tab: Enabled when any product is enabled, else All. */
export function defaultFilterTab(status: TryOnStatus | null): TryOnFilterTab {
  return status && status.enabled_products > 0 ? 'true' : '';
}

/**
 * Why Try-On is unavailable, as i18n keys (one per missing piece). When both
 * env flags are on but the provider still isn't available, the missing piece
 * is the OpenAI key.
 */
export function offReasonKeys(status: TryOnStatus | null): string[] {
  if (!status || status.available) return [];
  const keys: string[] = [];
  if (!status.tryon_flag) keys.push('tryon.off.flag_tryon');
  if (!status.ai_enabled) keys.push('tryon.off.flag_ai');
  if (!keys.length) keys.push('tryon.off.flag_key');
  return keys;
}

/** Change to the enabled-product count when a product goes prev → next. */
export function enabledDelta(prev: boolean, next: boolean): number {
  return prev === next ? 0 : next ? 1 : -1;
}

/** Order the skip reasons are listed in a summary. */
const SKIP_ORDER: readonly TryOnSkipReason[] = ['unsupported_format', 'no_image', 'external_image', 'not_found'];

/**
 * Toast for a bulk result, e.g. "12 enabled, 3 skipped: 2 GIF or unsupported
 * image, 1 no image". `ok` is false only when nothing was updated but
 * something was skipped (shown as an error toast).
 */
export function summarizeBulk(result: TryOnBulkResult, enabled: boolean, t: Translate): { ok: boolean; message: string } {
  const updated = result.updated.length;
  const updatedPart = t(enabled ? 'tryon.bulk.enabled' : 'tryon.bulk.disabled', { count: updated });
  if (!result.skipped.length) {
    return { ok: true, message: t('tryon.bulk.summary', { updated: updatedPart }) };
  }
  const counts = new Map<TryOnSkipReason, number>();
  for (const s of result.skipped) counts.set(s.reason, (counts.get(s.reason) ?? 0) + 1);
  const reasons = SKIP_ORDER
    .filter((r) => counts.has(r))
    .map((r) => t(`tryon.skip.${r}`, { count: counts.get(r) }))
    .join(t('tryon.list_separator'));
  return {
    ok: updated > 0,
    message: t('tryon.bulk.summary_skipped', { updated: updatedPart, skipped: result.skipped.length, reasons }),
  };
}

/**
 * Message for a failed single toggle. A 422 TRYON_IMAGE_INELIGIBLE shows the
 * localized reason (the API's message is English-only, so it is just the
 * fallback for a reason this client doesn't know); a 404 says the product is
 * gone; anything else uses the API message or `fallbackKey`.
 */
export function toggleErrorMessage(err: any, t: Translate, fallbackKey = 'tryon.toggle.error'): string {
  if (err?.status === 0) return t('tryon.network_error');
  const api = tryOnApiError(err);
  if (api?.code === TRYON_IMAGE_INELIGIBLE) {
    return api.reason ? t(reasonMessageKey(api.reason)) : (api.message || t(reasonMessageKey(null)));
  }
  if (err?.status === 404) return t('tryon.toggle.not_found');
  return api?.message || t(fallbackKey);
}

/**
 * The eligibility a 422 TRYON_IMAGE_INELIGIBLE reports, so the row/card can
 * show the real reason (and lock the switch) after a refused enable.
 */
export function eligibilityFromError(err: any): TryOnEligibility | null {
  const api = tryOnApiError(err);
  return api?.code === TRYON_IMAGE_INELIGIBLE ? { eligible: false, reason: api.reason } : null;
}
