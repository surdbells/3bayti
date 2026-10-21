import { storeFollowMessage } from './store-follow-messages';

/** Echoes the key, plus the interpolated store when one is passed. */
const i18n = {
  t: (key: string, params?: Record<string, unknown>) =>
    params && params['store'] != null ? `${key}|${params['store']}` : key,
};

describe('storeFollowMessage', () => {
  it('names the store on a successful follow / unfollow', () => {
    expect(storeFollowMessage(i18n, 'followed', 'Dar Abaya')).toBe('store_follow_success|Dar Abaya');
    expect(storeFollowMessage(i18n, 'unfollowed', 'Dar Abaya')).toBe('store_unfollow_success|Dar Abaya');
  });

  it('trims the name and falls back to generic copy when it is unknown', () => {
    expect(storeFollowMessage(i18n, 'followed', '  Dar Abaya  ')).toBe('store_follow_success|Dar Abaya');
    expect(storeFollowMessage(i18n, 'followed', '')).toBe('store_follow_success_generic');
    expect(storeFollowMessage(i18n, 'followed', '   ')).toBe('store_follow_success_generic');
    expect(storeFollowMessage(i18n, 'unfollowed', null)).toBe('store_unfollow_success_generic');
    expect(storeFollowMessage(i18n, 'unfollowed')).toBe('store_unfollow_success_generic');
  });

  it('uses localized failure copy (never the raw server message)', () => {
    expect(storeFollowMessage(i18n, 'follow_failed', 'Dar Abaya')).toBe('store_follow_failed');
    expect(storeFollowMessage(i18n, 'unfollow_failed', 'Dar Abaya')).toBe('following_unfollow_failed');
  });

  it('never returns an empty message for any outcome', () => {
    for (const outcome of ['followed', 'unfollowed', 'follow_failed', 'unfollow_failed'] as const) {
      expect(storeFollowMessage(i18n, outcome, '').length).toBeGreaterThan(0);
    }
  });
});
