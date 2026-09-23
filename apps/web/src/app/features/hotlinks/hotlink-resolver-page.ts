import { Component, ChangeDetectionStrategy, OnInit, inject, signal } from '@angular/core';
import { NgIf } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { HotlinkService } from '../../core/hotlinks/hotlink.service';

/**
 * /s/:code — resolves a shared hotlink and forwards to the target page.
 *
 * Calls GET /hotlinks/:code (which records the click), then replaces the URL
 * with /stores/:slug or /styles/:slug. Shows a brief resolving state, and a
 * "link not found" message that offers the home page on a bad/expired code.
 */
@Component({
  selector: 'app-hotlink-resolver-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgIf, RouterLink, TranslatePipe],
  template: `
    <section class="hotlink-resolver">
      <div class="hotlink-resolver__inner">
        <p *ngIf="!failed()" class="hotlink-resolver__loading" aria-live="polite">
          {{ 'hotlink.resolving' | translate }}
        </p>
        <div *ngIf="failed()" class="hotlink-resolver__failed" data-testid="hotlink-failed">
          <p>{{ 'hotlink.notFound' | translate }}</p>
          <a routerLink="/" class="hotlink-resolver__home">{{ 'hotlink.goHome' | translate }}</a>
        </div>
      </div>
    </section>
  `,
  styles: [`
    .hotlink-resolver { min-height: 60vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .hotlink-resolver__inner { text-align: center; color: var(--color-text-muted, #6b6056); }
    .hotlink-resolver__home { color: var(--color-brand-700, #5a3a2c); font-weight: 600; text-decoration: none; }
    .hotlink-resolver__home:hover { text-decoration: underline; }
  `],
})
export class HotlinkResolverPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly hotlinks = inject(HotlinkService);

  protected readonly failed = signal<boolean>(false);

  async ngOnInit(): Promise<void> {
    const code = this.route.snapshot.paramMap.get('code') ?? '';
    if (code.trim() === '') {
      this.failed.set(true);
      return;
    }
    const target = await this.hotlinks.resolve(code);
    if (target === null) {
      this.failed.set(true);
      return;
    }
    const path = target.target_type === 'style'
      ? `/styles/${target.target_slug}`
      : `/stores/${target.target_slug}`;
    await this.router.navigateByUrl(path, { replaceUrl: true });
  }
}
