import { of, throwError } from 'rxjs';

import {
  CashbackService,
  formatCashbackValue,
  isCashbackGiftCard,
  parseCartCashback,
  parseOrderCashback,
  parseProductCashback,
} from './cashback.service';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

describe('cashback parsing', () => {
  describe('parseCartCashback', () => {
    it('parses an eligible estimate', () => {
      expect(parseCartCashback({
        estimated_amount: '12.50', currency: 'AED', eligible: true, reason: null,
        hold_days: 14, uses_cashback_credit: false, wallet_has_cashback_credit: true,
      })).toEqual({
        estimated_amount: '12.50', currency: 'AED', eligible: true, reason: null,
        hold_days: 14, uses_cashback_credit: false, wallet_has_cashback_credit: true,
      });
    });

    it('keeps the reason and nulls hold_days for an ineligible estimate', () => {
      const r = parseCartCashback({
        estimated_amount: '0.00', currency: 'AED', eligible: false, reason: 'paid_with_cashback',
        hold_days: null, uses_cashback_credit: true, wallet_has_cashback_credit: true,
      });
      expect(r?.eligible).toBeFalse();
      expect(r?.reason).toBe('paid_with_cashback');
      expect(r?.hold_days).toBeNull();
      expect(r?.uses_cashback_credit).toBeTrue();
    });

    it('never reports a zero amount as eligible', () => {
      expect(parseCartCashback({ estimated_amount: '0.00', eligible: true })?.eligible).toBeFalse();
    });

    it('returns null for unusable data', () => {
      expect(parseCartCashback(null)).toBeNull();
      expect(parseCartCashback({})).toBeNull();
      expect(parseCartCashback({ estimated_amount: 'abc' })).toBeNull();
    });
  });

  describe('parseProductCashback', () => {
    it('parses a percentage badge with a cap', () => {
      expect(parseProductCashback({ type: 'percentage', value: '5.00', max: '50.00', channel: 'all' }))
        .toEqual({ type: 'percentage', value: '5.00', max: '50.00', channel: 'all' });
    });

    it('treats a missing or zero cap as uncapped', () => {
      expect(parseProductCashback({ type: 'fixed', value: '10.00', max: null, channel: 'mobile' })?.max).toBeNull();
      expect(parseProductCashback({ type: 'fixed', value: '10.00', max: '0.00' })?.max).toBeNull();
    });

    it('hides web-only rules and malformed badges', () => {
      expect(parseProductCashback({ type: 'percentage', value: '5.00', max: null, channel: 'web' })).toBeNull();
      expect(parseProductCashback({ type: 'other', value: '5.00' })).toBeNull();
      expect(parseProductCashback({ type: 'fixed', value: '0' })).toBeNull();
      expect(parseProductCashback(null)).toBeNull();
    });
  });

  describe('parseOrderCashback', () => {
    it('parses a pending award', () => {
      expect(parseOrderCashback({
        status: 'pending', amount: '7.25', currency: 'AED', release_at: '2026-12-01T10:00:00+04:00', gift_card_id: null,
      })).toEqual({
        status: 'pending', amount: '7.25', currency: 'AED', release_at: '2026-12-01T10:00:00+04:00', gift_card_id: null,
      });
    });

    it('parses an issued award with its gift card id', () => {
      const r = parseOrderCashback({ status: 'issued', amount: '7.25', currency: 'AED', release_at: null, gift_card_id: 88 });
      expect(r?.gift_card_id).toBe(88);
      expect(r?.release_at).toBeNull();
    });

    it('returns null for no award or an unknown status', () => {
      expect(parseOrderCashback(null)).toBeNull();
      expect(parseOrderCashback({ status: 'weird', amount: '1.00' })).toBeNull();
    });
  });

  it('isCashbackGiftCard reads source', () => {
    expect(isCashbackGiftCard({ source: 'cashback' })).toBeTrue();
    expect(isCashbackGiftCard({ source: 'purchase' })).toBeFalse();
    expect(isCashbackGiftCard({})).toBeFalse();
    expect(isCashbackGiftCard(null)).toBeFalse();
  });

  it('formatCashbackValue trims trailing zeros', () => {
    expect(formatCashbackValue('5.00')).toBe('5');
    expect(formatCashbackValue('2.50')).toBe('2.5');
    expect(formatCashbackValue('12.75')).toBe('12.75');
  });
});

describe('CashbackService.estimateForCart', () => {
  let adapter: jasmine.SpyObj<MobileNetworkAdapter>;
  let service: CashbackService;

  beforeEach(() => {
    adapter = jasmine.createSpyObj<MobileNetworkAdapter>('MobileNetworkAdapter', ['get_v3']);
    service = new CashbackService(adapter);
  });

  it('sends the mobile channel and the selected payment, and parses the reply', async () => {
    adapter.get_v3.and.returnValue(of({
      response_code: 200, status: 'success',
      data: { estimated_amount: '3.00', currency: 'AED', eligible: true, reason: null, hold_days: 7,
        uses_cashback_credit: false, wallet_has_cashback_credit: false },
    }));
    const r = await service.estimateForCart('tok', { promoCode: ' SAVE10 ', useGiftWallet: true });
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /cart/cashback', {
      authToken: 'tok',
      queryParams: { channel: 'mobile', promo_code: 'SAVE10', use_gift_wallet: 'true' },
    });
    expect(r?.estimated_amount).toBe('3.00');
  });

  it('prefers a typed gift card code over the wallet', async () => {
    adapter.get_v3.and.returnValue(of({ response_code: 200, status: 'success', data: { estimated_amount: '0.00' } }));
    await service.estimateForCart('tok', { giftCardCode: 'ABCD-EFGH', useGiftWallet: true });
    expect(adapter.get_v3.calls.mostRecent().args[1]?.queryParams).toEqual({ channel: 'mobile', gift_card_code: 'ABCD-EFGH' });
  });

  it('resolves null on an error envelope, a transport error, or no token', async () => {
    adapter.get_v3.and.returnValue(of({ response_code: 401, status: 'error', data: null }));
    expect(await service.estimateForCart('tok')).toBeNull();
    adapter.get_v3.and.returnValue(throwError(() => new Error('offline')));
    expect(await service.estimateForCart('tok')).toBeNull();
    adapter.get_v3.calls.reset();
    expect(await service.estimateForCart('')).toBeNull();
    expect(adapter.get_v3).not.toHaveBeenCalled();
  });
});
