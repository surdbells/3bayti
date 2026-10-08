import {
  AfterViewInit,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  OnChanges,
  Output,
  SimpleChanges,
  ViewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { A11yModule } from '@angular/cdk/a11y';

import { IconComponent } from '../../../../shared/icon/icon.component';
import { CollectionRef, CuratedProduct, ProductDetail } from '../collection-builder.model';

/** How far the lazy GET /admin/products/:id for the panel got. */
export type PanelDetailState = 'loading' | 'loaded' | 'error' | 'forbidden';

/** Descriptions longer than this are clipped behind "Show more". */
const DESCRIPTION_PREVIEW = 360;

/**
 * Product details slide-over for the collection builder.
 *
 * Presentational: the builder owns the curation state and passes it in
 * (`inCollection`, `otherCollections`, `locked`); the panel emits `toggle`
 * for Add/Remove and `closed` to close. What the card already knows shows at
 * once; the admin detail (`detail`) fills in description, category, SKU and
 * stock as it lands.
 *
 * Accessibility: role="dialog" + aria-modal, labelled by the product name
 * heading, which takes focus on open; Tab is trapped inside (cdkTrapFocus);
 * Esc (anywhere on the page) and the backdrop close it. The builder returns
 * focus to the card.
 */
@Component({
  selector: 'app-collection-product-panel',
  standalone: true,
  imports: [CommonModule, RouterLink, A11yModule, IconComponent],
  templateUrl: './collection-product-panel.component.html',
  styleUrl: './collection-product-panel.component.css',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CollectionProductPanelComponent implements OnChanges, AfterViewInit {
  @Input({ required: true }) product!: CuratedProduct;
  @Input() detail: ProductDetail | null = null;
  @Input() detailState: PanelDetailState = 'loading';
  /** In the builder's (unsaved) curated list right now. */
  @Input() inCollection = false;
  /** In this collection as last saved (to flag an unsaved add/remove). */
  @Input() savedInCollection = false;
  /** OTHER collections the product is in; undefined while loading. */
  @Input() otherCollections: CollectionRef[] | undefined;
  @Input() membershipFailed = false;
  @Input() currentCollection: { id: number; name: string } = { id: 0, name: '' };
  /** Curation is locked (loading / saving): Add/Remove is aria-disabled. */
  @Input() locked = false;
  /** 1-based position in the curated list (null when not in it). */
  @Input() position: number | null = null;
  /** The admin may open the admin product page (products.view). */
  @Input() canOpenProduct = true;

  @Output() closed = new EventEmitter<void>();
  @Output() toggle = new EventEmitter<CuratedProduct>();
  @Output() retryDetail = new EventEmitter<void>();
  /** Look the product's other collections up again (after a failed lookup). */
  @Output() retryMemberships = new EventEmitter<void>();
  /**
   * A link in the panel was followed. The builder closes the panel first, so
   * an unsaved-changes prompt (a CDK overlay) is never hidden behind it.
   */
  @Output() navigating = new EventEmitter<void>();

  @ViewChild('heading') private heading?: ElementRef<HTMLElement>;

  readonly headingId = `ecp-title-${Math.random().toString(36).slice(2, 9)}`;
  imageIndex = 0;
  descriptionExpanded = false;

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['product'] && !changes['product'].firstChange) {
      const prev = changes['product'].previousValue as CuratedProduct | undefined;
      if (prev?.id !== this.product?.id) {
        this.imageIndex = 0;
        this.descriptionExpanded = false;
        // A different product in the same panel: re-announce it.
        queueMicrotask(() => this.focusHeading());
      }
    }
    if (this.imageIndex >= this.images.length) this.imageIndex = 0;
  }

  ngAfterViewInit(): void {
    this.focusHeading();
  }

  /** Move focus to the dialog's heading so its name is announced. */
  focusHeading(): void {
    this.heading?.nativeElement.focus({ preventScroll: true });
  }

  /** Gallery: the admin detail's images once loaded, else the card's. */
  get images(): string[] {
    const fromDetail = this.detail?.images ?? [];
    return fromDetail.length ? fromDetail : this.product?.images?.length ? this.product.images : (this.product?.image ? [this.product.image] : []);
  }

  get currentImage(): string | null {
    return this.images[this.imageIndex] ?? null;
  }

  get name(): string {
    return this.detail?.name || this.product.name;
  }

  get price(): number | null {
    return this.detail?.price ?? this.product.price;
  }

  get salePrice(): number | null {
    return this.detail ? this.detail.sale_price : this.product.sale_price;
  }

  get storeName(): string | null {
    return this.detail?.store_name ?? this.product.vendor_name;
  }

  get inStock(): boolean | null {
    return this.detail?.in_stock ?? this.product.in_stock;
  }

  get description(): string {
    return this.detail?.description ?? '';
  }

  get descriptionClipped(): boolean {
    return this.description.length > DESCRIPTION_PREVIEW;
  }

  get descriptionShown(): string {
    const d = this.description;
    if (this.descriptionExpanded || d.length <= DESCRIPTION_PREVIEW) return d;
    return d.slice(0, DESCRIPTION_PREVIEW).replace(/\s+\S*$/, '') + '…';
  }

  get stockLabel(): string | null {
    const d = this.detail;
    if (d?.stock_status) {
      const status = d.stock_status.replace(/_/g, ' ');
      return d.stock_quantity != null ? `${status} (${d.stock_quantity})` : status;
    }
    if (this.inStock === true) return 'In stock';
    if (this.inStock === false) return 'Out of stock';
    return null;
  }

  get unsavedChange(): boolean {
    return this.inCollection !== this.savedInCollection;
  }

  selectImage(i: number): void {
    if (i >= 0 && i < this.images.length) this.imageIndex = i;
  }

  prevImage(): void {
    const n = this.images.length;
    if (n > 1) this.imageIndex = (this.imageIndex - 1 + n) % n;
  }

  nextImage(): void {
    const n = this.images.length;
    if (n > 1) this.imageIndex = (this.imageIndex + 1) % n;
  }

  onGalleryKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      const rtl = getComputedStyle(event.currentTarget as Element).direction === 'rtl';
      const forward = (event.key === 'ArrowRight') !== rtl;
      forward ? this.nextImage() : this.prevImage();
    }
  }

  /**
   * Esc closes the panel wherever focus is (inside it, or on <body> after a
   * click on the backdrop edge or a scrollbar). Listening on the document
   * rather than the dialog element means a focus loss can never make Esc stop
   * working. A handler that already consumed the key (defaultPrevented, e.g.
   * a dialog opened above the panel) wins.
   */
  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && !event.defaultPrevented) {
      event.preventDefault();
      this.closed.emit();
    }
  }

  onToggle(): void {
    if (this.locked) return;
    this.toggle.emit(this.product);
  }

  trackCollection = (_: number, c: CollectionRef) => c.id;
}
