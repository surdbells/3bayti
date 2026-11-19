/**
 * Shared, framework-free helpers for the admin cashback pages (rules list,
 * rule form, award ledger). Kept pure so the parsing / payload / validation
 * rules are unit-tested without a TestBed.
 *
 * Contract source of truth: apps/api Http/Serializers/CashbackSerializer.php
 * and Http/Controllers/Admin/Cashback/*. Money is always a "10.00" string,
 * dates are ISO-8601, ids are v3 ids (never legacy ids).
 */

export type CashbackRewardType = 'percentage' | 'fixed';
export type CashbackScope = 'all' | 'stores' | 'products' | 'categories';
export type CashbackChannel = 'all' | 'web' | 'mobile';
export type CashbackRuleStatus = 'active' | 'inactive' | 'scheduled' | 'expired';
export type CashbackAwardStatus = 'pending' | 'issued' | 'cancelled' | 'reversed';
export type CashbackTargetType = 'vendor' | 'product' | 'category';

export const CASHBACK_SCOPES: readonly CashbackScope[] = ['all', 'stores', 'products', 'categories'];
export const CASHBACK_CHANNELS: readonly CashbackChannel[] = ['all', 'web', 'mobile'];
export const CASHBACK_RULE_STATUSES: readonly CashbackRuleStatus[] = ['active', 'inactive', 'scheduled', 'expired'];
export const CASHBACK_AWARD_STATUSES: readonly CashbackAwardStatus[] = ['pending', 'issued', 'cancelled', 'reversed'];

/** Limits mirrored from CashbackRule / CashbackProgramSettings / GiftCard. */
export const CASHBACK_LIMITS = {
  maxHoldDays: 365,
  maxValidityDays: 1825,
  minPriority: -1000,
  maxPriority: 1000,
  /** One award = one gift card; GiftCard::MAX_DENOMINATION. */
  maxAwardPerOrder: 10000,
} as const;

/** Which target type a scoped rule includes. */
export const SCOPE_TARGET_TYPE: Readonly<Record<Exclude<CashbackScope, 'all'>, CashbackTargetType>> = {
  stores: 'vendor',
  products: 'product',
  categories: 'category',
};

export interface CashbackTarget {
  id?: number | null;
  target_type: CashbackTargetType;
  target_id: number;
  is_exclusion: boolean;
  label?: string | null;
}

export interface CashbackRule {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  status: CashbackRuleStatus;
  priority: number;
  reward_type: CashbackRewardType;
  reward_value: string;
  max_reward_per_order: string | null;
  min_eligible_amount: string | null;
  valid_from: string | null;
  valid_until: string | null;
  scope: CashbackScope;
  channel: CashbackChannel;
  first_order_only: boolean;
  per_customer_limit: number | null;
  budget_total: string | null;
  budget_spent: string;
  budget_remaining: string | null;
  awards_count: number;
  allow_with_promo: boolean;
  hold_days: number;
  gift_card_validity_days: number;
  targets: CashbackTarget[];
  created_at: string | null;
  updated_at: string | null;
}

export interface CashbackSettings {
  enabled: boolean;
  min_award: string;
  max_award_per_order: string | null;
  default_hold_days: number;
  default_validity_days: number;
}

export interface CashbackAward {
  id: number;
  status: CashbackAwardStatus;
  amount: string;
  accrued_amount: string;
  reversed_amount: string;
  uncollected_amount: string;
  currency: string;
  hold_days: number | null;
  release_at: string | null;
  released_at: string | null;
  cancelled_at: string | null;
  reversed_at: string | null;
  status_reason: string | null;
  order: { id: number; order_reference: string; status: string; total: string; paid_at: string | null; delivered_at: string | null } | null;
  customer: { id: number; email: string | null; name: string | null } | null;
  gift_card: { id: number; code: string; balance: string; status: string } | null;
  rules: { id: number; name: string }[];
  created_at: string | null;
  /** Detail only. */
  lines?: {
    id: number; rule_id: number; rule_name: string; order_item_id: number;
    base_amount: string; amount: string; accrued_amount: string;
  }[];
  /** Detail only: accrual snapshot (may carry `recoveries`). */
  snapshot?: Record<string, unknown> | null;
}

// ── Parsing ──────────────────────────────────────────────────────────────

const str = (v: unknown, d = ''): string => (v == null ? d : String(v));
const strOrNull = (v: unknown): string | null => (v == null || v === '' ? null : String(v));
const num = (v: unknown, d = 0): number => {
  const n = Number(v);
  return Number.isFinite(n) ? n : d;
};
const numOrNull = (v: unknown): number | null => (v == null || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));

/** Unwrap a `{ data: X }` envelope (or accept X directly). */
export function unwrap<T = any>(res: any): T {
  return (res && typeof res === 'object' && 'data' in res ? res.data : res) as T;
}

export function parseSettings(raw: any): CashbackSettings {
  const s = raw ?? {};
  return {
    enabled: s.enabled === true,
    min_award: str(s.min_award, '1.00'),
    max_award_per_order: strOrNull(s.max_award_per_order),
    default_hold_days: num(s.default_hold_days, 14),
    default_validity_days: num(s.default_validity_days, 365),
  };
}

export function parseRule(raw: any): CashbackRule {
  const r = raw ?? {};
  return {
    id: num(r.id),
    name: str(r.name),
    description: strOrNull(r.description),
    is_active: r.is_active === true,
    status: (CASHBACK_RULE_STATUSES as readonly string[]).includes(r.status) ? r.status : (r.is_active ? 'active' : 'inactive'),
    priority: num(r.priority),
    reward_type: r.reward_type === 'fixed' ? 'fixed' : 'percentage',
    reward_value: str(r.reward_value, '0.00'),
    max_reward_per_order: strOrNull(r.max_reward_per_order),
    min_eligible_amount: strOrNull(r.min_eligible_amount),
    valid_from: strOrNull(r.valid_from),
    valid_until: strOrNull(r.valid_until),
    scope: (CASHBACK_SCOPES as readonly string[]).includes(r.scope) ? r.scope : 'all',
    channel: (CASHBACK_CHANNELS as readonly string[]).includes(r.channel) ? r.channel : 'all',
    first_order_only: r.first_order_only === true,
    per_customer_limit: numOrNull(r.per_customer_limit),
    budget_total: strOrNull(r.budget_total),
    budget_spent: str(r.budget_spent, '0.00'),
    budget_remaining: strOrNull(r.budget_remaining),
    awards_count: num(r.awards_count),
    allow_with_promo: r.allow_with_promo === true,
    hold_days: num(r.hold_days, 14),
    gift_card_validity_days: num(r.gift_card_validity_days, 365),
    targets: Array.isArray(r.targets)
      ? r.targets
          .filter((t: any) => t && ['vendor', 'product', 'category'].includes(t.target_type) && Number(t.target_id) > 0)
          .map((t: any): CashbackTarget => ({
            id: numOrNull(t.id),
            target_type: t.target_type,
            target_id: Number(t.target_id),
            is_exclusion: t.is_exclusion === true,
            label: strOrNull(t.label),
          }))
      : [],
    created_at: strOrNull(r.created_at),
    updated_at: strOrNull(r.updated_at),
  };
}

export function parseAward(raw: any): CashbackAward {
  const a = raw ?? {};
  const o = a.order;
  const c = a.customer;
  const g = a.gift_card;
  return {
    id: num(a.id),
    status: (CASHBACK_AWARD_STATUSES as readonly string[]).includes(a.status) ? a.status : 'pending',
    amount: str(a.amount, '0.00'),
    accrued_amount: str(a.accrued_amount ?? a.amount, '0.00'),
    reversed_amount: str(a.reversed_amount, '0.00'),
    uncollected_amount: str(a.uncollected_amount, '0.00'),
    currency: str(a.currency, 'AED'),
    hold_days: numOrNull(a.hold_days),
    release_at: strOrNull(a.release_at),
    released_at: strOrNull(a.released_at),
    cancelled_at: strOrNull(a.cancelled_at),
    reversed_at: strOrNull(a.reversed_at),
    status_reason: strOrNull(a.status_reason),
    order: o && typeof o === 'object' ? {
      id: num(o.id),
      order_reference: str(o.order_reference),
      status: str(o.status),
      total: str(o.total, '0.00'),
      paid_at: strOrNull(o.paid_at),
      delivered_at: strOrNull(o.delivered_at),
    } : null,
    customer: c && typeof c === 'object' ? { id: num(c.id), email: strOrNull(c.email), name: strOrNull(c.name) } : null,
    gift_card: g && typeof g === 'object' && Number(g.id) > 0
      ? { id: num(g.id), code: str(g.code), balance: str(g.balance, '0.00'), status: str(g.status) }
      : null,
    rules: Array.isArray(a.rules) ? a.rules.map((r: any) => ({ id: num(r?.id), name: str(r?.name) })) : [],
    created_at: strOrNull(a.created_at),
    lines: Array.isArray(a.lines)
      ? a.lines.map((l: any) => ({
          id: num(l.id),
          rule_id: num(l.rule_id),
          rule_name: str(l.rule_name),
          order_item_id: num(l.order_item_id),
          base_amount: str(l.base_amount, '0.00'),
          amount: str(l.amount, '0.00'),
          accrued_amount: str(l.accrued_amount ?? l.amount, '0.00'),
        }))
      : undefined,
    snapshot: a.snapshot && typeof a.snapshot === 'object' ? a.snapshot : null,
  };
}

/** `snapshot.recoveries` (written-off spend recovered later), if any. */
export function awardRecoveries(award: CashbackAward): { at: string | null; amount: string; order_reference: string }[] {
  const list = (award.snapshot as any)?.recoveries;
  if (!Array.isArray(list)) return [];
  return list.map((r: any) => ({ at: strOrNull(r?.at), amount: str(r?.amount, '0.00'), order_reference: str(r?.order_reference) }));
}

// ── Display helpers ──────────────────────────────────────────────────────

/** "5%" or "AED 10.00". */
export function rewardLabel(rule: Pick<CashbackRule, 'reward_type' | 'reward_value'>, currency = 'AED'): string {
  const v = Number(rule.reward_value);
  if (rule.reward_type === 'percentage') return `${Number.isFinite(v) ? +v.toFixed(2) : rule.reward_value}%`;
  return `${currency} ${formatMoney(rule.reward_value)}`;
}

export function formatMoney(v: string | number | null | undefined): string {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return String(v ?? '');
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Share of the budget used (0-100), or null when the rule has no budget. */
export function budgetPercent(rule: Pick<CashbackRule, 'budget_total' | 'budget_spent'>): number | null {
  const total = Number(rule.budget_total);
  if (rule.budget_total == null || !Number.isFinite(total) || total <= 0) return null;
  const spent = Number(rule.budget_spent);
  const pct = (Number.isFinite(spent) ? spent : 0) / total * 100;
  return Math.max(0, Math.min(100, Math.round(pct * 10) / 10));
}

export function budgetBarClass(pct: number | null): string {
  if (pct == null) return '';
  if (pct >= 100) return 'ax-progress-bar-danger';
  if (pct >= 75) return 'ax-progress-bar-warning';
  return 'ax-progress-bar-success';
}

export function ruleStatusBadge(status: string): string {
  switch (status) {
    case 'active': return 'ax-badge-success';
    case 'scheduled': return 'ax-badge-info';
    case 'expired': return 'ax-badge-warning';
    default: return 'ax-badge-neutral';
  }
}

export function awardStatusBadge(status: string): string {
  switch (status) {
    case 'pending': return 'ax-badge-warning';
    case 'issued': return 'ax-badge-success';
    case 'reversed': return 'ax-badge-danger';
    default: return 'ax-badge-neutral';
  }
}

/** Flatten the /v3/categories tree into options labelled by their path. */
export function flattenCategories(tree: any[], parentLabel = '', depth = 0): { id: number; label: string }[] {
  const out: { id: number; label: string }[] = [];
  if (!Array.isArray(tree) || depth > 8) return out;
  for (const c of tree) {
    const id = Number(c?.id);
    if (!(id > 0)) continue;
    const name = str(c?.name, `#${id}`);
    const label = parentLabel ? `${parentLabel} › ${name}` : name;
    out.push({ id, label });
    if (Array.isArray(c?.children) && c.children.length) {
      out.push(...flattenCategories(c.children, label, depth + 1));
    }
  }
  return out;
}

// ── Rule form ────────────────────────────────────────────────────────────

/** Template-driven form model. Money stays a string to avoid float drift. */
export interface CashbackRuleForm {
  name: string;
  description: string;
  is_active: boolean;
  priority: number | null;
  reward_type: CashbackRewardType;
  reward_value: string;
  max_reward_per_order: string;
  min_eligible_amount: string;
  valid_from: string;
  valid_until: string;
  scope: CashbackScope;
  channel: CashbackChannel;
  first_order_only: boolean;
  per_customer_limit: number | null;
  budget_total: string;
  allow_with_promo: boolean;
  hold_days: number | null;
  gift_card_validity_days: number | null;
  /** Inclusion ids for the scope's own target type (v3 ids). */
  include_ids: number[];
  exclude_vendor_ids: number[];
  exclude_product_ids: number[];
  exclude_category_ids: number[];
}

export function emptyRuleForm(settings?: Pick<CashbackSettings, 'default_hold_days' | 'default_validity_days'> | null): CashbackRuleForm {
  return {
    name: '',
    description: '',
    is_active: true,
    priority: 0,
    reward_type: 'percentage',
    reward_value: '',
    max_reward_per_order: '',
    min_eligible_amount: '',
    valid_from: '',
    valid_until: '',
    scope: 'all',
    channel: 'all',
    first_order_only: false,
    per_customer_limit: null,
    budget_total: '',
    allow_with_promo: false,
    hold_days: settings?.default_hold_days ?? 14,
    gift_card_validity_days: settings?.default_validity_days ?? 365,
    include_ids: [],
    exclude_vendor_ids: [],
    exclude_product_ids: [],
    exclude_category_ids: [],
  };
}

export function ruleToForm(rule: CashbackRule): CashbackRuleForm {
  const ids = (type: CashbackTargetType, exclusion: boolean) =>
    rule.targets.filter((t) => t.target_type === type && t.is_exclusion === exclusion).map((t) => t.target_id);
  const includeType = rule.scope === 'all' ? null : SCOPE_TARGET_TYPE[rule.scope];
  return {
    name: rule.name,
    description: rule.description ?? '',
    is_active: rule.is_active,
    priority: rule.priority,
    reward_type: rule.reward_type,
    reward_value: rule.reward_value,
    max_reward_per_order: rule.max_reward_per_order ?? '',
    min_eligible_amount: rule.min_eligible_amount ?? '',
    valid_from: toLocalInput(rule.valid_from),
    valid_until: toLocalInput(rule.valid_until),
    scope: rule.scope,
    channel: rule.channel,
    first_order_only: rule.first_order_only,
    per_customer_limit: rule.per_customer_limit,
    budget_total: rule.budget_total ?? '',
    allow_with_promo: rule.allow_with_promo,
    hold_days: rule.hold_days,
    gift_card_validity_days: rule.gift_card_validity_days,
    include_ids: includeType ? ids(includeType, false) : [],
    exclude_vendor_ids: ids('vendor', true),
    exclude_product_ids: ids('product', true),
    exclude_category_ids: ids('category', true),
  };
}

const moneyRe = (intDigits: number) => new RegExp(`^\\d{1,${intDigits}}(\\.\\d{1,2})?$`);

/** "12.5" → "12.50"; '' → null. Assumes it already passed validation. */
function moneyOrNull(v: string | number | null | undefined): string | null {
  const s = v == null ? '' : String(v).trim();
  if (s === '') return null;
  const n = Number(s);
  return Number.isFinite(n) ? n.toFixed(2) : s;
}

function intOrNull(v: number | string | null | undefined): number | null {
  if (v == null || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? Math.trunc(n) : null;
}

/** Build the POST/PUT body. `targets` always replaces the whole target set. */
export function formToPayload(form: CashbackRuleForm): Record<string, unknown> {
  const targets: { target_type: CashbackTargetType; target_id: number; is_exclusion: boolean }[] = [];
  if (form.scope !== 'all') {
    const type = SCOPE_TARGET_TYPE[form.scope];
    for (const id of uniqIds(form.include_ids)) targets.push({ target_type: type, target_id: id, is_exclusion: false });
  }
  const excl: [CashbackTargetType, number[]][] = [
    ['vendor', form.exclude_vendor_ids],
    ['product', form.exclude_product_ids],
    ['category', form.exclude_category_ids],
  ];
  for (const [type, ids] of excl) {
    for (const id of uniqIds(ids)) targets.push({ target_type: type, target_id: id, is_exclusion: true });
  }

  return {
    name: form.name.trim(),
    description: form.description.trim() || null,
    is_active: form.is_active,
    priority: intOrNull(form.priority) ?? 0,
    reward_type: form.reward_type,
    reward_value: moneyOrNull(form.reward_value),
    max_reward_per_order: moneyOrNull(form.max_reward_per_order),
    min_eligible_amount: moneyOrNull(form.min_eligible_amount),
    valid_from: form.valid_from ? new Date(form.valid_from).toISOString() : null,
    valid_until: form.valid_until ? new Date(form.valid_until).toISOString() : null,
    scope: form.scope,
    channel: form.channel,
    first_order_only: form.first_order_only,
    per_customer_limit: intOrNull(form.per_customer_limit),
    budget_total: moneyOrNull(form.budget_total),
    allow_with_promo: form.allow_with_promo,
    hold_days: intOrNull(form.hold_days),
    gift_card_validity_days: intOrNull(form.gift_card_validity_days),
    targets,
  };
}

function uniqIds(ids: readonly (number | string)[]): number[] {
  const out: number[] = [];
  for (const raw of ids ?? []) {
    const n = Number(raw);
    if (Number.isInteger(n) && n > 0 && !out.includes(n)) out.push(n);
  }
  return out;
}

/**
 * Client-side checks mirroring CashbackRuleInput / CashbackRule. Returns
 * field → i18n key (empty object = valid). The server stays authoritative.
 */
export function validateRuleForm(form: CashbackRuleForm): Record<string, string> {
  const e: Record<string, string> = {};
  if (!form.name.trim()) e['name'] = 'cashback.err.name_required';
  else if (form.name.trim().length > 120) e['name'] = 'cashback.err.name_long';

  const rv = String(form.reward_value ?? '').trim();
  if (!moneyRe(8).test(rv) || Number(rv) <= 0) e['reward_value'] = 'cashback.err.reward_value';
  else if (form.reward_type === 'percentage' && Number(rv) > 100) e['reward_value'] = 'cashback.err.reward_pct_max';

  for (const f of ['max_reward_per_order', 'min_eligible_amount'] as const) {
    const v = String(form[f] ?? '').trim();
    if (v !== '' && !moneyRe(8).test(v)) e[f] = 'cashback.err.money';
    else if (f === 'max_reward_per_order' && v !== '' && Number(v) <= 0) e[f] = 'cashback.err.positive';
  }
  const budget = String(form.budget_total ?? '').trim();
  if (budget !== '' && (!moneyRe(10).test(budget) || Number(budget) <= 0)) e['budget_total'] = 'cashback.err.budget';

  const prio = form.priority;
  if (prio != null && String(prio) !== '' && (!Number.isInteger(Number(prio))
    || Number(prio) < CASHBACK_LIMITS.minPriority || Number(prio) > CASHBACK_LIMITS.maxPriority)) {
    e['priority'] = 'cashback.err.priority';
  }
  const pcl = form.per_customer_limit;
  if (pcl != null && String(pcl) !== '' && (!Number.isInteger(Number(pcl)) || Number(pcl) < 1)) {
    e['per_customer_limit'] = 'cashback.err.per_customer_limit';
  }
  const hold = Number(form.hold_days);
  if (form.hold_days == null || String(form.hold_days) === '' || !Number.isInteger(hold) || hold < 0 || hold > CASHBACK_LIMITS.maxHoldDays) {
    e['hold_days'] = 'cashback.err.hold_days';
  }
  const val = Number(form.gift_card_validity_days);
  if (form.gift_card_validity_days == null || String(form.gift_card_validity_days) === '' || !Number.isInteger(val)
    || val < 1 || val > CASHBACK_LIMITS.maxValidityDays) {
    e['gift_card_validity_days'] = 'cashback.err.validity_days';
  }
  if (form.valid_from && form.valid_until && new Date(form.valid_until) < new Date(form.valid_from)) {
    e['valid_until'] = 'cashback.err.dates';
  }
  if (form.scope !== 'all' && uniqIds(form.include_ids).length === 0) {
    e['targets'] = 'cashback.err.targets_required';
  }
  return e;
}

/** Validate the programme settings card. field → i18n key. */
export function validateSettings(s: { min_award: string; max_award_per_order: string; default_hold_days: number | null; default_validity_days: number | null }): Record<string, string> {
  const e: Record<string, string> = {};
  const min = String(s.min_award ?? '').trim();
  if (!moneyRe(8).test(min)) e['min_award'] = 'cashback.err.money';
  const max = String(s.max_award_per_order ?? '').trim();
  if (max !== '') {
    if (!moneyRe(8).test(max) || Number(max) <= 0) e['max_award_per_order'] = 'cashback.err.positive';
    else if (Number(max) > CASHBACK_LIMITS.maxAwardPerOrder) e['max_award_per_order'] = 'cashback.err.max_award_cap';
    else if (!e['min_award'] && Number(max) < Number(min)) e['max_award_per_order'] = 'cashback.err.max_below_min';
  }
  const hold = Number(s.default_hold_days);
  if (s.default_hold_days == null || !Number.isInteger(hold) || hold < 0 || hold > CASHBACK_LIMITS.maxHoldDays) {
    e['default_hold_days'] = 'cashback.err.hold_days';
  }
  const val = Number(s.default_validity_days);
  if (s.default_validity_days == null || !Number.isInteger(val) || val < 1 || val > CASHBACK_LIMITS.maxValidityDays) {
    e['default_validity_days'] = 'cashback.err.validity_days';
  }
  return e;
}

/** Field → first server message from a v3 422 (details.fields). */
export function serverFieldErrors(err: any): Record<string, string> {
  const fields = err?.error?.error?.details?.fields ?? err?.error?.details?.fields;
  const out: Record<string, string> = {};
  if (!fields || typeof fields !== 'object') return out;
  for (const [k, v] of Object.entries(fields as Record<string, unknown>)) {
    const msg = Array.isArray(v) ? v.find((x) => typeof x === 'string') : typeof v === 'string' ? v : null;
    if (msg) out[k.startsWith('targets') ? 'targets' : k] = String(msg);
  }
  return out;
}

/**
 * ISO datetime → 'YYYY-MM-DDTHH:mm' in the browser's local time, so it
 * round-trips with `new Date(local).toISOString()` on save.
 */
export function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(String(iso));
  if (isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function fmtDate(iso: string | null | undefined, withTime = false): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  return withTime ? d.toLocaleString() : d.toLocaleDateString();
}
