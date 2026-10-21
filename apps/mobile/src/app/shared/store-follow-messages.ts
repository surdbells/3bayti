import type { I18nService } from '../i18n.service';

/** Outcome of a follow / unfollow write, for picking the toast copy. */
export type StoreFollowOutcome = 'followed' | 'unfollowed' | 'follow_failed' | 'unfollow_failed';

/**
 * Toast copy for following / unfollowing a store.
 *
 * The v3 follow endpoints carry no human message (POST /me/following/{id}
 * returns the vendor, DELETE is a bare 204), and the network adapter's
 * success envelope always has `message: ''`. Toasting `response.message`
 * therefore rendered a green success icon with no text. Callers use this
 * instead: it always returns non-empty, localized copy, naming the store
 * when it is known ("You're now following Dar Al Abaya").
 */
export function storeFollowMessage(
  i18n: Pick<I18nService, 't'>,
  outcome: StoreFollowOutcome,
  storeName?: string | null,
): string {
  const store = (storeName ?? '').trim();
  switch (outcome) {
    case 'followed':
      return store
        ? i18n.t('store_follow_success', { store })
        : i18n.t('store_follow_success_generic');
    case 'unfollowed':
      return store
        ? i18n.t('store_unfollow_success', { store })
        : i18n.t('store_unfollow_success_generic');
    case 'follow_failed':
      return i18n.t('store_follow_failed');
    case 'unfollow_failed':
      return i18n.t('following_unfollow_failed');
  }
}
