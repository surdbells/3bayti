import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadPasswordScorer, _resetPasswordScorerForTest } from './password-strength';

describe('loadPasswordScorer', () => {
  beforeEach(() => {
    _resetPasswordScorerForTest();
  });

  afterEach(() => {
    _resetPasswordScorerForTest();
  });

  it('returns a function that scores a password (0-4)', async () => {
    const scorer = await loadPasswordScorer();
    expect(scorer).not.toBeNull();
    if (scorer === null) return;

    const weak = scorer('password');
    expect(weak.score).toBeGreaterThanOrEqual(0);
    expect(weak.score).toBeLessThanOrEqual(4);
  });

  it('rates short obvious passwords as weak (score 0-1)', async () => {
    const scorer = await loadPasswordScorer();
    if (scorer === null) return;

    expect(scorer('123').score).toBeLessThanOrEqual(1);
    expect(scorer('password').score).toBeLessThanOrEqual(1);
    expect(scorer('qwerty').score).toBeLessThanOrEqual(1);
  });

  it('rates long mixed passwords as strong (score 3-4)', async () => {
    const scorer = await loadPasswordScorer();
    if (scorer === null) return;

    /* Length and entropy together, zxcvbn cares about both. */
    expect(scorer('correct-horse-battery-staple').score).toBeGreaterThanOrEqual(3);
    expect(scorer('xK9$mPq#L7nT@2vR8wYf').score).toBeGreaterThanOrEqual(3);
  });

  it('caches the loaded scorer (second call is synchronous-ish)', async () => {
    const first = await loadPasswordScorer();
    const second = await loadPasswordScorer();
    /* Same function reference means the module-level cache works. */
    expect(first).toBe(second);
  });

  it('deduplicates concurrent loads — concurrent callers share one promise', async () => {
    _resetPasswordScorerForTest();
    const [a, b, c] = await Promise.all([
      loadPasswordScorer(),
      loadPasswordScorer(),
      loadPasswordScorer(),
    ]);
    expect(a).toBe(b);
    expect(b).toBe(c);
  });
});
