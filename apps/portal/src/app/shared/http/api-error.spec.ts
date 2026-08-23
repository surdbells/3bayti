import { apiErrorMessage } from './api-error';

/**
 * apiErrorMessage extracts a human-readable string from a failed HTTP call.
 * The key regression these tests guard: the v3 validation envelope nests field
 * errors under details.fields.<field>, and an earlier version stringified that
 * object into "[object Object]" in the toast.
 */
describe('apiErrorMessage', () => {
  it('extracts the field message from a v3 validation envelope (details.fields)', () => {
    const err = {
      status: 422,
      error: { error: { code: 'VALIDATION_FAILED', message: 'One or more fields failed validation.', details: { fields: { store_logo: ['Must be at most 500 characters.'] } } } },
    };
    expect(apiErrorMessage(err)).toBe('Must be at most 500 characters.');
  });

  it('never returns "[object Object]" for a nested-object details payload', () => {
    const err = { status: 422, error: { error: { details: { fields: { a: { nested: {} } } } } } };
    const msg = apiErrorMessage(err, 'fallback');
    expect(msg).not.toContain('[object Object]');
  });

  it('handles field errors placed directly on details', () => {
    const err = { status: 409, error: { error: { details: { email: ['already taken'] } } } };
    expect(apiErrorMessage(err)).toBe('already taken');
  });

  it('falls back to the top-level API message when there are no field details', () => {
    const err = { status: 409, error: { error: { code: 'CONFLICT_DUPLICATE', message: 'A measurement for size M already exists.' } } };
    expect(apiErrorMessage(err)).toBe('A measurement for size M already exists.');
  });

  it('uses a plain-string error body', () => {
    expect(apiErrorMessage({ status: 500, error: 'Server exploded' })).toBe('Server exploded');
  });

  it('reports a network problem for status 0', () => {
    expect(apiErrorMessage({ status: 0 })).toContain('Network');
  });

  it('returns the caller fallback when nothing usable is present', () => {
    expect(apiErrorMessage({ status: 500, error: {} }, 'Try later')).toBe('Try later');
  });
});
