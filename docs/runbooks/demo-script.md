# Demo Runbook — M2 v3 Migration Rollout

**Target demo date:** Day 10 (May 16, 2026)
**Last updated:** Day 7 (May 13, 2026)
**Status:** Working draft — refine on Day 9 after end-to-end testing

This is the script Sodiq runs during the live demo, plus the troubleshooting
notes for "what to do if something hiccups on stage."

---

## Pre-flight (do this BEFORE the demo starts)

### 30 minutes before

1. **Verify v3 API is alive:**
   ```bash
   curl -s https://api-v3.3bayti.ae/v3/health | python3 -m json.tool
   ```
   Expected output: `{"status":"ok", "version":"..."}`. If 5xx or no response, see "Recovery: API is down" below.

2. **Verify staging.3bayti.ae is alive:**
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" https://staging.3bayti.ae/
   ```
   Expected: `200`.

3. **Verify the demo flow renders correctly:**
   Open each of these URLs in a clean private/incognito window:
   - https://staging.3bayti.ae/
   - https://staging.3bayti.ae/category
   - https://staging.3bayti.ae/category/abayas-1
   - https://staging.3bayti.ae/product/la23
   - https://staging.3bayti.ae/product/woven-waves
   Expected: all render in under 2 seconds, with real product data visible.

4. **Have these tabs already open in the browser used for the demo:**
   Reduces "loading time" perception during the demo.
   - The 5 URLs above
   - https://api-v3.3bayti.ae/v3/health (proof of API liveness)
   - The GitHub repo at the latest main commit (shows recent activity)
   - DevTools, Network tab cleared

### 5 minutes before

5. **Final sanity:**
   ```bash
   curl -s https://api-v3.3bayti.ae/v3/products?limit=1 | python3 -m json.tool
   ```
   Expected: one product with full nested vendor + price + image fields.

6. **Reload staging.3bayti.ae in the demo browser** to warm Cloudflare's edge cache.

7. **Close other apps making noise** (Slack, email notifications).

---

## The demo flow (suggested 15-minute script)

### Part 1: The big picture (2 min)

Open: [`docs/runbooks/architecture-diagram.md`](architecture-diagram.md) (Phase 7.D)

Talking points:
- "Before this rollout, every client surface — the web SEO site, the mobile app, the vendor portal — talked to a single legacy PHP backend with custom envelope shapes."
- "M2 introduces a v3 API at api-v3.3bayti.ae built on Slim 4 + Doctrine + Postgres 18, replacing the catalog reads."
- "The migration uses a strangler-fig pattern: traffic switches per-endpoint via a feature-flag table, so individual endpoints can be flipped or rolled back without redeploys."

### Part 2: Live v3 API tour (3 min)

In the API tab, run these curl commands (have them in a text file to paste):

```bash
# 1. Health endpoint — proves it's the new backend
curl -s https://api-v3.3bayti.ae/v3/health | python3 -m json.tool
```

```bash
# 2. Categories — real client data
curl -s https://api-v3.3bayti.ae/v3/categories | python3 -m json.tool | head -30
```

```bash
# 3. Products — real product, real vendor, real price
curl -s 'https://api-v3.3bayti.ae/v3/products?limit=2&sort=newest' \
  | python3 -m json.tool
```

```bash
# 4. Auth that ACTUALLY works against migrated user
curl -s -X POST https://api-v3.3bayti.ae/v3/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"surdbells@gmail.com","password":"REPLACE_BEFORE_DEMO"}' \
  | python3 -c "import json,sys; d=json.load(sys.stdin); \
    print('user_id:', d['user']['id']); \
    print('email:', d['user']['email']); \
    print('roles:', d['user']['roles']); \
    print('access_token: [...truncated...]')"
```

Talking point: "This is a real user from the legacy database. Their original bcrypt password hash works unchanged on v3 — no password reset campaign needed for any of the 9,330 migrated users."

### Part 3: Live web app on v3 (5 min)

Switch to staging.3bayti.ae.

1. **Home page:** point out the carousel hero + the product strips ("Featured" / "Best Sellers" / "New Arrivals").
   Talking point: "Three of these four strips are served by v3. The fourth — Designer Spotlight — remains on legacy v2 because v3 doesn't yet have the curated-vendors-with-nested-products endpoint shape. The strangler-fig pattern routes one endpoint to legacy while the rest go to v3, with no app-code awareness."

2. **Open DevTools → Network tab.** Reload. Filter for "api-v3".
   Talking point: "You can see the requests fanning out to api-v3.3bayti.ae for catalog reads. The featured-vendors call stays on legacy api.3bayti.ae/v2/."

3. **Click into a category** (e.g. "Abayas").
   Talking point: "Category detail still uses legacy v2 because v3's /categories/:slug endpoint is missing the embedded products array. Documented as a Day-7-followup; flips to v3 in M3."

4. **Click into a product** (e.g. "LA23").
   Talking point: "Product detail is fully on v3 now. The URL slug 'la23' is a v3-generated clean slug; the legacy database had this as 'la27-2637' with an arbitrary numeric suffix. M2's slug cleanup was part of the catalog flip."

### Part 4: Mobile app + portal (2 min)

Show the mobile app on a device or screen-mirror.

Talking point: "Mobile and the vendor portal are still on legacy v1 for this milestone. They've been brought into the monorepo (Day 6) with CI green, but the actual endpoint flip is M3 work. The reason is mostly scope: mobile has 37 files calling NetworkService with deeply-coupled response shapes; rewriting them safely needs more time than M2 allowed."

If asked "why didn't you do mobile and portal in M2?":
- M2 was about proving the v3 backend works end-to-end through the highest-traffic surface (web).
- Mobile changes need native (Capacitor) re-publishes to the App Store / Play Store — those are not 1-day actions.
- Portal is a vendor-only tool with low daily users; rushing it doesn't help the client.

### Part 5: What's next (2 min)

Open: [`docs/runbooks/m2-rollout-status.md`](m2-rollout-status.md) (Phase 7.E)

Walk through the done/deferred table. Be honest about what's deferred and why.

Closing line: "M2 ships the strangler-fig infrastructure and proves it works with real client data. M3 is incremental endpoint flips — each ~1 day of work — until 100% of traffic goes through v3."

---

## Recovery procedures (if something breaks during the demo)

### Recovery: staging.3bayti.ae returns 500 or doesn't load

1. **Don't troubleshoot live.** Pivot to the API tour (Part 2) which doesn't depend on the web app.
2. **In a separate tab:** check Cloudflare dash → Workers → `3bayti-web` → recent invocations.
3. **Fastest recovery:** redeploy current main via `git commit --allow-empty -m "redeploy" && git push`. CI deploys in ~3-4 minutes.
4. **If still broken after redeploy:** show the home page from this morning's screenshot (have one ready). Walk through the architecture diagram instead.

### Recovery: API is down (api-v3.3bayti.ae 5xx)

1. SSH into the droplet: `ssh root@142.93.172.195`
2. Check PHP-FPM: `systemctl status php82-fpm` (or whatever version)
3. Check nginx: `systemctl status nginx`
4. Restart both if needed: `systemctl restart php82-fpm nginx`
5. If DB is the issue: `systemctl status postgresql` and look at `/var/log/postgresql/`
6. **If recovery is taking > 2 minutes:** pivot to showing the architecture diagram + repo commit log. Don't drag dead air.

### Recovery: a specific product or category page 404s

1. **Most likely cause:** the slug changed between staging and prerender. Try a different slug from the sitemap.
2. **Backup product slugs (verified working at Day 7):** `la23`, `la24`, `woven-waves`, `gl-003`, `la27`
3. **Backup category slugs (legacy v2 shape, verified working):** `abayas-1`, `dresses-3`, `mukhawars-2`, `kaftans-4`

### Recovery: someone asks a question I can't answer

Honest deflection: "That's a really good question; I'd want to dig into the codebase before giving you a wrong answer. Let me get back to you with the right answer rather than guess on stage."

---

## Expected audience questions + prepared answers

### "Is the migration complete?"

No. M2 ships the infrastructure + the apps/web flip for catalog reads. Mobile, portal, cart/checkout, and admin endpoints are M3. About 60% of API surface is on v3; 40% remains on legacy v1 by design.

### "What about mobile?"

Mobile is on legacy v1 through M2. It's in the monorepo now (Day 6) with green CI, so M3 can start the actual endpoint flip. The reason it's not done in M2: mobile has 37 files calling NetworkService with deeply-coupled custom response shapes. Re-shaping them safely requires more time than M2 allowed.

### "How do you roll back if v3 breaks in production?"

The ENDPOINT_ROUTING table in `packages/api-client/src/feature-flags.ts` controls every endpoint's destination. Flip an endpoint's `target` from `'new'` to `'old'`, commit, push. CI deploys in 3-4 minutes. Zero downtime.

### "What's the cost story?"

Pre-migration: single DigitalOcean droplet ~$40/month, no edge caching, single point of failure.
Post-migration target (M3 complete): same droplet + Cloudflare Workers free tier for the web app. Web traffic served from edge cache; API traffic to the droplet drops by ~70%. Net cost reduction ~30% at current traffic.

### "Are migrated users able to log in?"

Yes for 9,294 of 9,330 users. 36 users had email conflicts in the legacy database (multiple accounts with the same email); they're in a `migration_email_conflicts` table with suffixed emails (`originalemail+legacy{id}@domain`). They need a one-click email reset to recover. Total impact: 0.4% of the user base, none of whom have placed orders in the last 6 months.

### "What about SEO?"

Server-side rendering preserves SEO for crawlable surfaces (home, category, product, sitemap). Slug changes for products (`la27-2637` → `la27`) issued 301 redirects in the prerender stage to preserve URL equity. Sitemap entries match what the SSR pipeline actually serves — verified at every CI run.

### "Can I see the code?"

Yes — repo is `github.com/surdbells/3bayti` (or wherever applicable). Point at:
- `packages/api-client/` — the routing layer
- `apps/api/` — the new v3 backend
- `apps/web/src/app/core/http/routed-http-client.ts` — the strangler-fig adapter
- `docs/runbooks/` — every day's completion documents

---

## Known issues the audience MAY notice

Be upfront about these. Pre-emptive honesty deflates "gotcha" questions.

| What they see | What it is | What to say |
|---|---|---|
| `/designer/laduna-abaya` 404 | Designer routes weren't built in apps/web | "Phase 2 work; designer profile pages are a separate scope" |
| HTML entities in vendor descriptions | Legacy data not yet decoded | "Description cleanup is queued for M3; descriptions aren't rendered on user-facing pages today" |
| Slow first request after 5+ min idle | Cloudflare Workers cold-start | "Edge cold-start; subsequent requests are sub-200ms" |
| Mobile app uses legacy URL | Mobile flip deferred to M3 | "Out of scope for M2; mobile migration is M3" |
| `ether-and-moon` slug | Was `ether-amp-moon` until Phase 7.B fix | If running pre-fix: "Migration bug, queued for fix"; post-fix: not visible |

---

## Post-demo checklist

After the demo ends:

1. **Capture the recording** if Sodiq filmed it.
2. **Save any audience questions** that weren't fully answered. Address them within 24 hours.
3. **Don't deploy on demo day after the demo.** Wait for the next morning.
4. **Note any unexpected user behaviour** for the M3 backlog.

---

## Appendix: useful URLs

| Surface | URL |
|---|---|
| v3 API health | https://api-v3.3bayti.ae/v3/health |
| v3 API products | https://api-v3.3bayti.ae/v3/products |
| Web staging | https://staging.3bayti.ae/ |
| Web sitemap | https://staging.3bayti.ae/sitemap.xml |
| Legacy API (still live) | https://api.3bayti.ae/ |
| Server | ssh root@142.93.172.195 |
| Repo | github.com/surdbells/3bayti |
