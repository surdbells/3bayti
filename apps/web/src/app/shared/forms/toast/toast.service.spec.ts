import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ToastService } from './toast.service';

describe('ToastService', () => {
  let service: ToastService;

  beforeEach(() => {
    service = new ToastService();
  });

  afterEach(() => {
    service.clearAll();
    vi.useRealTimers();
  });

  it('starts with an empty stack', () => {
    expect(service.toasts()).toEqual([]);
    expect(service.hasToasts()).toBe(false);
  });

  it('show() returns a unique id and appends the toast', () => {
    const id1 = service.show({ message: 'first' });
    const id2 = service.show({ message: 'second' });

    expect(id1).not.toBe(id2);
    expect(service.toasts()).toHaveLength(2);
    expect(service.toasts()[0].message).toBe('first');
    expect(service.toasts()[1].message).toBe('second');
  });

  it('defaults kind to info and durationMs to 5000', () => {
    service.show({ message: 'hi' });
    expect(service.toasts()[0].kind).toBe('info');
    expect(service.toasts()[0].durationMs).toBe(5000);
  });

  it('convenience methods set the kind', () => {
    service.success('ok');
    service.error('bad');
    service.warning('hmm');
    service.info('fyi');

    const kinds = service.toasts().map(t => t.kind);
    expect(kinds).toEqual(['success', 'error', 'warning', 'info']);
  });

  it('auto-dismisses after durationMs', () => {
    vi.useFakeTimers();
    service.show({ message: 'transient', durationMs: 100 });
    expect(service.toasts()).toHaveLength(1);

    vi.advanceTimersByTime(99);
    expect(service.toasts()).toHaveLength(1);

    vi.advanceTimersByTime(2);
    expect(service.toasts()).toHaveLength(0);
  });

  it('does NOT auto-dismiss when durationMs is 0 (sticky)', () => {
    vi.useFakeTimers();
    service.show({ message: 'sticky', durationMs: 0 });

    vi.advanceTimersByTime(60_000);
    expect(service.toasts()).toHaveLength(1);
  });

  it('dismiss(id) removes the toast immediately', () => {
    const id = service.show({ message: 'now-gone' });
    service.dismiss(id);
    expect(service.toasts()).toEqual([]);
  });

  it('dismiss(id) cancels the auto-dismiss timer (no double-dismiss)', () => {
    vi.useFakeTimers();
    const id = service.show({ message: 'gone-then-timer-tries', durationMs: 100 });
    service.dismiss(id);
    expect(service.toasts()).toHaveLength(0);

    /* Push a second toast. Advancing past the first toast's timer
       shouldn't disturb the second. */
    service.show({ message: 'survives', durationMs: 0 });
    vi.advanceTimersByTime(200);
    expect(service.toasts()).toHaveLength(1);
    expect(service.toasts()[0].message).toBe('survives');
  });

  it('dismiss(unknownId) is a no-op', () => {
    service.show({ message: 'a' });
    service.dismiss('nonexistent');
    expect(service.toasts()).toHaveLength(1);
  });

  it('clearAll() removes everything and cancels timers', () => {
    vi.useFakeTimers();
    service.show({ message: 'a' });
    service.show({ message: 'b' });
    service.show({ message: 'c' });

    service.clearAll();
    expect(service.toasts()).toEqual([]);

    /* Even if the timers were going to fire, they shouldn't reach into
       a stale list. Verify by advancing past the durations. */
    vi.advanceTimersByTime(10_000);
    expect(service.toasts()).toEqual([]);
  });

  it('passes params through to the toast object', () => {
    service.show({ message: 'auth.x.errors.timeout', params: { seconds: 30 } });
    expect(service.toasts()[0].params).toEqual({ seconds: 30 });
  });
});
