import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslatePipe } from '@ngx-translate/core';

import { SeoService } from '../../core/seo/seo.service';
import { breadcrumbSchema } from '../../core/seo/schema.helpers';
import { environment } from '../../../environments/environment';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
} from '../../shared/ui';
import { SkeletonShimmerComponent } from '../../shared/ui/skeleton-shimmer';
import { CollectionCardComponent } from './collection-card';
import { CollectionsService } from './collections.service';

/**
 * Collections index, `/collection`.
 *
 * Every shoppable admin-curated collection (GET /collections, API display
 * order, empty ones dropped) as the same image-backed CollectionCard used
 * by the home-page "Shop by collection" row, laid out in a responsive grid.
 * Mirrors the `/category` index (header + grid, client-side fetch).
 */
@Component({
  selector: 'app-collections',
  standalone: true,
  imports: [
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    SkeletonShimmerComponent,
    CollectionCardComponent,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './collections.html',
  styleUrl: './collections.scss',
})
export class CollectionsComponent {
  private readonly collectionsService = inject(CollectionsService);
  private readonly seo = inject(SeoService);

  /** null while loading; Collection[] once loaded (errors → []). */
  readonly collections = toSignal(this.collectionsService.list$(), { initialValue: null });

  /** True until the first fetch resolves. */
  readonly loading = computed(() => this.collections() === null);

  /** Skeleton placeholders shown while loading. */
  readonly skeletons = [0, 1, 2, 3, 4, 5, 6, 7];

  constructor() {
    const siteUrl = environment.SITE_URL;

    /* SEO: indexable collections index page. */
    this.seo.set({
      title: 'Shop by Collection',
      description:
        'Explore curated collections of abayas, kaftans and modest wear, ' +
        'hand-picked edits from independent UAE designers on 3bayti.',
      url: `${siteUrl}/collection`,
      type: 'website',
    });

    this.seo.setStructuredData([
      breadcrumbSchema([
        { name: 'Home', url: `${siteUrl}/` },
        { name: 'Collections', url: `${siteUrl}/collection` },
      ]),
    ]);
  }
}
