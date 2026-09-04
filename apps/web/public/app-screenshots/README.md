# App showcase screenshots

These images power the auto-advancing carousel in the home page **“Get the app”**
band (`app-showcase` component → `features/home/app-showcase.ts`).

The eight files here are **branded placeholders** (a cream→gold gradient with a
phone outline). **Replace each one with the real App Store / Play Store promo
tile**, keeping the exact filename below.

| Filename           | Screen shown                              |
|--------------------|-------------------------------------------|
| `home.png`         | Home — “Modest Fashion, Beautifully Curated” |
| `collections.png`  | Category grid — “Explore the Collections” |
| `product.png`      | Product detail — “Hand-Embellished, Premium Quality” |
| `style-hub.png`    | Style Hub — “Get Styled by the Community” |
| `filters.png`      | Filters sheet — “Your Size, Your Colour”  |
| `gift-cards.png`   | Gift cards — “Gifts for Eid, Birthdays & More” |
| `checkout.png`     | Cart — “Seamless Checkout”                |
| `signin.png`       | Auth — “Sign In Your Way”                 |

## Specs

- **Aspect ratio: 9:16 portrait** (the slide is `aspect-ratio: 9/16`, images are
  `object-fit: cover`, so anything close to 9:16 fits without cropping).
- **Recommended size:** 1080 × 1920 px.
- **Format:** `.png` (keep the name/extension). `.jpg`/`.webp` are fine too, but
  then update the `img` paths in `app-showcase.ts`.
- Keep each file well under ~1 MB for fast loading (compress/optimise before
  committing; the promo tiles compress well as JPEG/WebP if PNG is large).

To add or reorder tiles, edit the `shots` array (and the `home.getApp.shots.*`
i18n keys in `public/i18n/en.json` + `ar.json`).
