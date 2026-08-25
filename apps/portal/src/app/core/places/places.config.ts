/**
 * Google Places (New) configuration for the portal.
 *
 * The portal has no Angular `environments/` setup (config is inlined), so the
 * key + region bias live here. Mirrors apps/web's environment.googlePlaces.
 *
 * SECURITY: this is currently the same key the web/mobile apps ship. For the
 * portal it SHOULD be replaced with an HTTP-referrer-restricted web key
 * (restricted to app.3bayti.ae + the Places API). Leaving apiKey empty makes
 * PlacesService.isAvailable false and every call degrade to a plain text input.
 */
export const GOOGLE_PLACES = {
  apiKey: 'AIzaSyAHERMyCn9KfrhZF5zpKynzLp0SjXpQpKU',
  /** Region bias — UAE, per current product scope. */
  regions: ['AE'],
};
