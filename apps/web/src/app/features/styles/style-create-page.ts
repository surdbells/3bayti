import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { HttpErrorResponse } from '@angular/common/http';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { ToastService } from '../../shared/forms';
import { AuthService } from '../../core/auth/auth.service';
import { SearchService } from '../search/search.service';
import { StyleService } from './style.service';
import type { Product } from '../catalog/product.model';
import type { StyleProduct } from './style.model';

/** A product the user has added to the outfit-in-progress. */
interface PickedProduct {
  id: number;
  slug: string;
  name: string;
  image: string | null;
  price: string;
}

/** Hard cap on products per style, matches the v3 CreateStyleController. */
const MAX_PRODUCTS = 4;

/**
 * /styles/create, assemble a community style (outfit) and submit it.
 *
 * Ported from the mobile create flow (apps/mobile/.../styles/create).
 * The v3 CreateStyleController reads only `name` + `products` (up to 4
 * v3 product ids), so this page collects exactly those, mobile's
 * category / isPrivate fields are dropped because the API ignores them.
 *
 * Auth: guests are shown a sign-in prompt instead of the form (POST
 * /me/styles is Bearer-bound; the auth interceptor attaches the token).
 *
 * Product picker: rather than the mobile vendor-drill-down, the web port
 * uses the existing product search (GET /products?q=…), type a query,
 * tap a result to add it to the outfit tray (max 4, no duplicates). Each
 * picked product is keyed by its v3 `id`, which is what the API expects.
 */
@Component({
  selector: 'app-style-create',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule, RouterLink, TranslatePipe, CfImagePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="style-create" data-testid="style-create-page">
      <div class="style-create__container">
        <header class="style-create__header">
          <h1 class="style-create__title">{{ (isEdit() ? 'styles.edit.title' : 'styles.create.title') | translate }}</h1>
          <p class="style-create__subtitle">{{ (isEdit() ? 'styles.edit.subtitle' : 'styles.create.subtitle') | translate }}</p>
        </header>

        <!-- Guests: sign-in prompt instead of the form -->
        <section
          *ngIf="!isAuthenticated(); else form"
          class="style-create__sign-in"
          data-testid="style-create-sign-in"
        >
          <p class="style-create__sign-in-text">
            {{ 'styles.create.signInToCreate' | translate }}
          </p>
          <a
            routerLink="/login"
            class="style-create__sign-in-cta"
            data-testid="style-create-sign-in-cta"
          >
            {{ 'styles.signIn' | translate }}
          </a>
        </section>

        <ng-template #form>
          <!-- Name -->
          <div class="style-create__field">
            <label class="style-create__label" for="style-name">
              {{ 'styles.create.nameLabel' | translate }}
            </label>
            <input
              id="style-name"
              type="text"
              class="style-create__input"
              [placeholder]="'styles.create.namePlaceholder' | translate"
              [(ngModel)]="name"
              [disabled]="isSubmitting()"
              maxlength="80"
              data-testid="style-create-name"
            />
          </div>

          <!-- Selected products tray -->
          <div class="style-create__field">
            <span class="style-create__label">
              {{ 'styles.create.productsLabel' | translate }}
              ({{ picked().length }}/{{ maxProducts }})
            </span>

            <ul
              *ngIf="picked().length > 0; else trayEmpty"
              class="style-tray"
              role="list"
              data-testid="style-create-tray"
            >
              <li *ngFor="let p of picked(); trackBy: trackById" class="style-tray__item">
                <div class="style-tray__thumb">
                  <img
                    *ngIf="p.image; else trayBlank"
                    [src]="p.image | cfImage:'thumb'"
                    alt=""
                    loading="lazy"
                    decoding="async"
                  />
                  <ng-template #trayBlank>
                    <div class="style-tray__blank"></div>
                  </ng-template>
                </div>
                <span class="style-tray__name">{{ p.name }}</span>
                <button
                  type="button"
                  class="style-tray__remove"
                  [attr.aria-label]="'styles.create.remove' | translate"
                  (click)="removeProduct(p.id)"
                  data-testid="style-create-remove"
                >
                  &times;
                </button>
              </li>
            </ul>

            <ng-template #trayEmpty>
              <p class="style-create__tray-empty">
                {{ 'styles.create.trayEmpty' | translate }}
              </p>
            </ng-template>
          </div>

          <!-- Product search picker -->
          <div class="style-create__field" *ngIf="!isFull()">
            <label class="style-create__label" for="style-search">
              {{ 'styles.create.searchLabel' | translate }}
            </label>
            <input
              id="style-search"
              type="search"
              class="style-create__input"
              [placeholder]="'styles.create.searchPlaceholder' | translate"
              [(ngModel)]="query"
              (ngModelChange)="onQueryChange($event)"
              data-testid="style-create-search"
            />

            <ul
              *ngIf="results().length > 0"
              class="style-picker"
              role="list"
              data-testid="style-create-results"
            >
              <li *ngFor="let r of results(); trackBy: trackById" class="style-picker__item">
                <button
                  type="button"
                  class="style-picker__btn"
                  [disabled]="isPicked(r.id)"
                  (click)="addProduct(r)"
                  data-testid="style-create-result"
                >
                  <span class="style-picker__thumb">
                    <img
                      *ngIf="r.primary_image?.url as img; else pickerBlank"
                      [src]="img | cfImage:'thumb'"
                      alt=""
                      loading="lazy"
                      decoding="async"
                    />
                    <ng-template #pickerBlank>
                      <span class="style-tray__blank"></span>
                    </ng-template>
                  </span>
                  <span class="style-picker__name">{{ r.name }}</span>
                  <span class="style-picker__added" *ngIf="isPicked(r.id)">
                    {{ 'styles.create.added' | translate }}
                  </span>
                </button>
              </li>
            </ul>

            <p
              *ngIf="searched() && !isSearching() && results().length === 0"
              class="style-create__no-results"
              data-testid="style-create-no-results"
            >
              {{ 'styles.create.noResults' | translate }}
            </p>
          </div>

          <p *ngIf="isFull()" class="style-create__full-note" data-testid="style-create-full">
            {{ 'styles.create.maxReached' | translate:{ max: maxProducts } }}
          </p>

          <!-- Actions -->
          <div class="style-create__actions">
            <button
              type="button"
              class="style-create__submit"
              [disabled]="!canSubmit() || isSubmitting()"
              (click)="submit()"
              data-testid="style-create-submit"
            >
              {{ (isSubmitting() ? 'common.loading' : (isEdit() ? 'styles.edit.submit' : 'styles.create.submit')) | translate }}
            </button>
            <a routerLink="/styles" class="style-create__cancel" data-testid="style-create-cancel">
              {{ 'common.cancel' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './style-create.scss',
})
export class StyleCreatePageComponent implements OnInit {
  private readonly styleService = inject(StyleService);
  private readonly searchService = inject(SearchService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly maxProducts = MAX_PRODUCTS;
  protected readonly isAuthenticated = this.auth.isAuthenticated;

  /** In edit mode (/styles/:slug/edit) this holds the style's id + slug. */
  private readonly _editId = signal<number | null>(null);
  private editSlug: string | null = null;
  protected readonly isEdit = computed(() => this._editId() !== null);

  protected name = '';
  protected query = '';

  private readonly _picked = signal<PickedProduct[]>([]);
  protected readonly picked = this._picked.asReadonly();

  private readonly _results = signal<Product[]>([]);
  protected readonly results = this._results.asReadonly();

  private readonly _isSearching = signal(false);
  protected readonly isSearching = this._isSearching.asReadonly();

  private readonly _searched = signal(false);
  protected readonly searched = this._searched.asReadonly();

  private readonly _isSubmitting = signal(false);
  protected readonly isSubmitting = this._isSubmitting.asReadonly();

  protected readonly isFull = computed(() => this._picked().length >= MAX_PRODUCTS);
  protected readonly canSubmit = computed(
    () => this.name.trim().length > 0 && this._picked().length > 0,
  );

  /* Debounce token so only the latest in-flight search updates results. */
  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private searchSeq = 0;

  async ngOnInit(): Promise<void> {
    const slug = this.route.snapshot.paramMap.get('slug');
    if (slug === null || slug.trim() === '') {
      return; // create mode
    }
    // Edit mode: load the existing look and prefill. Ownership is enforced
    // server-side on save; we also redirect a non-owner away up front.
    try {
      const style = await this.styleService.getBySlug(slug.trim());
      if (style.is_owner !== true) {
        this.toast.error('styles.edit.notOwner');
        await this.router.navigate(['/styles', slug.trim()]);
        return;
      }
      this.editSlug = style.slug;
      this._editId.set(style.id);
      this.name = style.name;
      this._picked.set(
        style.products.slice(0, MAX_PRODUCTS).map((p: StyleProduct) => ({
          id: p.id,
          slug: p.slug,
          name: p.name,
          image: p.primary_image_url ?? null,
          price: p.price,
        })),
      );
    } catch {
      this.toast.error('styles.edit.loadFailed');
      await this.router.navigate(['/styles']);
    }
  }

  protected onQueryChange(value: string): void {
    this.query = value;
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
    const q = value.trim();
    if (q === '') {
      this._results.set([]);
      this._searched.set(false);
      this._isSearching.set(false);
      return;
    }
    this.searchTimer = setTimeout(() => void this.runSearch(q), 280);
  }

  private async runSearch(q: string): Promise<void> {
    const seq = ++this.searchSeq;
    this._isSearching.set(true);
    try {
      const res = await this.searchService.search(q, 8);
      /* Ignore stale responses (a newer keystroke superseded this one). */
      if (seq !== this.searchSeq) return;
      this._results.set(res.products);
    } catch {
      if (seq !== this.searchSeq) return;
      this._results.set([]);
    } finally {
      if (seq === this.searchSeq) {
        this._isSearching.set(false);
        this._searched.set(true);
      }
    }
  }

  protected isPicked(id: number): boolean {
    return this._picked().some((p) => p.id === id);
  }

  protected addProduct(product: Product): void {
    if (this.isPicked(product.id)) {
      this.toast.info('styles.create.alreadyAdded');
      return;
    }
    if (this.isFull()) {
      this.toast.error('styles.create.maxReached', { max: MAX_PRODUCTS });
      return;
    }
    this._picked.set([
      ...this._picked(),
      {
        id: product.id,
        slug: product.slug,
        name: product.name,
        image: product.primary_image?.url ?? null,
        price: product.price ? `${product.price.currency} ${product.price.amount.toFixed(2)}` : '',
      },
    ]);
  }

  protected removeProduct(id: number): void {
    this._picked.set(this._picked().filter((p) => p.id !== id));
  }

  protected async submit(): Promise<void> {
    if (!this.canSubmit() || this._isSubmitting()) return;
    this._isSubmitting.set(true);
    const input = {
      name: this.name.trim(),
      products: this._picked().map((p) => p.id),
    };
    try {
      const editId = this._editId();
      const saved =
        editId !== null
          ? await this.styleService.updateStyle(editId, input)
          : await this.styleService.createStyle(input);
      this.toast.success(editId !== null ? 'styles.edit.success' : 'styles.create.success');
      /* Reset the 'mine' tab accumulator so the hub refetches the
         created/edited style on next visit. */
      this.styleService.reset('mine');
      /* On edit the server keeps the slug stable; fall back to the loaded
         edit slug (the update response is a summary that also carries it). */
      const slug = saved?.slug ?? this.editSlug;
      await this.router.navigate(slug ? ['/styles', slug] : ['/styles']);
    } catch (err) {
      if (err instanceof HttpErrorResponse && err.status === 401) {
        this.toast.error('styles.create.errors.unauthenticated');
      } else {
        this.toast.error(this._editId() !== null ? 'styles.edit.errors.failed' : 'styles.create.errors.failed');
      }
    } finally {
      this._isSubmitting.set(false);
    }
  }

  protected trackById(_idx: number, item: { id: number }): number {
    return item.id;
  }
}
