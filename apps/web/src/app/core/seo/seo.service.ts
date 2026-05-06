import { Injectable, inject, DOCUMENT } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';
import { environment } from '../../../environments/environment';

/**
 * Centralized SEO + social-share metadata management.
 *
 * Pages call `seo.set({...})` once in their constructor (or in a route
 * resolver) to declare their meta block. Handles:
 *   - <title>
 *   - <meta name="description">
 *   - <link rel="canonical">
 *   - Open Graph (og:title, og:description, og:type, og:url, og:image)
 *   - Twitter Cards (twitter:card, twitter:title, twitter:description, twitter:image)
 *   - JSON-LD structured data (injected as <script type="application/ld+json">)
 *
 * SSR-safe: uses Angular's Meta + Title services + DOCUMENT injection
 * (no direct `document` access). Result: metadata appears in the HTML
 * the server sends to crawlers.
 */
@Injectable({ providedIn: 'root' })
export class SeoService {
  private readonly meta = inject(Meta);
  private readonly title = inject(Title);
  private readonly doc = inject(DOCUMENT);

  /** Site default — override per page via `set()`. */
  private readonly siteName = '3bayti';
  /** Build-time canonical URL — set via SITE_URL env var. */
  private readonly siteUrl = environment.SITE_URL;
  /** Default OG image. Lives at the site root. */
  private readonly defaultImage = `${environment.SITE_URL}/og-default.jpg`;

  /**
   * Set all SEO + social tags for a page.
   *
   * Idempotent — calling repeatedly with different values updates the
   * tags in place (uses Meta service's `updateTag`, not `addTag`).
   */
  set(opts: SeoOptions): void {
    const fullTitle = opts.titleSuffix === false
      ? opts.title
      : `${opts.title} | ${this.siteName}`;
    const description = opts.description;
    const url = opts.url ?? this.siteUrl;
    const image = opts.image ?? this.defaultImage;
    const type = opts.type ?? 'website';

    /* Title — both <title> AND og:title for share previews */
    this.title.setTitle(fullTitle);

    /* Description */
    this.meta.updateTag({ name: 'description', content: description });

    /* Canonical link — important for SEO to avoid duplicate-content
       penalties when the same page is reachable at multiple URLs. */
    this.setCanonical(url);

    /* Open Graph — Facebook, LinkedIn, WhatsApp, etc. */
    this.meta.updateTag({ property: 'og:title', content: opts.title });
    this.meta.updateTag({ property: 'og:description', content: description });
    this.meta.updateTag({ property: 'og:type', content: type });
    this.meta.updateTag({ property: 'og:url', content: url });
    this.meta.updateTag({ property: 'og:image', content: image });
    this.meta.updateTag({ property: 'og:site_name', content: this.siteName });
    this.meta.updateTag({ property: 'og:locale', content: opts.locale ?? 'en_AE' });

    /* Twitter Cards */
    this.meta.updateTag({ name: 'twitter:card', content: 'summary_large_image' });
    this.meta.updateTag({ name: 'twitter:title', content: opts.title });
    this.meta.updateTag({ name: 'twitter:description', content: description });
    this.meta.updateTag({ name: 'twitter:image', content: image });

    /* Robots — controls indexability per page. Default = index, follow.
       Use noindex for legal/internal pages. */
    if (opts.robots) {
      this.meta.updateTag({ name: 'robots', content: opts.robots });
    } else {
      this.meta.updateTag({ name: 'robots', content: 'index,follow' });
    }
  }

  /**
   * Inject a JSON-LD structured data block. Multiple calls append; pages
   * can declare Organization + Product + BreadcrumbList all on the same
   * page.
   *
   * Pass an object — it will be JSON.stringify'd into a <script> tag.
   * No need to manually include the @context (we add it automatically
   * if missing).
   */
  setStructuredData(data: object | object[]): void {
    /* Wrap in array for uniform handling */
    const items = Array.isArray(data) ? data : [data];

    /* Remove any prior JSON-LD blocks we previously injected. We mark
       them with data-seo-jsonld so we don't accidentally remove blocks
       set by other code (e.g., third-party tags). */
    const existing = this.doc.head.querySelectorAll('script[type="application/ld+json"][data-seo-jsonld]');
    existing.forEach((el) => el.remove());

    for (const item of items) {
      const script = this.doc.createElement('script');
      script.type = 'application/ld+json';
      script.setAttribute('data-seo-jsonld', '');
      /* Auto-inject @context if missing — convenience so callers can
         pass schema.org snippets without boilerplate. */
      const payload = '@context' in item
        ? item
        : { '@context': 'https://schema.org', ...item };
      script.textContent = JSON.stringify(payload);
      this.doc.head.appendChild(script);
    }
  }

  /* ----- Canonical link helper ------------------------------------------- */

  private setCanonical(url: string): void {
    /* The canonical link element either exists (set in index.html as a
       fallback) or doesn't. We update or create as needed. */
    let link = this.doc.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
    if (!link) {
      link = this.doc.createElement('link');
      link.rel = 'canonical';
      this.doc.head.appendChild(link);
    }
    link.href = url;
  }
}

/* ----- Types -------------------------------------------------------------- */

export interface SeoOptions {
  /** Page title — appears in <title> + og:title + twitter:title.
   *  Will be auto-suffixed with " | 3bayti" unless `titleSuffix: false`. */
  title: string;
  /** ~155 char description for SERP snippet, og:description, twitter:description. */
  description: string;
  /** Full canonical URL of this page. Defaults to site root. */
  url?: string;
  /** Hero image URL (≥1200×630 for og:image best results). Defaults to site default. */
  image?: string;
  /** OG type. 'website' (default), 'product', 'article', 'profile', etc. */
  type?: 'website' | 'product' | 'article' | 'profile';
  /** Locale tag for og:locale. Defaults to 'en_AE'. */
  locale?: string;
  /** Robots meta. Default 'index,follow'. Use 'noindex' for non-indexable pages. */
  robots?: string;
  /** Set to false to disable the " | 3bayti" suffix in <title>. */
  titleSuffix?: boolean;
}
