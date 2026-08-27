import { parseSocialAuthError, socialAuthErrorMessage } from './social-auth-error';

/** Identity translate stub, returns the key so assertions can match on it. */
const t = (k: string) => k;

describe('parseSocialAuthError', () => {
  it('flags user cancellation from a message', () => {
    expect(parseSocialAuthError({ message: 'The user canceled the sign-in flow.' }).cancelled).toBe(true);
  });

  it('flags Apple (1001) and Google (12501) cancel codes', () => {
    expect(parseSocialAuthError({ code: '1001', message: 'canceled' }).cancelled).toBe(true);
    expect(parseSocialAuthError({ code: '12501' }).cancelled).toBe(true);
  });

  it('classifies Google Play Services DEVELOPER_ERROR (10) as a config problem', () => {
    // The signing SHA not being registered in Firebase, the most likely
    // "fails on some devices" cause.
    expect(parseSocialAuthError({ code: '10', message: 'DEVELOPER_ERROR' }).category).toBe('config');
    expect(parseSocialAuthError({ message: 'com.google...ApiException: 10: ' }).category).toBe('config');
    expect(parseSocialAuthError({ code: 'auth/operation-not-allowed' }).category).toBe('config');
  });

  it('does not mistake 1001 / 12500 for the config code 10', () => {
    // 1001 is a cancel; 12500 is a generic sign-in failure, neither is config.
    expect(parseSocialAuthError({ code: '12500', message: 'sign in failed' }).category).not.toBe('config');
  });

  it('classifies network failures', () => {
    expect(parseSocialAuthError({ code: 'auth/network-request-failed' }).category).toBe('network');
    expect(parseSocialAuthError({ code: '7', message: 'NETWORK_ERROR' }).category).toBe('network');
  });

  it('classifies account-exists-with-different-credential', () => {
    expect(
      parseSocialAuthError({ code: 'auth/account-exists-with-different-credential' }).category,
    ).toBe('account-exists');
  });

  it('recovers a Firebase code embedded in the message when .code is absent', () => {
    expect(parseSocialAuthError({ message: 'Firebase: Error (auth/user-disabled).' }).code).toBe(
      'auth/user-disabled',
    );
  });

  it('leaves unknown errors as unknown but keeps the raw message', () => {
    const info = parseSocialAuthError({ message: 'something weird' });
    expect(info.category).toBe('unknown');
    expect(info.rawMessage).toBe('something weird');
  });
});

describe('socialAuthErrorMessage', () => {
  it('maps a config error to the config key and appends the code', () => {
    const info = parseSocialAuthError({ code: '10', message: 'DEVELOPER_ERROR' });
    expect(socialAuthErrorMessage(info, t)).toBe('social_err_config (10)');
  });

  it('falls back to the generic key with the code appended for unknown errors', () => {
    const info = parseSocialAuthError({ code: '12500', message: 'sign in failed' });
    expect(socialAuthErrorMessage(info, t)).toBe('text_social_signin_failed (12500)');
  });

  it('uses the plain generic key when no code could be extracted', () => {
    const info = parseSocialAuthError({ message: 'totally opaque' });
    expect(socialAuthErrorMessage(info, t)).toBe('text_social_signin_failed');
  });
});
