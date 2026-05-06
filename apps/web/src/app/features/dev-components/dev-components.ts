import { Component, ChangeDetectionStrategy, inject } from '@angular/core';
import {
  ButtonComponent,
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import { SeoService } from '../../core/seo/seo.service';
import { environment } from '../../../environments/environment';
import { ProductCardComponent } from '../catalog/product-card';
import type { Product } from '../catalog/product.model';

/**
 * /_dev/components — visual showcase of every shared UI primitive.
 *
 * This is our lightweight Storybook substitute. Native to the app, no
 * extra deps, fully SSR-rendered (so we also get to verify primitives
 * don't break under server rendering).
 *
 * The route is noindex'd so search engines never include it.
 *
 * To browse: visit /_dev/components in dev or staging. Production
 * users can technically reach it too — there's no harm in that, the
 * page is purely informational. We could route-guard it if it ever
 * felt necessary.
 */
@Component({
  selector: 'app-dev-components',
  standalone: true,
  imports: [
    ButtonComponent,
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    ProductCardComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './dev-components.html',
  styleUrl: './dev-components.scss',
})
export class DevComponentsComponent {
  private seo = inject(SeoService);

  constructor() {
    this.seo.set({
      title: 'Component preview',
      description: 'Internal UI primitive showcase.',
      url: `${environment.SITE_URL}/_dev/components`,
      robots: 'noindex,nofollow',
    });
  }

  /** Variants matrix used by the template — keeps logic out of HTML. */
  readonly buttonVariants = ['primary', 'secondary', 'ghost'] as const;
  readonly buttonSizes = ['sm', 'md', 'lg'] as const;
  readonly headingSizes = ['display', 'xl', 'lg', 'md', 'sm'] as const;
  readonly textSizes = ['xs', 'sm', 'base', 'lg'] as const;
  readonly textTones = ['default', 'secondary', 'tertiary'] as const;
  readonly containerSizes = ['narrow', 'default', 'wide', 'full'] as const;
  readonly stackGaps = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'] as const;

  /**
   * Mock products covering every ProductCard branch so the showcase
   * exercises all visual variants in one render. Designed to verify:
   *
   *   1. With image + vendor + rating + sale price (the rich case)
   *   2. With image but no rating yet (new product, no reviews)
   *   3. Out-of-stock (overlay treatment)
   *   4. Letter-fallback (no primary_image) — most common with broken
   *      backend image URLs in current data
   *   5. Bestseller pill, no sale
   *   6. Missing slug (renders as div, not anchor) — defensive case
   *
   * Once the live API is wired, real data will replace these. The mocks
   * stay in /_dev/components for ongoing visual regression checks.
   */
  readonly mockProducts: Product[] = [
    {
      id: 1,
      slug: 'embroidered-silk-kaftan-1',
      name: 'Embroidered Silk Kaftan with Metallic Thread',
      sku: 'GL-K-01',
      price: { amount: 1850, currency: 'AED' },
      sale_price: { amount: 1295, currency: 'AED' },
      primary_image: {
        url: 'https://images.unsplash.com/photo-1583846783214-7229a91b20ed?w=600',
        alt: 'Cream silk kaftan with gold embroidery',
        width: 600,
        height: 800,
      },
      category_slug: 'kaftans-3',
      vendor: { slug: 'graceloom-7', name: 'Graceloom' },
      rating: 4.7,
      review_count: 23,
      in_stock: true,
      is_new: false,
      is_bestseller: true,
    },
    {
      id: 2,
      slug: 'modern-abaya-2',
      name: 'Modern Open-Front Abaya',
      sku: 'BL-A-12',
      price: { amount: 920, currency: 'AED' },
      sale_price: null,
      primary_image: {
        url: 'https://images.unsplash.com/photo-1539109136881-3be0616acf4b?w=600',
        alt: 'Black abaya on hanger',
      },
      category_slug: 'abayas-1',
      vendor: { slug: 'noor-couture-15', name: 'Noor Couture' },
      rating: null,
      review_count: 0,
      in_stock: true,
      is_new: true,
      is_bestseller: false,
    },
    {
      id: 3,
      slug: 'pearl-clutch-3',
      name: 'Pearl-Beaded Evening Clutch',
      price: { amount: 480, currency: 'AED' },
      primary_image: {
        url: 'https://images.unsplash.com/photo-1591561954557-26941169b49e?w=600',
        alt: 'Pearl evening clutch on velvet',
      },
      category_slug: 'bags-4',
      vendor: { slug: 'maison-eden-22', name: 'Maison Eden' },
      rating: 4.2,
      review_count: 8,
      in_stock: false,  // Out of stock — overlay treatment
      is_new: false,
      is_bestseller: false,
    },
    {
      id: 4,
      slug: 'velvet-mukhawar-4',
      name: 'Velvet Mukhawar with Hand-Stitched Trim',
      price: { amount: 2400, currency: 'AED' },
      primary_image: null,  // Triggers letter-fallback
      category_slug: 'mukhawars-2',
      vendor: { slug: 'royal-thread-44', name: 'Royal Thread' },
      rating: 4.9,
      review_count: 47,
      in_stock: true,
      is_new: false,
      is_bestseller: true,
    },
    {
      id: 5,
      slug: '',  // Empty slug → card renders as <div>, not <a>
      name: 'Product Without Slug (defensive case)',
      price: { amount: 350, currency: 'AED' },
      primary_image: null,
      vendor: { slug: '', name: '' },
      rating: null,
      review_count: 0,
      in_stock: true,
    },
    {
      id: 6,
      slug: 'sale-pyjama-set-6',
      name: 'Cotton Pyjama Set',
      price: { amount: 580, currency: 'AED' },
      sale_price: { amount: 290, currency: 'AED' },
      primary_image: {
        url: 'https://images.unsplash.com/photo-1620799140408-edc6dcb6d633?w=600',
        alt: 'Folded pyjama set in soft pink',
      },
      category_slug: 'pyjamas-8',
      vendor: { slug: 'cotton-house-3', name: 'Cotton House' },
      rating: 4.4,
      review_count: 156,
      in_stock: true,
      is_new: false,
      is_bestseller: false,
    },
  ];
}
