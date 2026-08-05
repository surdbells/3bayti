import {
  AfterViewInit, ChangeDetectionStrategy, ChangeDetectorRef,
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  HostListener,
  NgZone,
  OnDestroy,
  OnInit,
  signal,
  ViewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCol,
  IonContent,
  IonGrid,
  IonHeader,
  IonInput,
  IonLabel,
  IonModal,
  IonRange,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController
} from '@ionic/angular/standalone';
import { Platform, ToastController } from "@ionic/angular";
import { Products } from "../../class/products";
import { Labels } from "../../class/labels";
import { Subscription } from "rxjs";
import { ConnectionService } from "../../service/connection.service";
import { Router } from "@angular/router";
import { NetworkService } from "../../service/network.service";
import { MobileNetworkAdapter } from "../../core/http/mobile-network-adapter";
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { GlobalComponent } from "../../global-component";
import { TranslatePipe } from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';

interface Category {
  readonly id: number;
  readonly name: string;
}

type DualRange = { lower: number; upper: number };

@Component({
  selector: 'app-vertican',
  templateUrl: './vertican.page.html',
  styleUrls: ['./vertican.page.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  standalone: true,
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  imports: [
    IonContent,
    IonButton,
    IonButtons,
    IonModal,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonGrid,
    IonRow,
    IonCol,
    IonLabel,
    IonRange,
    IonInput,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxWishlistSheetComponent,
  ]
})
export class VerticanPage implements OnInit, OnDestroy, AfterViewInit {
  products: Products[] = [];
  categories: Labels[] = [];
  private swiperInitialized = false;
  private verticalSwiper: any = null;

  @ViewChild(IonModal) modal!: IonModal;
  @ViewChild('filter_modal', { read: ElementRef }) filterModal!: ElementRef<HTMLIonModalElement>;
  @ViewChild('swiper', { static: false }) swiperEl!: ElementRef<HTMLElement>;
  @ViewChild(IonContent, { static: false }) ionContent!: IonContent;

  // Track active image index for each product: productId -> imageIndex
  activeImageIndices: Map<number, number> = new Map();

  // Track image loading states: "productId-imageIndex" -> boolean
  imageLoaded: { [key: string]: boolean } = {};

  // Vertical pagination
  verticalDots: number[] = [0, 1, 2];
  currentProductDot = 0;
  currentProductIndex = 0;

  // Image windowing: only slides within ±IMAGE_WINDOW of the active index
  // mount their full-screen <img>. Off-window slides render the skeleton only,
  // so iOS (WKWebView) can free the decoded full-screen bitmaps and stop
  // building memory pressure that forces a WebView reload during infinite
  // scroll. Kept updated via currentProductIndex in onVerticalSlideChange.
  private readonly IMAGE_WINDOW = 2;

  // Bounded sliding window: never keep more than this many product SLIDES
  // mounted. Image windowing (above) drops off-screen <img> bitmaps, but the
  // swiper-slide nodes themselves — hero, overlays, buttons, bindings and
  // listeners — still accumulated for every product as the feed grew, and that
  // unbounded slide count is what eventually pushed iOS/WKWebView over its
  // memory budget and forced a silent reload. trimRenderedWindow() keeps only
  // the newest MAX_RENDERED_PRODUCTS slides. Must comfortably exceed the fetch
  // page size (explore.limit = 10) plus the near-end prefetch trigger (5), so
  // trimming never drops the slide the user is on or its neighbours — 20 keeps
  // two full pages live (a 5-slide back-scroll buffer behind the prefetch
  // point) while holding the mounted DOM lower for iOS/WKWebView.
  private readonly MAX_RENDERED_PRODUCTS = 20;

  // Random ordering: a per-session seed sent to the API so it returns a stable
  // random order across every page of one browsing session. Regenerated on each
  // fresh (re)entry / refresh via resetState() so the order varies session to
  // session. We do NOT shuffle client-side (that would break pagination).
  exploreSeed = 0;

  index = signal(0);
  isOnline = true;
  range = signal<DualRange>({ lower: 5, upper: 500 });

  protected readonly category: Category[] = [
    { id: 1, name: 'Abayas' },
    { id: 2, name: 'Mukhawars' },
    { id: 3, name: 'Kaftans' },
    { id: 4, name: 'Bags' },
    { id: 5, name: 'Accessories' },
    { id: 6, name: 'Modest clothes' },
    { id: 7, name: 'Dresses' }
  ];

  protected value: Category | null = { id: 1, name: 'Abayas' };
  isFilterOpen = false;
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  images: string[] = [];
  private sub: Subscription;

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private toastController: ToastController,
    private platform: Platform,
    private router: Router,
    private cdr: ChangeDetectorRef,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private wishlistService: WishlistService,
    private i18n: I18nService,
    private toast: AxNotificationService,
    private ngZone: NgZone
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as CustomEvent).detail.register(100, () => {
      this.nav.navigateRoot('/account');
    });
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.verticalSwiper = null;
  }

  ui_controls = {
    is_loading: false,
    is_loaded: false,
    is_empty: false,
    hasMore: true,
    is_loading_category: false
  }

  filter = {
    id: 0,
    token: "",
    category: [1] as number[],
    price_start: 5,
    price_end: 500,
    delivery: "1 - 3"
  }

  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  }

  ngOnInit() {
    if (!this.isOnline) {
      console.log('You are offline');
    }
  }

  ionViewWillEnter() {
    console.log('[Vertican] ionViewWillEnter');
    this.getObject();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      if (this.products.length === 0) {
        this.explore_products();
      }
    }
  }

  // ========================================
  // Image handling
  // ========================================

  getProductImages(product: any): string[] {
    if (!product) return [];

    if (Array.isArray(product.images) && product.images.length > 0) {
      return product.images;
    }

    if (typeof product.images === 'string' && product.images.length > 0) {
      const imgs = product.images.split(',').map((img: string) => img.trim()).filter((img: string) => img.length > 0);
      if (imgs.length > 0) return imgs;
    }

    if (product.image_1) {
      return [product.image_1];
    }

    return [];
  }

  getActiveImageIndex(productId: number): number {
    return this.activeImageIndices.get(productId) || 0;
  }

  // Image windowing gate. Only slides whose index is within ±IMAGE_WINDOW of the
  // currently active slide mount their <img>; every other slide shows just the
  // skeleton placeholder so iOS can release the decoded full-screen bitmap.
  // This is what keeps WKWebView memory flat during infinite scroll and stops
  // the "glitch"/reload. currentProductIndex is kept in sync in
  // onVerticalSlideChange (and on initial swiper attach).
  isInImageWindow(idx: number): boolean {
    return Math.abs(idx - this.currentProductIndex) <= this.IMAGE_WINDOW;
  }

  getCurrentImage(product: any): string {
    if (!product) return '';
    const images = this.getProductImages(product);
    const activeIndex = this.getActiveImageIndex(product.product_id);
    return images[activeIndex] || product.image_1 || '';
  }

  setActiveImage(productId: number, index: number, event: Event) {
    event.preventDefault();
    event.stopPropagation();
    console.log('[Image] setActiveImage:', productId, index);
    this.activeImageIndices.set(productId, index);
    this.cdr.markForCheck();
  }

  nextImage(productId: number, event: Event) {
    event.preventDefault();
    event.stopPropagation();

    const product = this.products.find(p => p.product_id === productId);
    if (!product) return;

    const images = this.getProductImages(product);
    const currentIndex = this.getActiveImageIndex(productId);

    if (currentIndex < images.length - 1) {
      console.log('[Image] nextImage:', productId, currentIndex + 1);
      this.activeImageIndices.set(productId, currentIndex + 1);
      this.cdr.markForCheck();
    }
  }

  prevImage(productId: number, event: Event) {
    event.preventDefault();
    event.stopPropagation();

    const currentIndex = this.getActiveImageIndex(productId);

    if (currentIndex > 0) {
      console.log('[Image] prevImage:', productId, currentIndex - 1);
      this.activeImageIndices.set(productId, currentIndex - 1);
      this.cdr.markForCheck();
    }
  }

  // ========================================
  // Horizontal swipe to change image
  //
  // Lightweight touch handlers on the .image-container — NO nested Swiper.
  // The main swiper is direction="vertical", so horizontal drags never move
  // it; we do not stopPropagation, which keeps vertical dragging working.
  // ========================================
  private imageTouchStartX = 0;
  private imageTouchStartY = 0;
  private readonly imageSwipeThreshold = 40;

  onImageTouchStart(event: TouchEvent) {
    const touch = event.touches?.[0];
    if (!touch) return;
    this.imageTouchStartX = touch.clientX;
    this.imageTouchStartY = touch.clientY;
  }

  onImageTouchEnd(event: TouchEvent, productId: number) {
    const touch = event.changedTouches?.[0];
    if (!touch) return;

    const deltaX = touch.clientX - this.imageTouchStartX;
    const deltaY = touch.clientY - this.imageTouchStartY;

    // Only treat as an image swipe when the gesture is predominantly
    // horizontal and clears the threshold. Otherwise let it pass through
    // (e.g. a vertical drag for the vertical swiper, or a tap).
    if (Math.abs(deltaX) <= Math.abs(deltaY) || Math.abs(deltaX) < this.imageSwipeThreshold) {
      return;
    }

    const product = this.products.find(p => p.product_id === productId);
    if (!product) return;

    const images = this.getProductImages(product);
    const currentIndex = this.getActiveImageIndex(productId);

    if (deltaX < 0) {
      // Swipe left -> next image (respect upper bound).
      if (currentIndex < images.length - 1) {
        this.activeImageIndices.set(productId, currentIndex + 1);
        this.cdr.markForCheck();
      }
    } else {
      // Swipe right -> previous image (respect lower bound).
      if (currentIndex > 0) {
        this.activeImageIndices.set(productId, currentIndex - 1);
        this.cdr.markForCheck();
      }
    }
  }

  onImageLoad(productId: number, imageIndex: number) {
    const key = `${productId}-${imageIndex}`;
    console.log('[Image] Loaded:', key);
    this.imageLoaded[key] = true;
    this.cdr.markForCheck();
  }

  onImageError(productId: number, imageIndex: number) {
    const key = `${productId}-${imageIndex}`;
    console.log('[Image] Error:', key);
    this.imageLoaded[key] = true; // Hide skeleton even on error
    this.cdr.markForCheck();
  }

  // ========================================
  // Vertical swiper
  // ========================================

  onVerticalSlideChange(event: any) {
    try {
      const swiper = event?.target?.swiper;
      if (swiper) {
        this.ngZone.run(() => {
          // Drive pagination off the swiper's own slide count, but drive the
          // near-end "fetch more" check off the data length (this.products),
          // NOT swiper.slides.length. swiper.slides can briefly lag the data
          // after an append, which caused premature/duplicate fetches.
          const totalSlides = swiper.slides?.length || this.products.length;
          const currentIndex = swiper.activeIndex ?? 0;

          console.log('[Swiper] Slide changed:', currentIndex);

          this.currentProductIndex = currentIndex;
          this.index.set(currentIndex);
          this.updateVerticalPagination(currentIndex, totalSlides);

          // Fetch more when near end (measured against the product data length)
          if (this.products.length - currentIndex <= 5 && !this.ui_controls.is_loading && this.ui_controls.hasMore) {
            this.getMoreItems();
          }

          this.cdr.markForCheck();
        });
      }
    } catch (error) {
      console.error('[Swiper] Error:', error);
    }
  }

  goToNext(event: Event) {
    event.preventDefault();
    event.stopPropagation();

    console.log('[Nav] goToNext, current:', this.currentProductIndex);

    if (!this.verticalSwiper) {
      const el = this.swiperEl?.nativeElement as any;
      this.verticalSwiper = el?.swiper;
    }

    if (this.verticalSwiper && this.currentProductIndex < this.products.length - 1) {
      this.verticalSwiper.slideNext();
    }
  }

  goToPrevious(event: Event) {
    event.preventDefault();
    event.stopPropagation();

    console.log('[Nav] goToPrevious, current:', this.currentProductIndex);

    if (!this.verticalSwiper) {
      const el = this.swiperEl?.nativeElement as any;
      this.verticalSwiper = el?.swiper;
    }

    if (this.verticalSwiper && this.currentProductIndex > 0) {
      this.verticalSwiper.slidePrev();
    }
  }

  updateVerticalPagination(activeIndex: number, totalSlides: number) {
    if (totalSlides <= 3) {
      this.currentProductDot = activeIndex;
    } else {
      const chunk = Math.floor(totalSlides / 3);
      if (activeIndex < chunk) {
        this.currentProductDot = 0;
      } else if (activeIndex < chunk * 2) {
        this.currentProductDot = 1;
      } else {
        this.currentProductDot = 2;
      }
    }
  }

  // ========================================
  // Filter methods
  // ========================================

  closeFilter() {
    this.isFilterOpen = false;
  }

  isCategorySelected(categoryId: number): boolean {
    return this.filter.category.includes(categoryId);
  }

  toggleCategory(categoryId: number) {
    const index = this.filter.category.indexOf(categoryId);
    if (index > -1) {
      if (this.filter.category.length > 1) {
        this.filter.category.splice(index, 1);
      }
    } else {
      this.filter.category.push(categoryId);
    }
  }

  resetFilters() {
    this.filter.category = [1];
    this.range.set({ lower: 5, upper: 500 });
    this.filter.price_start = 5;
    this.filter.price_end = 500;
  }

  // ========================================
  // API request parameters
  // ========================================

  explore = {
    id: 0,
    token: "",
    limit: 10,
    offset: 0
  }

  rqst_param = {
    id: 0,
    token: ""
  }

  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }

  // ========================================
  // Range slider
  // ========================================

  readonly min = 1;
  readonly max = 5000;
  readonly step = 5;

  onRangeChange(ev: any) {
    const v = ev?.detail?.value as DualRange | number;
    if (typeof v === 'number') return;
    const lower = this.clamp(v.lower, this.min, Math.min(v.upper, this.max));
    const upper = this.clamp(v.upper, Math.max(v.lower, this.min), this.max);
    this.range.set({ lower: this.snap(lower), upper: this.snap(upper) });
    this.filter.price_start = this.range().lower;
    this.filter.price_end = this.range().upper;
  }

  onLowerInput(ev: any) {
    const raw = Number(ev?.target?.value ?? this.range().lower);
    const snapped = this.snap(this.clamp(raw, this.min, this.range().upper));
    this.range.set({ lower: snapped, upper: this.range().upper });
    this.filter.price_start = this.range().lower;
    this.filter.price_end = this.range().upper;
  }

  onUpperInput(ev: any) {
    const raw = Number(ev?.target?.value ?? this.range().upper);
    const snapped = this.snap(this.clamp(raw, this.range().lower, this.max));
    this.range.set({ lower: this.range().lower, upper: snapped });
    this.filter.price_start = this.range().lower;
    this.filter.price_end = this.range().upper;
  }

  private clamp(n: number, lo: number, hi: number) {
    return Math.min(Math.max(n, lo), hi);
  }

  private snap(n: number) {
    return Math.round(n / this.step) * this.step;
  }

  // ========================================
  // API calls
  // ========================================

  get_filtered_products() {
    this.isFilterOpen = false;
    this.resetState();

    this.ui_controls.is_loading = true;
    this.cdr.markForCheck();

    this.filter.id = this.single_user.id;
    this.filter.token = this.single_user.token;

    this.networkService.post_request(this.filter, GlobalComponent.filterexplore)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_loading = false;
            this.ui_controls.is_loaded = true;
            this.cdr.markForCheck();
            setTimeout(() => this.initializeSwipers(), 200);
          } else {
            this.ui_controls.is_loading = false;
            this.cdr.markForCheck();
            this.presentToast('middle', this.i18n.t('empty_filter'));
          }
        }
      });
  }

  explore_products() {
    console.log('[Vertican] explore_products');
    this.resetState();

    this.ui_controls.is_loading = true;
    this.ui_controls.hasMore = true;
    this.cdr.markForCheck();

    this.explore.id = this.single_user.id;
    this.explore.token = this.single_user.token;
    this.explore.offset = 0;

    this.networkAdapter.get_v3('GET /mobile/explore-listing', {
      queryParams: {
        limit: this.explore.limit,
        offset: this.explore.offset,
        seed: this.exploreSeed,
      },
    })
      .subscribe({
        next: (response: any) => {
          console.log('[Vertican] Products:', response.data?.length);
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_loading = false;
            this.ui_controls.is_loaded = true;
            this.ui_controls.is_empty = false;
            this.cdr.markForCheck();
            setTimeout(() => this.initializeSwipers(), 200);
          } else {
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
            this.cdr.markForCheck();
          }
        }
      });
  }

  private resetState() {
    this.products = [];
    this.swiperInitialized = false;
    this.verticalSwiper = null;
    this.currentProductIndex = 0;
    this.activeImageIndices.clear();
    this.imageLoaded = {};
    this.ui_controls.is_loaded = false;
    this.ui_controls.is_empty = false;

    // Fresh random seed for this browsing session. The same seed is reused for
    // every page (initial + getMoreItems) so the API returns one stable random
    // order; regenerating here means each fresh (re)entry / refresh reshuffles.
    this.exploreSeed = Math.floor(Math.random() * 2_000_000_000);
  }

  getMoreItems() {
    // Re-entrancy guard: this MUST be checked (and is_loading set) before we
    // touch the offset or fire the request, so overlapping slide-change events
    // during a fast scroll cannot kick off duplicate fetches.
    if (this.ui_controls.is_loading || !this.ui_controls.hasMore) return;

    console.log('[Vertican] getMoreItems');

    // Set the guard BEFORE advancing offset / starting the request.
    this.ui_controls.is_loading = true;
    this.cdr.markForCheck();

    this.explore.id = this.single_user.id;
    this.explore.token = this.single_user.token;

    // Request the NEXT page without mutating the persisted offset yet — we only
    // commit the advance after a successful, deduped, non-empty append. On an
    // empty/failed page the offset stays put and we stop.
    const nextOffset = this.explore.offset + this.explore.limit;

    this.networkAdapter.get_v3('GET /mobile/explore-listing', {
      queryParams: {
        limit: this.explore.limit,
        offset: nextOffset,
        seed: this.exploreSeed,
      },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code !== 200 || response.status !== 'success') {
            this.ui_controls.hasMore = false;
            this.ui_controls.is_loading = false;
            this.cdr.markForCheck();
            return;
          }

          const incoming: any[] = Array.isArray(response.data) ? response.data : [];

          // Dedupe against products already on screen by product_id.
          const existingIds = new Set(this.products.map(p => p.product_id));
          const deduped = incoming.filter(p => p && !existingIds.has(p.product_id));

          // Empty (or fully-duplicate) page -> nothing new to show; stop and
          // leave offset untouched so we don't loop on the same data.
          if (deduped.length === 0) {
            this.ui_controls.hasMore = false;
            this.ui_controls.is_loading = false;
            this.cdr.markForCheck();
            return;
          }

          // Append in place (no full reassignment) so swiper does not re-render
          // and reset the scroll/active index.
          this.products.push(...deduped);

          // Commit the offset advance only now that we've appended.
          this.explore.offset = nextOffset;

          // If the raw page came back short, there is no more data after this.
          if (incoming.length < this.explore.limit) {
            this.ui_controls.hasMore = false;
          }

          this.ui_controls.is_loading = false;

          // Enforce the bounded sliding window: drop the oldest slides beyond
          // MAX_RENDERED_PRODUCTS and re-sync Swiper's active index so the
          // product currently on screen does not jump. Also lets swiper pick up
          // the newly appended slides (update()), preserving the active index.
          this.trimRenderedWindow();

          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.hasMore = false;
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        }
      });
  }

  // ========================================
  // Sliding window
  // ========================================
  //
  // The explore feed appends pages forever. Without a cap, every product slide
  // stays mounted and iOS/WKWebView eventually hits memory pressure and silently
  // reloads the WebView (the "glitch"). Here we keep only the newest
  // MAX_RENDERED_PRODUCTS slides.
  //
  // The catch: Swiper tracks slides by NUMERIC index, not by product. Dropping N
  // slides off the FRONT shifts every remaining slide's index down by N, so the
  // slide Swiper considers "active" would now hold a DIFFERENT product and the
  // feed would visibly jump forward. We compensate by shifting Swiper's active
  // index back by the same N (slideTo, instant, callbacks off).
  //
  // removedCount MUST be computed BEFORE splicing — computing it against the
  // already-trimmed array yields 0 and the index re-sync silently never happens.
  private trimRenderedWindow() {
    const sw: any = this.verticalSwiper || (this.swiperEl?.nativeElement as any)?.swiper;

    const removedCount = this.products.length - this.MAX_RENDERED_PRODUCTS;
    if (removedCount <= 0) {
      // Under the cap — just let Swiper pick up the freshly appended slides so
      // swiper.slides.length tracks the data, preserving the active index.
      setTimeout(() => sw?.update?.(), 0);
      return;
    }

    try {
      // Capture the active index BEFORE any DOM change.
      const activeBefore = sw?.activeIndex ?? this.currentProductIndex;

      // Release per-product image state for the slides we are about to drop so
      // activeImageIndices / imageLoaded don't grow unbounded alongside the feed.
      const removed = this.products.slice(0, removedCount);
      for (const p of removed) {
        this.activeImageIndices.delete(p.product_id);
        const prefix = p.product_id + '-';
        for (const key of Object.keys(this.imageLoaded)) {
          if (key.startsWith(prefix)) delete this.imageLoaded[key];
        }
      }

      // Drop the oldest slides from the data.
      this.products.splice(0, removedCount);

      // Flush the @for synchronously so the removed <swiper-slide> nodes are
      // actually gone from the DOM before we ask Swiper to recount + reposition.
      this.cdr.detectChanges();

      const newActive = Math.max(0, activeBefore - removedCount);
      this.currentProductIndex = newActive;
      this.index.set(newActive);
      this.updateVerticalPagination(newActive, this.products.length);

      if (sw) {
        // update() first so Swiper re-scans the now-shorter slide list, then
        // jump to the SAME product instantly. runCallbacks=false so this
        // reposition does not re-fire onVerticalSlideChange (which would kick
        // off another fetch). A trailing update() settles the new geometry.
        sw.update();
        sw.slideTo(newActive, 0, false);
        sw.update();
      }
    } catch (error) {
      console.error('[Vertican] trimRenderedWindow error:', error);
    }
  }

  get_label() {
    this.ui_controls.is_loading_category = true;
    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      });
  }

  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;

    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      });
  }

  startAddToCloset(product: number, product_name: string, image_1: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = product;
    this.addCloset.product_name = product_name;
    this.addCloset.product_image = image_1;
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
  }

  // ========================================
  // Notifications
  // ========================================

  error_notification(message: string) {
    this.toast.error(message, { position: "top-center" });
  }

  /**
   * Inline create from the wishlist sheet: POST the new label, then drop the
   * pending product straight into it (addToCloset closes the sheet + toasts).
   * On failure the sheet stays open so the user can retry.
   */
  async onCreateLabel(name: string): Promise<void> {
    if (this.isCreatingLabel) {
      return;
    }
    this.isCreatingLabel = true;
    this.cdr.markForCheck();
    try {
      const label = await this.wishlistService.createLabel(this.single_user.token, name);
      if (label) {
        this.addToCloset(label.id);
      } else {
        this.error_notification(this.i18n.t('network_error_retry'));
      }
    } catch {
      this.error_notification(this.i18n.t('network_error_retry'));
    } finally {
      this.isCreatingLabel = false;
      this.cdr.markForCheck();
    }
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }

  // ========================================
  // Navigation
  // ========================================

  open_vendor(id: number, name: string) {
    this.router.navigate(['/', 'vendors'], { queryParams: { id, name } });
  }

  triggerBack() {
    this.nav.back();
  }

  open_product(id: number) {
    this.router.navigate(['/', 'product'], { queryParams: { id } });
  }

  closeWish() {
    this.isWishOpen = false;
  }

  openHome() {
    this.router.navigate(['/', 'account']);
  }

  OnDidDismiss() {
    this.isWishOpen = false;
  }

  // ========================================
  // Swiper initialization
  // ========================================

  initializeSwipers() {
    if (this.swiperInitialized) return;
    this.swiperInitialized = true;

    console.log('[Swiper] Initializing...');

    const el = this.swiperEl?.nativeElement as any;
    if (!el) {
      console.log('[Swiper] No element');
      return;
    }

    const attachSwiper = () => {
      const sw: any = el.swiper;
      if (!sw) {
        setTimeout(attachSwiper, 100);
        return;
      }

      console.log('[Swiper] Attached, slides:', sw.slides?.length);
      this.verticalSwiper = sw;

      this.index.set(sw.activeIndex ?? 0);
      this.currentProductIndex = sw.activeIndex ?? 0;
      this.updateVerticalPagination(sw.activeIndex ?? 0, sw.slides?.length ?? this.products.length);
      this.cdr.markForCheck();
    };

    attachSwiper();
  }

  ngAfterViewInit(): void {
    setTimeout(() => this.initializeSwipers(), 200);
  }

  async presentToast(position: 'top' | 'middle' | 'bottom', message: string) {
    const toast = await this.toastController.create({
      message: message,
      duration: 2000,
      position: position,
    });
    await toast.present();
  }

  protected readonly Math = Math;
}
