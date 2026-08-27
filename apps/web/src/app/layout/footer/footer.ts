import { Component, ChangeDetectionStrategy } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Site footer. Phase 1: minimal, copyright, basic links to anchor SEO.
 * Phase 2 will add full link columns, social, currency/lang toggle, etc.
 */
@Component({
  selector: 'app-footer',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './footer.html',
  styleUrl: './footer.scss',
})
export class FooterComponent {
  /** Copyright year, computed at request time so the SSR'd HTML always
   *  shows the current year without manual updates each January. */
  readonly currentYear = new Date().getFullYear();
}
