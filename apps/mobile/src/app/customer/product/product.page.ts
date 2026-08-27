import {
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  OnInit,
  AfterViewInit,
  OnDestroy,
  signal,
  ViewChild,
  ChangeDetectorRef,
  ChangeDetectionStrategy
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonCard,
  IonCol,
  IonContent,
  IonFooter,
  IonImg,
  IonRow,
  IonText,
  NavController
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from "@angular/router";
import { Subscription } from "rxjs";
import { Platform } from "@ionic/angular";
import { ConnectionService } from "../../service/connection.service";
import { NetworkService } from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { SizeChipsComponent } from "../../size-chips/size-chips.component";
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from "../../translate.pipe";
import { CartCountService } from '../../core/services/cart-count.service';
import { ProductReviewService } from '../../core/services/product-review.service';
import { ProductReview } from '../../class/product-review';
import { Products } from "../../class/products";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { cfImage } from '../../shared/cf-image';
export interface StoreMeasurement {
  id: number;
  token: string;
  measurement: number;
  size: string;
  bust: number;
  waist: number;
  hip: number;
  length: number;
  neck: number;
  arm: number;
  armhole: number;
  shoulder: number;
}

export interface ColorOption {
  id: string;
  text: string;
  hex: string;
}

@Component({
  selector: 'app-product',
  templateUrl: './product.page.html',
  styleUrls: ['./product.page.scss'],
  standalone: true,
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    IonContent,
    CommonModule,
    FormsModule,
    IonImg,
    IonText,
    IonCol,
    IonRow,
    IonFooter,
    SizeChipsComponent,
    TranslatePipe,
    IonCard,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
  ]
})
export class ProductPage implements OnInit, AfterViewInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  store_measurement: StoreMeasurement[] = [];
  product: Products[] = [];
  @ViewChild('swiper') swiperEl?: ElementRef<HTMLElement>;
  @ViewChild('lightboxSwiper') lightboxSwiperEl?: ElementRef<HTMLElement>;
  @ViewChild('thumbStrip') thumbStripEl?: ElementRef<HTMLElement>;
  index = signal(0);

  /** Fullscreen tap-to-expand image gallery (cinematic filmstrip). */
  lightboxOpen = signal(false);
  lightboxIndex = signal(0);
  /** Live swipe-down-to-dismiss drag state. */
  lbDragY = signal(0);
  lbBackdropOpacity = signal(1);
  private lbDrag = { active: false, dir: '' as '' | 'v' | 'h', startX: 0, startY: 0 };
  isOnline = true;
  isMeasureOpen = false;
  isSizeGuideOpen = false;
  itemExists = false;
  private sub: Subscription | null = null;
  selectedHex = "";
  visibleCount = 3;

  // Color options with hex values for preview
  colorOptions: ColorOption[] = [
    { id: 'black', text: 'Black', hex: '#000000' },
    { id: 'white', text: 'White', hex: '#FFFFFF' },
    { id: 'off-white', text: 'Off White', hex: '#FAF9F6' },
    { id: 'charcoal', text: 'Charcoal', hex: '#333333' },
    { id: 'gray', text: 'Gray', hex: '#808080' },
    { id: 'light-gray', text: 'Light Gray', hex: '#D3D3D3' },
    { id: 'beige', text: 'Beige', hex: '#F5F5DC' },
    { id: 'tan', text: 'Tan', hex: '#D2B48C' },
    { id: 'camel', text: 'Camel', hex: '#C19A6B' },
    { id: 'brown', text: 'Brown', hex: '#8B4513' },
    { id: 'chocolate', text: 'Chocolate', hex: '#5D3A00' },
    { id: 'navy', text: 'Navy', hex: '#001F3F' },
    { id: 'blue', text: 'Blue', hex: '#1F75FE' },
    { id: 'light-blue', text: 'Light Blue', hex: '#87CEEB' },
    { id: 'sky-blue', text: 'Sky Blue', hex: '#00BFFF' },
    { id: 'denim', text: 'Denim', hex: '#274472' },
    { id: 'teal', text: 'Teal', hex: '#008080' },
    { id: 'aqua', text: 'Aqua', hex: '#00FFFF' },
    { id: 'mint', text: 'Mint', hex: '#98FF98' },
    { id: 'green', text: 'Green', hex: '#2E8B57' },
    { id: 'lime', text: 'Lime', hex: '#32CD32' },
    { id: 'olive', text: 'Olive', hex: '#808000' },
    { id: 'forest', text: 'Forest Green', hex: '#228B22' },
    { id: 'red', text: 'Red', hex: '#C0392B' },
    { id: 'crimson', text: 'Crimson', hex: '#DC143C' },
    { id: 'burgundy', text: 'Burgundy', hex: '#800020' },
    { id: 'pink', text: 'Pink', hex: '#FFC0CB' },
    { id: 'hot-pink', text: 'Hot Pink', hex: '#FF69B4' },
    { id: 'rose', text: 'Rose', hex: '#FF007F' },
    { id: 'purple', text: 'Purple', hex: '#800080' },
    { id: 'lavender', text: 'Lavender', hex: '#E6E6FA' },
    { id: 'violet', text: 'Violet', hex: '#8A2BE2' },
    { id: 'orange', text: 'Orange', hex: '#FF8C00' },
    { id: 'peach', text: 'Peach', hex: '#FFDAB9' },
    { id: 'coral', text: 'Coral', hex: '#FF7F50' },
    { id: 'yellow', text: 'Yellow', hex: '#FFD200' },
    { id: 'mustard', text: 'Mustard', hex: '#FFDB58' },
    { id: 'gold', text: 'Gold', hex: '#D4AF37' },
    { id: 'silver', text: 'Silver', hex: '#C0C0C0' },
    { id: 'bronze', text: 'Bronze', hex: '#CD7F32' },
    { id: 'champagne', text: 'Champagne', hex: '#F7E7CE' },
    { id: 'ivory', text: 'Ivory', hex: '#FFFFF0' },
    { id: 'multicolor', text: 'Multicolor', hex: 'linear-gradient(135deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3, #54a0ff)' }
  ];

  // Light colors that need dark checkmark icons
  lightColors = ['white', 'off-white', 'light-gray', 'beige', 'ivory', 'champagne', 'peach', 'lavender', 'mint', 'aqua', 'yellow', 'pink'];

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef,
    private i18n: I18nService,
    private cartCount: CartCountService,
    private reviewService: ProductReviewService,
  ) {
    this.platform.backButton.subscribeWithPriority(10, () => {
    });
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnInit() {
    this.rqst_param.product = Number(this.route.snapshot.queryParamMap.get('id'));
    this.rqst_param.product_name = this.route.snapshot.queryParamMap.get('name') || '';
    this.getObject();
  }

  ngAfterViewInit() {
    // The swiper lives inside @if(!product_missing) (a structural directive),
    // so its ViewChild only resolves AFTER the view renders. Init it here, not
    // in ngOnInit, a static ref there is undefined and throws, which used to
    // abort the rest of ngOnInit (including the product load + footer).
    this.initSwiper();
  }

  ngOnDestroy() {
    this.sub?.unsubscribe();
  }

  private initSwiper() {
    const el = this.swiperEl?.nativeElement as any;
    if (!el) {
      return;
    }
    const attach = () => {
      const sw: any = el.swiper;
      if (!sw) {
        setTimeout(attach, 30);
        return;
      }
      this.index.set(sw.activeIndex ?? 0);
      sw.on('slideChange', () => {
        this.index.set(sw.activeIndex ?? 0);
        this.cdr.markForCheck();
      });
    };
    attach();
  }

  // ── Fullscreen lightbox gallery ─────────────────────────────────────
  /** Open the tap-to-expand fullscreen gallery at the tapped photo. */
  openLightbox(start: number): void {
    if (!this.images.length) { return; }
    this.lightboxIndex.set(Math.max(0, Math.min(start, this.images.length - 1)));
    this.resetLbDrag();
    this.lightboxOpen.set(true);
    this.cdr.markForCheck();
    // The overlay renders via @if, init its swiper once the view paints.
    setTimeout(() => this.initLightboxSwiper(this.lightboxIndex()), 0);
  }

  closeLightbox(): void {
    this.lightboxOpen.set(false);
    this.resetLbDrag();
    this.cdr.markForCheck();
    // Leave the PDP hero on the photo the user last viewed in the lightbox.
    const sw: any = (this.swiperEl?.nativeElement as any)?.swiper;
    sw?.slideTo?.(this.lightboxIndex(), 0);
  }

  private initLightboxSwiper(start: number): void {
    const el = this.lightboxSwiperEl?.nativeElement as any;
    if (!el) { return; }
    const attach = () => {
      const sw: any = el.swiper;
      if (!sw) { setTimeout(attach, 30); return; }
      sw.slideTo(start, 0, false);
      this.lightboxIndex.set(sw.activeIndex ?? start);
      this.centerActiveThumb();
      sw.on('slideChange', () => {
        this.lightboxIndex.set(sw.activeIndex ?? 0);
        this.centerActiveThumb();
        this.cdr.markForCheck();
      });
    };
    attach();
  }

  /** Jump the lightbox to a thumbnail. */
  goToLightboxSlide(i: number): void {
    const sw: any = (this.lightboxSwiperEl?.nativeElement as any)?.swiper;
    sw?.slideTo?.(i);
    this.lightboxIndex.set(i);
    this.centerActiveThumb();
  }

  /** Scroll the coverflow strip so the active thumb sits centered. */
  private centerActiveThumb(): void {
    const strip = this.thumbStripEl?.nativeElement;
    if (!strip) { return; }
    const active = strip.children[this.lightboxIndex()] as HTMLElement | undefined;
    if (!active) { return; }
    const target = active.offsetLeft - (strip.clientWidth - active.clientWidth) / 2;
    strip.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
  }

  // Swipe-down-to-dismiss ----------------------------------------------
  onLbTouchStart(e: TouchEvent): void {
    const sw: any = (this.lightboxSwiperEl?.nativeElement as any)?.swiper;
    if (sw?.zoom && sw.zoom.scale > 1) { return; } // let zoom-pan win
    const t = e.touches[0];
    this.lbDrag = { active: true, dir: '', startX: t.clientX, startY: t.clientY };
  }

  onLbTouchMove(e: TouchEvent): void {
    if (!this.lbDrag.active) { return; }
    const t = e.touches[0];
    const dx = t.clientX - this.lbDrag.startX;
    const dy = t.clientY - this.lbDrag.startY;
    if (!this.lbDrag.dir) {
      if (dy > 0 && Math.abs(dy) > Math.abs(dx) + 6) {
        this.lbDrag.dir = 'v';
        const sw: any = (this.lightboxSwiperEl?.nativeElement as any)?.swiper;
        if (sw) { sw.allowTouchMove = false; } // stop horizontal swipe fighting the drag
      } else if (Math.abs(dx) >= Math.abs(dy)) {
        this.lbDrag.dir = 'h';
      }
    }
    if (this.lbDrag.dir === 'v') {
      const y = Math.max(0, dy);
      this.lbDragY.set(y);
      this.lbBackdropOpacity.set(Math.max(0.2, 1 - y / 500));
      this.cdr.markForCheck();
    }
  }

  onLbTouchEnd(): void {
    const sw: any = (this.lightboxSwiperEl?.nativeElement as any)?.swiper;
    if (sw) { sw.allowTouchMove = true; }
    const dismissed = this.lbDrag.dir === 'v' && this.lbDragY() > 110;
    this.lbDrag = { active: false, dir: '', startX: 0, startY: 0 };
    if (dismissed) {
      this.closeLightbox();
    } else {
      this.resetLbDrag();
      this.cdr.markForCheck();
    }
  }

  private resetLbDrag(): void {
    this.lbDragY.set(0);
    this.lbBackdropOpacity.set(1);
    this.lbDrag = { active: false, dir: '', startX: 0, startY: 0 };
  }

  colors: string[] = [];
  images: string[] = [];
  apiSizes = {};
  chosenSize: string | null = null;

  /** PDP info tabs, 'description' (default) vs 'reviews'. */
  activeTab: 'description' | 'reviews' = 'description';

  /** Switch the active PDP info tab (Description / Reviews). */
  setTab(tab: 'description' | 'reviews'): void {
    this.activeTab = tab;
    // Lazy-load the review list the first time the Reviews tab is opened.
    if (tab === 'reviews' && !this.reviewsLoadedOnce) {
      this.loadReviews(true);
    }
  }

  // ========================================
  // Reviews, READ side
  // ========================================

  /** How many reviews to fetch per page (initial + each "load more"). */
  private readonly reviewsPageSize = 10;

  /** Loaded approved reviews (accumulated across "load more" pages). */
  reviews: ProductReview[] = [];
  reviewsTotal = 0;
  reviewsHasMore = false;
  reviewsLoading = false;
  /** Becomes true after the first fetch so the empty state only shows post-load. */
  reviewsLoadedOnce = false;

  /** The v3 numeric product id used for the review endpoints. detailShape
   *  surfaces the v3 PK as top-level `id`, which the response transform maps
   *  onto `single.product`. Guarded so calls never fire with 0. */
  private get reviewProductId(): number {
    return Number(this.single.product) || 0;
  }

  /**
   * Fetch a page of approved reviews. `reset` clears the accumulated list
   * (initial load); otherwise it appends the next page ("load more").
   */
  async loadReviews(reset = false): Promise<void> {
    const productId = this.reviewProductId;
    if (!productId || this.reviewsLoading) {
      this.reviewsLoadedOnce = true;
      this.cdr.markForCheck();
      return;
    }
    if (reset) {
      this.reviews = [];
      this.reviewsTotal = 0;
      this.reviewsHasMore = false;
    }
    this.reviewsLoading = true;
    this.cdr.markForCheck();

    try {
      const page = await this.reviewService.list(productId, {
        limit: this.reviewsPageSize,
        offset: this.reviews.length,
      });
      this.reviews = reset ? page.reviews : [...this.reviews, ...page.reviews];
      this.reviewsTotal = page.total;
      this.reviewsHasMore = page.hasMore;
    } catch {
      // Leave whatever is already loaded; show the empty state if nothing.
    } finally {
      this.reviewsLoading = false;
      this.reviewsLoadedOnce = true;
      this.cdr.markForCheck();
    }
  }

  /** Append the next page of reviews. */
  loadMoreReviews(): void {
    void this.loadReviews(false);
  }

  /** Average star rating across the loaded reviews (0 when none). */
  get reviewsAverage(): number {
    if (this.reviews.length === 0) return 0;
    const sum = this.reviews.reduce((acc, r) => acc + (Number(r.star) || 0), 0);
    return sum / this.reviews.length;
  }

  /** Star fill states for an aggregate or per-review rating (rounds to the
   *  nearest whole star, the icon set has no half-star glyph). */
  starStates(rating: number): Array<'full' | 'empty'> {
    const filled = Math.round(rating);
    const out: Array<'full' | 'empty'> = [];
    for (let i = 1; i <= 5; i++) out.push(i <= filled ? 'full' : 'empty');
    return out;
  }

  /** Localised, RTL-safe formatted review date. */
  formatReviewDate(iso: string): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    const locale = this.i18n.lang === 'ar' ? 'ar' : 'en';
    try {
      return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      }).format(d);
    } catch {
      return d.toISOString().slice(0, 10);
    }
  }

  // ========================================
  // Reviews, WRITE side
  // ========================================

  isReviewSheetOpen = false;
  reviewSubmitting = false;
  reviewForm = { star: 0, title: '', comment: '' };

  /** Open the "Write a review" sheet, auth-gated (guests prompted to sign in). */
  openReviewSheet(): void {
    if (this.isGuest || !this.single_user.token) {
      this.error_notification(this.i18n.t('review_sign_in_to_review'));
      this.router.navigate(['/', 'login']);
      return;
    }
    this.reviewForm = { star: 0, title: '', comment: '' };
    this.isReviewSheetOpen = true;
    this.cdr.markForCheck();
  }

  /** Set the chosen star rating in the write-review form. */
  setReviewStar(star: number): void {
    this.reviewForm.star = star;
    this.cdr.markForCheck();
  }

  /** Submit the review (upsert, lands PENDING moderation), then refresh. */
  async submitReview(): Promise<void> {
    if (this.reviewSubmitting) return;
    if (this.isGuest || !this.single_user.token) {
      this.error_notification(this.i18n.t('review_sign_in_to_review'));
      this.router.navigate(['/', 'login']);
      return;
    }
    if (this.reviewForm.star < 1 || this.reviewForm.star > 5) {
      this.error_notification(this.i18n.t('review_select_rating'));
      return;
    }
    const productId = this.reviewProductId;
    if (!productId) {
      this.error_notification(this.i18n.t('text_something_went_wrong'));
      return;
    }

    this.reviewSubmitting = true;
    this.cdr.markForCheck();
    try {
      const ok = await this.reviewService.submit(this.single_user.token, productId, {
        star: this.reviewForm.star,
        title: this.reviewForm.title,
        comment: this.reviewForm.comment,
      });
      if (ok) {
        this.success_notification(this.i18n.t('review_submitted_for_approval'));
        this.isReviewSheetOpen = false;
        this.reviewForm = { star: 0, title: '', comment: '' };
        // Refresh the (approved-only) list; the new review stays hidden until
        // moderated, but any concurrently-approved reviews show up.
        void this.loadReviews(true);
      } else {
        this.error_notification(this.i18n.t('review_submit_failed'));
      }
    } catch (err) {
      this.error_notification(apiErrorMessage(err, this.i18n.t('review_submit_failed')));
    } finally {
      this.reviewSubmitting = false;
      this.cdr.markForCheck();
    }
  }

  single = {
    id: 0,
    token: "",
    product: 0,
    store: 0,
    // Vendor slug, the size guide is fetched by slug (legacy ids discarded).
    vendor_slug: "",
    store_name: "",
    category_id: "",
    category_slug: "",
    category_name: "",
    name: "",
    description: "",
    image_1: "assets/img/placeholder-1.png",
    images: [] as string[],
    collection: {},
    quantity: 0,
    allow_checkout_when_out_of_stock: false,
    with_storehouse_management: false,
    stock_status: "in_stock",
    sale_price: 0,
    price: 0,
    price_formated: "",
    minimum_order_quantity: 1,
    maximum_order_quantity: 1,
    height: 0,
    weight: 0,
    wide: 0,
    length: 0,
    cost_per_item: 0,
    delivery_time: "",
    custom_delivery_time: "",
    size_xs: false,
    size_s: false,
    size_m: false,
    size_l: false,
    size_xl: false,
    size_xxl: false,
    size_50: false,
    size_51: false,
    size_52: false,
    size_53: false,
    size_54: false,
    size_55: false,
    size_56: false,
    size_57: false,
    size_58: false,
    size_59: false,
    size_60: false,
    size_61: false,
    size_62: false,
    size_63: false,
    size_64: false,
    require_extra_msmt: false,
    extra_msmt: "",
    size_custom: false,
    size_normal: false,
    is_hot: false,
    is_new: false,
    is_sale: false,
    is_featured: false,
    delivery_note: "",
    colors: "",
    try_on_active: false,
    label: 0
  };

  /** True for BAGS / ACCESSORIES products, which have no size/color/size-chart.
   *  The v3 detail transform hardcodes category_id to 0, so we can't guard on
   *  it; category_slug (e.g. "bags-4", "accessories-5") is populated instead -
   *  strip the trailing "-<id>" and match the category name. */
  get isBagOrAccessory(): boolean {
    const c = String(this.single?.category_slug ?? "").toLowerCase().replace(/-\d+$/, "");
    return c === "bags" || c === "accessories";
  }

  /** Categories where size + colour are OPTIONAL even when the vendor DID set
   *  them, mirrors the server's isSizeOptionalCategory()
   *  (bags/accessories/kaftans/mukhawars). Bags/accessories carry no
   *  size/colour at all; mukhawars/kaftans may, but must never FORCE the
   *  choice. Guarded on category_slug because v3's detail transform zeroes
   *  category_id. */
  get isSizeColorOptionalCategory(): boolean {
    const c = String(this.single?.category_slug ?? "").toLowerCase().replace(/-\d+$/, "");
    return c === "bags" || c === "accessories" || c === "kaftans" || c === "mukhawars";
  }

  /** True only when the vendor actually enabled at least one ready size -
   *  mirrors app-size-chips, which renders only the truthy apiSizes keys. A
   *  product with NO sizes set must never force a size (it would silently kill
   *  the sale). */
  get hasSizes(): boolean {
    return Object.values(this.apiSizes || {}).some((v) => !!v);
  }

  /** True when the vendor offers at least one READY (non-custom) size, so ready
   *  chips + the size chart make sense alongside any Custom option. */
  get hasReadySizes(): boolean {
    return Object.entries(this.apiSizes || {})
      .some(([k, v]) => !!v && k.toUpperCase() !== 'CUSTOM');
  }

  /** True when the shopper has picked the made-to-measure CUSTOM chip. "Custom"
   *  is a per-SELECTION state (like the web PDP), not a product-level flag, a
   *  product can offer S/M/L AND Custom, and only picking Custom collects body
   *  measurements. */
  get isCustomSelected(): boolean {
    return String(this.add_cart.size ?? '').toUpperCase() === 'CUSTOM';
  }

  /** True only when the vendor set at least one colour, mirrors the colour
   *  pills (rendered only when colors.length > 0). No colours -> never force a
   *  colour. */
  get hasColors(): boolean {
    return this.colors.length > 0;
  }

  bill = {
    count: 0,
    discount: 0,
    delivery: 0,
    subtotal: 0,
    total: 0,
    f_discount: "",
    f_delivery: "",
    f_subtotal: "",
    f_total: ""
  };

  update = {
    id: 0,
    token: '',
    bust: 0,
    armhole: 0,
    shoulder: 0,
    length: 0,
    hip: 0,
    arm: 0
  };

  ui_controls = {
    is_loading: true,
    is_creating: false,
    is_adding_to_cart: false,
    is_loading_measurement: false,
    is_empty: false,
    // True when get_single resolves to a 404 / non-200 (deleted or unknown
    // product). Gates the not-found empty state; suppresses the normal
    // content + footer so a missing product never shows placeholders.
    product_missing: false
  };

  process_controls = {
    is_custom: false,
    confirmed_measurement: true
  };

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
  };

  rqst_param = {
    id: 0,
    token: "",
    product: 0,
    product_name: ""
  };

  add_cart = {
    id: 0,
    token: "",
    cart_code: "PND",
    store: 0,
    discount: 0,
    product_id: 0,
    product_name: "",
    product_desc: "",
    product_image: "",
    customer_name: "",
    customer_email: "",
    quantity: 1,
    price: 0,
    size: "",
    color: "",
    is_custom: false,
    measurement: "",
    extra_measurement: "",
    note: ""
  };

  // ========================================
  // Color Pill Methods
  // ========================================

  selectColor(color: string) {
    this.add_cart.color = color;
    const colorOption = this.getColorById(color);
    this.selectedHex = colorOption ? colorOption.hex : 'transparent';
    this.cdr.markForCheck();
  }

  getColorById(id: string): ColorOption | undefined {
    const normalizedId = id.toLowerCase().trim();
    return this.colorOptions.find(color =>
      color.id === normalizedId ||
      color.text.toLowerCase() === normalizedId
    );
  }

  getColorHex(colorId: string): string {
    const color = this.getColorById(colorId);
    return color ? color.hex : this.generateColorFromName(colorId);
  }

  getColorLabel(colorId: string): string {
    const color = this.getColorById(colorId);
    return color ? color.text : this.capitalizeWords(colorId);
  }

  isLightColor(colorId: string): boolean {
    return this.lightColors.includes(colorId.toLowerCase().trim());
  }

  private capitalizeWords(str: string): string {
    return str
      .replace(/-/g, ' ')
      .replace(/_/g, ' ')
      .split(' ')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
      .join(' ');
  }

  private generateColorFromName(colorName: string): string {
    // Generate a consistent color from color name for unknown colors
    const name = colorName.toLowerCase().trim();
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash % 360);
    return `hsl(${hue}, 60%, 50%)`;
  }

  // ========================================
  // Size Methods
  // ========================================

  onSizeSelected(sizeKey: string | any) {
    this.add_cart.size = sizeKey ?? '';
    // "Custom" tracks the SELECTED size, not the product: picking the CUSTOM
    // chip enters made-to-measure (and opens the sheet); any ready size leaves
    // it. Deselecting (null) clears both.
    const custom = String(sizeKey ?? '').toUpperCase() === 'CUSTOM';
    this.add_cart.is_custom = custom;
    this.process_controls.is_custom = custom;
    if (custom) {
      this.openMeasurement();
    }
    this.cdr.markForCheck();
  }

  selectStoreSize(measurement: StoreMeasurement) {
    this.add_cart.size = measurement.size;
    this.add_cart.measurement = JSON.stringify(measurement);
    this.cdr.markForCheck();
  }

  openMeasurement() {
    this.get_measurement();
    this.isMeasureOpen = true;
    this.cdr.markForCheck();
  }

  // ========================================
  // Image Loading
  // ========================================

  imgLoaded: boolean[] = new Array(10).fill(false);

  onWillLoad(index: number) {
    this.imgLoaded[index] = false;
  }

  onDidLoad(index: number) {
    this.imgLoaded[index] = true;
    this.cdr.markForCheck();
  }

  // ========================================
  // Quantity Control
  // ========================================

  increaseQuantity() {
    this.add_cart.quantity++;
    this.cdr.markForCheck();
  }

  decreaseQuantity() {
    if (this.add_cart.quantity > 1) {
      this.add_cart.quantity--;
      this.cdr.markForCheck();
    }
  }

  // ========================================
  // Navigation
  // ========================================

  triggerBack() {
    this.nav.back();
  }

  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }

  user_messages() {
    this.router.navigate(['/', 'chat-vendors']);
  }

  openCart() {
    this.router.navigate(['/', 'cart']);
  }

  onDismiss() {
    this.isMeasureOpen = false;
  }

  // ========================================
  // API Methods
  // ========================================

  ionViewDidEnter() {
    this.load_cart();
    // Keep the reactive cart badge in sync when the PDP is shown.
    void this.cartCount.refresh();
  }

  // Track guest mode. When true, addToCart shows a friendly login prompt
  // instead of POSTing /v3/cart/items. Guests can browse and see prices, but
  // adding to cart (and wishlist) requires signing in or signing up.
  isGuest = false;

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      // Guest mode, show the product and prices. Authenticated-only
      // features (add to cart, measurement save, wishlist add) are gated
      // behind a friendly sign-in / sign-up prompt.
      this.isGuest = true;
      this.get_single();
    } else {
      this.isGuest = false;
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id;
      this.rqst_param.token = this.single_user.token;
      this.get_measurement();
      this.get_single();
      this.add_cart.id = this.single_user.id;
      this.add_cart.token = this.single_user.token;
      this.add_cart.customer_name = this.single_user.first_name + " " + this.single_user.last_name;
      this.add_cart.customer_email = this.single_user.email;
    }
  }

  get_single() {
    this.ui_controls.is_loading = true;
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products/by-legacy-id/:id). The single_product
    // request transform put the legacy `product` id into the {id} path
    // param (transformSingleProductRequest); replicate that here. The
    // response transform (transformProductDetailResponse) still applies
    // via get_v3, so response.data keeps the legacy detail shape. Public
    // catalog read, no authToken.
    this.networkAdapter.get_v3('GET /mobile/single-product', {
      pathParams: { id: String(this.rqst_param.product) },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.single = response.data;
            // Clear the loading / not-found gates IMMEDIATELY on a successful
            // response, BEFORE the size/measurement processing below, so a
            // throw anywhere in that processing can never leave the skeleton
            // stuck on and hide the product card + footer (price / add-to-cart).
            this.ui_controls.is_loading = false;
            this.ui_controls.product_missing = false;
            this.cdr.markForCheck();
            // Parse colors and normalize them
            this.colors = response.data.colors
              ? response.data.colors.split(',').map((c: string) => c.trim().toLowerCase())
              : [];
            // v3's transformProductDetailResponse emits `images` as a
            // comma-joined string (imagesAsCsvString); split it into the URL
            // array the gallery @for iterates, same handling as `colors` above.
            // Without this, @for(image of images) iterates the string's chars.
            this.images = response.data.images
              ? String(response.data.images).split(',').map((s: string) => s.trim()).filter(Boolean)
              : [];
            this.add_cart.product_id = this.single.product;
            this.add_cart.product_name = this.single.name;
            this.add_cart.product_desc = this.single.description;
            this.add_cart.product_image = this.single.image_1;
            this.add_cart.price = this.single.price;
            this.add_cart.store = this.single.store;
            this.get_store_measurement();
            this.apiSizes = {
              'NORMAL': this.single.size_normal,
              'xs': this.single.size_xs,
              's': this.single.size_s,
              'm': this.single.size_m,
              'l': this.single.size_l,
              'xl': this.single.size_xl,
              'xxl': this.single.size_xxl,
              '50': this.single.size_50,
              '51': this.single.size_51,
              '52': this.single.size_52,
              '53': this.single.size_53,
              '54': this.single.size_54,
              '55': this.single.size_55,
              '56': this.single.size_56,
              '57': this.single.size_57,
              '58': this.single.size_58,
              '59': this.single.size_59,
              '60': this.single.size_60,
              '61': this.single.size_61,
              '62': this.single.size_62,
              '63': this.single.size_63,
              '64': this.single.size_64,
              // CUSTOM last so its chip renders after the ready sizes (a product
              // can offer BOTH ready sizes and made-to-measure).
              'CUSTOM': this.single.size_custom,
            };
            // Only a CUSTOM-ONLY product starts in custom mode; when ready sizes
            // are also offered, "custom" is decided by what the shopper picks.
            if (this.single.size_custom && !this.hasReadySizes) {
              this.add_cart.size = 'CUSTOM';
              this.add_cart.is_custom = true;
              this.process_controls.is_custom = true;
            }
            this.ui_controls.is_loading = false;
            this.ui_controls.product_missing = false;
            this.cdr.markForCheck();
          } else {
            // Non-200 (e.g. 404 for a deleted/unknown product). Stop the
            // skeleton and show the not-found empty state instead of
            // spinning forever / rendering placeholder fields.
            this.ui_controls.is_loading = false;
            this.ui_controls.product_missing = true;
            this.cdr.markForCheck();
          }
          this.load_cart();
        },
        error: () => {
          // Network/HTTP error (the v3 404 surfaces here for some transports).
          // Same outcome as the non-200 branch: stop loading, show empty state.
          this.ui_controls.is_loading = false;
          this.ui_controls.product_missing = true;
          this.cdr.markForCheck();
        }
      });
  }

  get_measurement() {
    this.ui_controls.is_loading_measurement = true;
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/me/measurements). Authed (derives the user from
    // the JWT) so authToken is required. The transformMeasurementsReadResponse
    // response transform still applies via get_v3, so response.data keeps the
    // legacy [{...flat numeric values}] shape this page reads. v3 values are
    // numbers, matching the numeric `update` fields here (unlike
    // measurements.page whose form fields are strings).
    this.networkAdapter.get_v3('GET /me/measurements', { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          const m = response.data?.[0];
          if (response.response_code === 200 && response.status === "success" && m) {
            this.update.bust = m.bust;
            this.update.armhole = m.armhole;
            this.update.shoulder = m.shoulder;
            this.update.length = m.length;
            this.update.hip = m.hip;
            this.update.arm = m.arm;
            this.add_cart.measurement = JSON.stringify(this.update);
          } else {
            this.ui_controls.is_empty = true;
          }
          this.ui_controls.is_loading_measurement = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading_measurement = false;
          this.cdr.markForCheck();
        }
      });
  }

  get_store_measurement() {
    // PUBLIC customer read of the store's published size guide, resolved by
    // SLUG (GET /v3/vendors/{slug}/size-chart). No authToken, shoppers view it.
    //
    // Legacy ids are discarded: a newly onboarded (v3-native) store has no
    // legacy id, so the old by-legacy-id fetch returned nothing and the size
    // guide was always empty for it. `vendor_slug` comes from v3's detailShape
    // vendor block. GUARD: no slug -> skip (empty "no size guide" state).
    const slug = String(this.single.vendor_slug ?? '');
    if (!slug) {
      return;
    }

    this.networkAdapter.get_v3('GET /vendors/:slug/size-chart', {
      pathParams: { slug },
    }).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === "success") {
          // v3 returns rows shaped { id, size, values: {bust, waist, hip,
          // length, neck, arm, armhole, shoulder, ...} }. The size-guide UI
          // binds FLAT fields (item.size, item.bust, ...), so flatten each
          // row's `values` map up to the top level (mirrors the legacy
          // wire shape this page used to read).
          const rows = Array.isArray(response.data) ? response.data : [];
          this.store_measurement = rows.map((row: any) => {
            const values = row && typeof row.values === 'object' && row.values !== null
              ? row.values
              : {};
            return {
              id: Number(row?.id ?? 0),
              token: "",
              measurement: 0,
              size: String(row?.size ?? ""),
              bust: Number(values.bust ?? 0),
              waist: Number(values.waist ?? 0),
              hip: Number(values.hip ?? 0),
              length: Number(values.length ?? 0),
              neck: Number(values.neck ?? 0),
              arm: Number(values.arm ?? 0),
              armhole: Number(values.armhole ?? 0),
              shoulder: Number(values.shoulder ?? 0),
            } as StoreMeasurement;
          });
          this.cdr.markForCheck();
        }
      }
    });
  }

  load_cart() {
    // Guests have no server cart; their cart lives in IndexedDB and the
    // "already in cart" indicator isn't shown for the guest add flow.
    // Skip the authed read entirely (no token to send).
    if (this.isGuest) {
      this.ui_controls.is_loading = false;
      return;
    }
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    // Direct v3 (GET /v3/cart). Authed, authToken required. The
    // transformCartListResponse response transform still applies via
    // get_v3, so response.data is the v3 {items, bill, ...} shape (NOT
    // the legacy array). Handle both shapes defensively, mirroring
    // cart.page.ts: legacy put the item array in `data` and the bill in
    // `message`; v3 puts {items, bill} in `data` and leaves `message` ''.
    this.networkAdapter.get_v3('GET /cart', { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200) {
            const data = response.data;
            if (Array.isArray(data)) {
              // Legacy shape
              this.product = data;
              this.bill = response.message;
            } else if (data && typeof data === 'object' && Array.isArray(data.items)) {
              // v3 shape (post-transform)
              this.product = data.items;
              this.bill = { ...this.bill, ...(data.bill ?? {}) };
            } else {
              this.product = [];
            }
            this.ui_controls.is_loading = false;
            this.itemExists = this.product.some((item: any) => item.product_id === this.single.product);
            this.cdr.markForCheck();
          }
        }
      });
  }

  addToCart() {
    if (this.add_cart.quantity === 0) {
      this.error_notification(this.i18n.t('text_quantity_required'));
      return;
    }

    // Size is required only when the category enforces it AND the vendor offers
    // sizes. CUSTOM is one of those size chips now, so a made-to-measure product
    // still needs a pick (the shopper taps CUSTOM). Bags/accessories/mukhawars/
    // kaftans never force a size; a product with NO sizes set must never be
    // blocked (that silently kills the sale).
    if (!this.isSizeColorOptionalCategory && this.hasSizes) {
      if (this.add_cart.size.length === 0) {
        this.error_notification(this.i18n.t('text_select_size'));
        return;
      }
    }

    // Colour is required only when the category enforces it AND the vendor set
    // colours. Optional categories, or a product with no colours, never block.
    if (!this.isSizeColorOptionalCategory && this.hasColors) {
      if (this.add_cart.color.length === 0) {
        this.error_notification(this.i18n.t('text_select_color'));
        return;
      }
    }

    // Made-to-measure (Custom size) products need the customer's BODY
    // measurements, this is the size_custom rule, independent of
    // require_extra_msmt, since not every custom product also asks for a
    // vendor-specific extra measurement. If nothing was auto-loaded from the
    // customer's profile (get_measurement on load), open the sheet so they
    // supply them instead of placing an unmakeable custom order.
    if (this.isCustomSelected && String(this.add_cart.measurement ?? '').trim().length === 0) {
      this.error_notification(this.i18n.t('text_provide_measurement'));
      this.openMeasurement();
      return;
    }

    // Made-to-measure products (require_extra_msmt) need the vendor's extra
    // measurement. The server rejects an empty value with a generic "one or
    // more fields failed validation", opaque, and for a ready-to-wear
    // mukhawar the measurement sheet's trigger isn't even shown, so the sale
    // is silently blocked. Catch it here with a clear prompt and open the
    // measurement sheet so the customer can supply it.
    if (this.single.require_extra_msmt && String(this.add_cart.extra_measurement ?? '').trim().length === 0) {
      this.error_notification(this.i18n.t('text_provide_measurement'));
      this.openMeasurement();
      return;
    }

    this.ui_controls.is_adding_to_cart = true;
    this.cdr.markForCheck();

    // Guest OR an invalid/empty session -> require login. Adding to cart needs
    // an account; show a friendly prompt and add nothing. A stale 'user' blob
    // with a dead token also lands here via the auth-failure branch in the
    // authed POST below, which shows the SAME prompt.
    if (this.isGuest || !this.single_user.token) {
      this.error_notification(this.i18n.t('sign_in_to_add_to_cart'));
      this.ui_controls.is_adding_to_cart = false;
      this.cdr.markForCheck();
      return;
    }

    // Direct v3 (POST /v3/cart/items). Authed, authToken required. Build
    // the body EXPLICITLY per AddCartItemInput (product_id, quantity, size,
    // color, is_custom, measurement, extra_measurement, note). The server
    // derives everything else (price, store, customer, product snapshot) -
    // the legacy display fields on `add_cart` are intentionally NOT sent.
    // extra_measurement is included for made-to-measure products
    // (require_extra_msmt); the server rejects an empty value for those.
    // The transformAddCartResponse response transform applies via post_v3,
    // so response.data = {success, count, cart}, handled below.
    const cartBody = {
      product_id: this.add_cart.product_id,
      quantity: this.add_cart.quantity,
      size: this.add_cart.size,
      color: this.add_cart.color,
      is_custom: this.add_cart.is_custom === true,
      measurement: this.add_cart.measurement,
      extra_measurement: this.add_cart.extra_measurement,
      note: this.add_cart.note,
    };
    this.networkAdapter.post_v3('POST /cart/items', cartBody, { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          // Dual-shape support during M3.1.6 strangler-fig migration.
          //   Legacy: response.response_code=200, response.status='success',
          //           response.message=<localised success text>
          //   v3:     response.response_code=200, response.status='success',
          //           response.message='', response.data={success, count, cart}
          const success =
            response.response_code === 200 &&
            (response.status === 'success' ||
              (response.data && typeof response.data === 'object' && response.data.success === true));

          if (success) {
            // Legacy: use server-supplied message; v3: fall back to i18n.
            const successText =
              typeof response.message === 'string' && response.message.length > 0
                ? response.message
                : this.i18n.t('text_added_to_cart');
            this.success_notification(successText);
            this.ui_controls.is_adding_to_cart = false;
            this.itemExists = true; // flip the CTA to "Already in cart, View"
            // Stay on the PDP (mirror web). Update the reactive cart-count
            // badge, the transformAddCartResponse transform exposes the new
            // total as response.data.count; publish it via the shared
            // CartCountService (replaces the write-only Preferences('count')).
            const newCount =
              response.data && typeof response.data === 'object' && response.data.count != null
                ? response.data.count
                : undefined;
            if (newCount !== undefined) {
              this.cartCount.setCount(Number(newCount));
            } else {
              // No authoritative count in the response, bump optimistically
              // and reconcile from the server.
              this.cartCount.bump(this.add_cart.quantity);
              void this.cartCount.refresh();
            }
          } else if (
            response.response_code === 401 ||
            response.error_code === 'AUTH_INVALID_TOKEN' ||
            (typeof response.error_code === 'string' && response.error_code.startsWith('AUTH_'))
          ) {
            // Stale/invalid session -> require login. Show the same friendly
            // prompt as the guest gate and add nothing.
            this.error_notification(this.i18n.t('sign_in_to_add_to_cart'));
            this.ui_controls.is_adding_to_cart = false;
          } else {
            this.ui_controls.is_empty = true;
            this.ui_controls.is_adding_to_cart = false;
            const errorText =
              typeof response.message === 'string' && response.message.length > 0
                ? response.message
                : this.i18n.t('text_something_went_wrong');
            this.error_notification(errorText);
          }
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_adding_to_cart = false;
          this.cdr.markForCheck();
        }
      });
  }

  update_measurement() {
    if (this.isOnline) {
      if (this.single.require_extra_msmt) {
        if (this.add_cart.extra_measurement.length === 0) {
          this.error_notification(this.i18n.t('text_provide_measurement'));
          return;
        }
      }
      this.update.id = this.single_user.id;
      this.update.token = this.single_user.token;
      this.ui_controls.is_loading_measurement = true;
      this.cdr.markForCheck();

      // Direct v3 (PUT /v3/me/measurements/default), SAME as
      // measurements.page.ts. v3 wants a numeric `values` map (cm, 0-500);
      // send only the fields the user filled. `update` fields are already
      // numbers here, so coerce + filter to positive values.
      const values: Record<string, number> = {};
      for (const k of ['bust', 'shoulder', 'armhole', 'length', 'hip', 'arm'] as const) {
        const n = Number(this.update[k]);
        // Clamp to the v3 range (cm, 0-500); drop out-of-range like the old
        // request transform did (>500 would 422 the whole save).
        if (Number.isFinite(n) && n > 0 && n <= 500) values[k] = n;
      }
      this.networkAdapter.put_v3('PUT /me/measurements/default', { values }, { authToken: this.single_user.token })
        .subscribe({
          next: (response: any) => {
            if (response.response_code === 200 && response.status === "success") {
              this.success_notification(this.i18n.t('text_measurement_confirmed'));
              this.ui_controls.is_loading_measurement = false;
              this.process_controls.confirmed_measurement = true;
              this.get_measurement();
              this.addToCart();
              this.isMeasureOpen = false;
            } else {
              this.ui_controls.is_loading_measurement = false;
              this.error_notification(response.message);
            }
            this.cdr.markForCheck();
          },
          error: (err: any) => {
            this.ui_controls.is_loading_measurement = false;
            this.error_notification(apiErrorMessage(err, this.i18n.t('text_unable_to_save_measurement')));
            this.cdr.markForCheck();
          }
        });
    } else {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
    }
  }

  // ========================================
  // Notifications
  // ========================================

  error_notification(message: string) {
    this.toast.error(message, { position: "top-center" });
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }
}
