import { describe, it, expect } from 'vitest';

import {
  formatCashbackNumber,
  isCashbackGiftCard,
  parseCartCashback,
  parseOrderCashback,
  parseProductCashback,
  toMoneyString,
  webProductCashback,
} from './cashback.model';

describe('cashback.model', () => {
  describe('toMoneyString', () => {
    it('normalises strings and numbers to 2dp', () => {
      expect(toMoneyString('12.5')).toBe('12.50');
      expect(toMoneyString(3)).toBe('3.00');
      expect(toMoneyString('0.00')).toBe('0.00');
    });

    it('returns null for non-numeric input', () => {
      expect(toMoneyString('abc')).toBeNull();
      expect(toMoneyString('')).toBeNull();
      expect(toMoneyString(null)).toBeNull();
      expect(toMoneyString(undefined)).toBeNull();
      expect(toMoneyString({})).toBeNull();
    });
  });

  describe('formatCashbackNumber', () => {
    it('drops trailing zeros', () => {
      expect(formatCashbackNumber('5.00')).toBe('5');
      expect(formatCashbackNumber('2.50')).toBe('2.5');
      expect(formatCashbackNumber('10.25')).toBe('10.25');
    });
  });

  describe('parseProductCashback', () => {
    const raw = {
      type: 'percentage',
      value: '5.00',
      max: '50.00',
      min_eligible_amount: null,
      channel: 'all',
      first_order_only: false,
      allow_with_promo: true,
    };

    it('parses the PDP badge block', () => {
      expect(parseProductCashback(raw)).toEqual({
        type: 'percentage',
        value: '5.00',
        max: '50.00',
        min_eligible_amount: null,
        channel: 'all',
        first_order_only: false,
        allow_with_promo: true,
      });
    });

    it('parses a fixed reward with no cap', () => {
      const b = parseProductCashback({ type: 'fixed', value: 10, max: null, first_order_only: true });
      expect(b).not.toBeNull();
      expect(b!.type).toBe('fixed');
      expect(b!.value).toBe('10.00');
      expect(b!.max).toBeNull();
      expect(b!.channel).toBe('all');
      expect(b!.first_order_only).toBe(true);
    });

    it('returns null for absent, malformed or zero rewards', () => {
      expect(parseProductCashback(null)).toBeNull();
      expect(parseProductCashback(undefined)).toBeNull();
      expect(parseProductCashback([])).toBeNull();
      expect(parseProductCashback({ ...raw, type: 'bogus' })).toBeNull();
      expect(parseProductCashback({ ...raw, value: '0.00' })).toBeNull();
      expect(parseProductCashback({ ...raw, value: 'x' })).toBeNull();
    });

    it('treats a zero cap as uncapped and an unknown channel as all', () => {
      const b = parseProductCashback({ ...raw, max: '0.00', channel: 'kiosk' });
      expect(b!.max).toBeNull();
      expect(b!.channel).toBe('all');
    });

    it('webProductCashback hides mobile-only rules', () => {
      expect(webProductCashback({ ...raw, channel: 'mobile' })).toBeNull();
      expect(webProductCashback({ ...raw, channel: 'web' })!.channel).toBe('web');
      expect(webProductCashback({ ...raw, channel: 'ALL' })!.channel).toBe('all');
    });
  });

  describe('parseCartCashback', () => {
    it('parses an eligible estimate', () => {
      expect(
        parseCartCashback({
          estimated_amount: '12.50',
          currency: 'AED',
          eligible: true,
          reason: null,
          hold_days: 14,
          uses_cashback_credit: false,
          wallet_has_cashback_credit: true,
        }),
      ).toEqual({
        estimated_amount: '12.50',
        currency: 'AED',
        eligible: true,
        reason: null,
        hold_days: 14,
        uses_cashback_credit: false,
        wallet_has_cashback_credit: true,
      });
    });

    it('is not eligible when paying with cashback credit, even with an amount', () => {
      const p = parseCartCashback({
        estimated_amount: '0.00',
        currency: 'AED',
        eligible: false,
        reason: 'paid_with_cashback',
        hold_days: null,
        uses_cashback_credit: true,
        wallet_has_cashback_credit: true,
      });
      expect(p!.eligible).toBe(false);
      expect(p!.uses_cashback_credit).toBe(true);
      expect(p!.reason).toBe('paid_with_cashback');

      const forced = parseCartCashback({ estimated_amount: '9.00', eligible: true, uses_cashback_credit: true });
      expect(forced!.eligible).toBe(false);
      expect(forced!.reason).toBe('paid_with_cashback');
      expect(forced!.hold_days).toBeNull();
    });

    it('is not eligible for a zero estimate even if flagged eligible', () => {
      const p = parseCartCashback({ estimated_amount: '0.00', eligible: true, hold_days: 14 });
      expect(p!.eligible).toBe(false);
      expect(p!.hold_days).toBeNull();
      expect(p!.currency).toBe('AED');
    });

    it('keeps the ineligible reason', () => {
      const p = parseCartCashback({ estimated_amount: '0.00', eligible: false, reason: 'no_matching_rule' });
      expect(p!.reason).toBe('no_matching_rule');
      expect(p!.uses_cashback_credit).toBe(false);
      expect(p!.wallet_has_cashback_credit).toBe(false);
    });

    it('returns null for unusable payloads', () => {
      expect(parseCartCashback(undefined)).toBeNull();
      expect(parseCartCashback({})).toBeNull();
      expect(parseCartCashback('12.00')).toBeNull();
    });
  });

  describe('parseOrderCashback', () => {
    it('parses a pending award', () => {
      expect(
        parseOrderCashback({
          status: 'pending',
          amount: '25.00',
          currency: 'AED',
          release_at: '2026-12-01T10:00:00+00:00',
          gift_card_id: null,
        }),
      ).toEqual({
        status: 'pending',
        amount: '25.00',
        currency: 'AED',
        release_at: '2026-12-01T10:00:00+00:00',
        gift_card_id: null,
      });
    });

    it('parses an issued award with its gift card v3 id', () => {
      const c = parseOrderCashback({ status: 'issued', amount: 7, release_at: '2026-11-01T00:00:00Z', gift_card_id: 88 });
      expect(c!.status).toBe('issued');
      expect(c!.amount).toBe('7.00');
      expect(c!.gift_card_id).toBe(88);
      expect(c!.currency).toBe('AED');
    });

    it('drops invalid dates and card ids', () => {
      const c = parseOrderCashback({ status: 'pending', amount: '1.00', release_at: 'soon', gift_card_id: 'x' });
      expect(c!.release_at).toBeNull();
      expect(c!.gift_card_id).toBeNull();
    });

    it('returns null when there is no award or the status is unknown', () => {
      expect(parseOrderCashback(null)).toBeNull();
      expect(parseOrderCashback(undefined)).toBeNull();
      expect(parseOrderCashback({ status: 'weird', amount: '1.00' })).toBeNull();
      expect(parseOrderCashback({ status: 'issued' })).toBeNull();
    });
  });

  describe('isCashbackGiftCard', () => {
    it('is true only for source=cashback', () => {
      expect(isCashbackGiftCard({ source: 'cashback' })).toBe(true);
      expect(isCashbackGiftCard({ source: 'purchase' })).toBe(false);
      expect(isCashbackGiftCard({ source: 'admin' })).toBe(false);
      expect(isCashbackGiftCard({})).toBe(false);
      expect(isCashbackGiftCard(null)).toBe(false);
    });
  });
});
