export const environment = {
  production: true,

  /* Google Places API (New) configuration.
   * See environment.ts for restriction guidance — same key for now,
   * but for a real production deployment this should be a separate
   * restricted key bound to the production domain/bundle ID. */
  googlePlaces: {
    apiKey: 'AIzaSyAHERMyCn9KfrhZF5zpKynzLp0SjXpQpKU',
    regions: ['AE']
  },

  /* App Update / kill-switch — see environment.ts for shape + semantics. */
  appUpdate: {
    configUrl: 'https://api.3bayti.ae/app_update.json' as string,
    iosCountry: 'ae'
  }
};
