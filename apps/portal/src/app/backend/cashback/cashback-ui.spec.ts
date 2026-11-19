import {
  awardRecoveries,
  budgetBarClass,
  budgetPercent,
  emptyRuleForm,
  flattenCategories,
  formToPayload,
  parseAward,
  parseRule,
  parseSettings,
  rewardLabel,
  ruleToForm,
  serverFieldErrors,
  validateRuleForm,
  validateSettings,
} from './cashback-ui';

describe('cashback-ui', () => {
  const ruleRaw = {
    id: 7,
    name: 'Store promo',
    description: null,
    is_active: true,
    status: 'active',
    priority: 5,
    reward_type: 'percentage',
    reward_value: '5.00',
    max_reward_per_order: '50.00',
    min_eligible_amount: null,
    valid_from: null,
    valid_until: '2026-12-31T20:00:00+00:00',
    scope: 'stores',
    channel: 'web',
    first_order_only: false,
    per_customer_limit: 2,
    budget_total: '1000.00',
    budget_spent: '250.00',
    budget_remaining: '750.00',
    awards_count: 3,
    allow_with_promo: true,
    hold_days: 7,
    gift_card_validity_days: 90,
    targets: [
      { id: 1, target_type: 'vendor', target_id: 11, is_exclusion: false, label: 'Acme' },
      { id: 2, target_type: 'product', target_id: 99, is_exclusion: true, label: 'Sale item' },
      { id: 3, target_type: 'bogus', target_id: 5, is_exclusion: false },
    ],
  };

  it('parses a rule and drops unknown target types', () => {
    const r = parseRule(ruleRaw);
    expect(r.id).toBe(7);
    expect(r.scope).toBe('stores');
    expect(r.channel).toBe('web');
    expect(r.targets.length).toBe(2);
    expect(r.targets[0]).toEqual(jasmine.objectContaining({ target_type: 'vendor', target_id: 11, is_exclusion: false }));
  });

  it('round-trips a rule through the form into a payload', () => {
    const form = ruleToForm(parseRule(ruleRaw));
    expect(form.include_ids).toEqual([11]);
    expect(form.exclude_product_ids).toEqual([99]);
    const body = formToPayload(form);
    expect(body['reward_value']).toBe('5.00');
    expect(body['max_reward_per_order']).toBe('50.00');
    expect(body['min_eligible_amount']).toBeNull();
    expect(body['per_customer_limit']).toBe(2);
    expect(body['targets']).toEqual([
      { target_type: 'vendor', target_id: 11, is_exclusion: false },
      { target_type: 'product', target_id: 99, is_exclusion: true },
    ]);
  });

  it('sends exclusions only for scope "all"', () => {
    const form = { ...emptyRuleForm(), name: 'All', reward_value: '2', include_ids: [4], exclude_vendor_ids: [3, 3] };
    const body = formToPayload(form);
    expect(body['targets']).toEqual([{ target_type: 'vendor', target_id: 3, is_exclusion: true }]);
    expect(body['description']).toBeNull();
    expect(body['valid_from']).toBeNull();
  });

  it('validates the rule form', () => {
    const bad = { ...emptyRuleForm(), reward_type: 'percentage' as const, reward_value: '150', scope: 'products' as const, hold_days: 400, budget_total: '12345678901.00' };
    const e = validateRuleForm(bad);
    expect(e['name']).toBe('cashback.err.name_required');
    expect(e['reward_value']).toBe('cashback.err.reward_pct_max');
    expect(e['targets']).toBe('cashback.err.targets_required');
    expect(e['hold_days']).toBe('cashback.err.hold_days');
    expect(e['budget_total']).toBe('cashback.err.budget');

    const ok = { ...emptyRuleForm(), name: 'OK', reward_value: '10.5' };
    expect(validateRuleForm(ok)).toEqual({});
    expect(validateRuleForm({ ...ok, reward_value: '123456789' })['reward_value']).toBe('cashback.err.reward_value');
  });

  it('validates settings including the 10000 cap', () => {
    expect(validateSettings({ min_award: '1.00', max_award_per_order: '10000.01', default_hold_days: 14, default_validity_days: 365 })['max_award_per_order'])
      .toBe('cashback.err.max_award_cap');
    expect(validateSettings({ min_award: '5', max_award_per_order: '2', default_hold_days: 14, default_validity_days: 365 })['max_award_per_order'])
      .toBe('cashback.err.max_below_min');
    expect(validateSettings({ min_award: '1', max_award_per_order: '', default_hold_days: 0, default_validity_days: 1 })).toEqual({});
  });

  it('computes budget usage', () => {
    expect(budgetPercent({ budget_total: null, budget_spent: '5' })).toBeNull();
    expect(budgetPercent({ budget_total: '200.00', budget_spent: '50.00' })).toBe(25);
    expect(budgetPercent({ budget_total: '100.00', budget_spent: '150.00' })).toBe(100);
    expect(budgetBarClass(100)).toBe('ax-progress-bar-danger');
    expect(budgetBarClass(80)).toBe('ax-progress-bar-warning');
  });

  it('labels rewards', () => {
    expect(rewardLabel({ reward_type: 'percentage', reward_value: '5.00' })).toBe('5%');
    expect(rewardLabel({ reward_type: 'fixed', reward_value: '10' })).toBe('AED 10.00');
  });

  it('parses awards including the new fields', () => {
    const a = parseAward({
      id: 3, status: 'issued', amount: '12.00', accrued_amount: '15.00', reversed_amount: '2.00', uncollected_amount: '1.00',
      currency: 'AED', order: { id: 9, order_reference: '3B-1', status: 'delivered', total: '300.00' },
      gift_card: { id: 44, code: 'ABCD', balance: '10.00', status: 'active' },
      rules: [{ id: 7, name: 'Store promo' }],
      lines: [{ id: 1, rule_id: 7, rule_name: 'Store promo', order_item_id: 5, base_amount: '300.00', amount: '12.00', accrued_amount: '15.00' }],
      snapshot: { recoveries: [{ at: '2026-11-01T00:00:00+00:00', amount: '1.00', order_reference: '3B-2' }] },
    });
    expect(a.uncollected_amount).toBe('1.00');
    expect(a.gift_card?.id).toBe(44);
    expect(a.lines?.[0].accrued_amount).toBe('15.00');
    expect(awardRecoveries(a)).toEqual([{ at: '2026-11-01T00:00:00+00:00', amount: '1.00', order_reference: '3B-2' }]);
    expect(parseAward({ id: 1, status: 'weird' }).status).toBe('pending');
  });

  it('parses settings defensively', () => {
    expect(parseSettings(null)).toEqual({ enabled: false, min_award: '1.00', max_award_per_order: null, default_hold_days: 14, default_validity_days: 365 });
  });

  it('flattens the category tree with path labels', () => {
    const out = flattenCategories([{ id: 1, name: 'Women', children: [{ id: 2, name: 'Abayas', children: [] }] }, { id: 0, name: 'bad' }]);
    expect(out).toEqual([{ id: 1, label: 'Women' }, { id: 2, label: 'Women › Abayas' }]);
  });

  it('reads server field errors', () => {
    const err = { error: { error: { details: { fields: { max_award_per_order: ['Must not exceed 10000.00'], 'targets.0.target_id': ['No vendor'] } } } } };
    expect(serverFieldErrors(err)).toEqual({ max_award_per_order: 'Must not exceed 10000.00', targets: 'No vendor' });
    expect(serverFieldErrors({})).toEqual({});
  });
});
