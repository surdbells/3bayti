import { Component, ChangeDetectionStrategy, inject } from '@angular/core';
import {
  ContainerComponent,
  HeadingComponent,
  TextComponent,
  StackComponent,
  ButtonComponent,
} from '../../shared/ui';
import { SeoService } from '../../core/seo/seo.service';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * 404 Not Found page, the catch-all `**` route (declared last in
 * app.routes.ts).
 *
 * This is a CLIENT route, not a top-level 404.html asset: Cloudflare
 * Pages serves the SPA shell for unknown paths, and Angular falls
 * through to this route when nothing else matches. Keeping it as a
 * route (rather than a 404.html) is what preserves Pages' built-in
 * single-page-application fallback.
 *
 * Marked noindex,follow so crawlers don't index unknown URLs but still
 * follow the links back into the catalogue.
 */
@Component({
  selector: 'app-not-found-page',
  standalone: true,
  imports: [
    ContainerComponent,
    HeadingComponent,
    TextComponent,
    StackComponent,
    ButtonComponent,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './not-found-page.html',
  styleUrl: './not-found-page.scss',
})
export class NotFoundPageComponent {
  private seo = inject(SeoService);

  constructor() {
    this.seo.set({
      title: 'Page not found',
      description:
        "The page you're looking for doesn't exist or has moved. Browse our collections of abayas, kaftans and modest wear.",
      robots: 'noindex,follow',
    });
  }
}
