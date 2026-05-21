import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { PLATFORM_ID } from '@angular/core';
import { CurrencyService, SUPPORTED_CURRENCIES } from './currency.service';

const STORAGE_KEY = 'bayti_currency';

describe('CurrencyService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    localStorage.removeItem(STORAGE_KEY);
    vi.restoreAllMocks();
  });

  function setup(platformId: string = 'browser'): CurrencyService {
    TestBed.configureTestingModule({
      providers: [
        CurrencyService,
        { provide: PLATFORM_ID, useValue: platformId },
      ],
    });
    return TestBed.inject(CurrencyService);
  }

  it('defaults to AED when no stored preference', () => {
    const svc = setup();
    expect(svc.currency()).toBe('AED');
  });

  it('reads a stored preference from localStorage on init', () => {
    localStorage.setItem(STORAGE_KEY, 'USD');
    const svc = setup();
    expect(svc.currency()).toBe('USD');
  });

  it('ignores an unknown stored value', () => {
    localStorage.setItem(STORAGE_KEY, 'INVALID');
    const svc = setup();
    expect(svc.currency()).toBe('AED');
  });

  it('set() updates the signal and persists to localStorage', () => {
    const svc = setup();
    svc.set('EUR');
    expect(svc.currency()).toBe('EUR');
    expect(localStorage.getItem(STORAGE_KEY)).toBe('EUR');
  });

  it('set() ignores unknown codes', () => {
    const svc = setup();
    svc.set('ZZZ' as any);
    expect(svc.currency()).toBe('AED');
  });

  it('isConverted is false when AED', () => {
    const svc = setup();
    expect(svc.isConverted()).toBe(false);
    svc.set('GBP');
    expect(svc.isConverted()).toBe(true);
  });

  it('queryParam is empty string when AED, code when non-AED', () => {
    const svc = setup();
    expect(svc.queryParam()).toBe('');
    svc.set('SAR');
    expect(svc.queryParam()).toBe('SAR');
  });

  it('accepts every supported currency', () => {
    const svc = setup();
    for (const code of SUPPORTED_CURRENCIES) {
      svc.set(code);
      expect(svc.currency()).toBe(code);
    }
  });

  describe('SSR (server platform)', () => {
    it('always returns AED on the server regardless of localStorage', () => {
      localStorage.setItem(STORAGE_KEY, 'GBP');
      const svc = setup('server');
      expect(svc.currency()).toBe('AED');
    });

    it('set() does not throw on the server', () => {
      const svc = setup('server');
      expect(() => svc.set('USD')).not.toThrow();
    });
  });
});
