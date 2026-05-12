// This file can be replaced during build by using the `fileReplacements` array.
// `ng build` replaces `environment.ts` with `environment.prod.ts`.
// The list of file replacements can be found in `angular.json`.

export const environment = {
  production: false,

  /* Google Places API (New) configuration.
   *
   * The key below is for development. It MUST be restricted in
   * Google Cloud Console:
   *   - Application restrictions: HTTP referrers — limit to localhost
   *     (http://localhost, capacitor://localhost, https://localhost)
   *   - API restrictions: Places API (New) only
   *
   * Without these restrictions a leaked key can be abused to consume
   * paid quota. Even with restrictions, set a usage quota cap in the
   * Cloud Console as a hard cost ceiling.
   *
   * If the key is missing or empty, the <ax-place-autocomplete>
   * component degrades gracefully to a plain text field.
   */
  googlePlaces: {
    apiKey: 'AIzaSyAHERMyCn9KfrhZF5zpKynzLp0SjXpQpKU',
    /* ISO 3166-1 alpha-2 country code(s) to restrict autocomplete to. */
    regions: ['AE']
  },

  /* App Update / kill-switch configuration.
   *
   * configUrl: URL of a static JSON file hosted on the app server. When
   * set to a non-empty string, the app fetches it on launch + when
   * resumed from background, and uses its contents to decide whether to
   * force users to update.
   *
   * If empty (current default until URL is provided), the app skips the
   * remote check and falls through to a local store-version comparison
   * only — which is currently a no-op since no min-version threshold
   * is configured locally.
   *
   * Failing safe: if the JSON fetch fails, returns malformed data, or
   * the URL is empty, the app does NOT force an update. The kill-switch
   * activates updates only when both file is reachable AND it explicitly
   * sets require_app_update: true.
   *
   * Expected JSON shape:
   *   {
   *     "require_app_update": false,
   *     "min_required_version": "0.0.2",
   *     "force_update_message_en": "An important update is required...",
   *     "force_update_message_ar": "يلزم تثبيت تحديث مهم...",
   *     "force_update_threshold": "patch" | "minor" | "major"
   *   }
   */
  appUpdate: {
    configUrl: 'https://api.3bayti.ae/app_update.json' as string,
    /* iOS App Store country code for iTunes Lookup queries. UAE-focused. */
    iosCountry: 'ae'
  }
};

/*
 * For easier debugging in development mode, you can import the following file
 * to ignore zone related error stack frames such as `zone.run`, `zoneDelegate.invokeTask`.
 *
 * This import should be commented out in production mode because it will have a negative impact
 * on performance if an error is thrown.
 */
// import 'zone.js/plugins/zone-error';  // Included with Angular CLI.
