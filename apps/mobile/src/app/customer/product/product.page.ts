import {
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  OnInit,
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
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { SizeChipsComponent } from "../../size-chips/size-chips.component";
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from "../../translate.pipe";
import { CartCountService } from '../../core/services/cart-count.service';
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
export class ProductPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  store_measurement: StoreMeasurement[] = [];
  product: Products[] = [];
  @ViewChild('swiper', { static: true }) swiperEl!: ElementRef<HTMLElement>;
  index = signal(0);
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
    this.initSwiper();
  }

  ngOnDestroy() {
    this.sub?.unsubscribe();
  }

  private initSwiper() {
    const el = this.swiperEl.nativeElement as any;
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

  colors: string[] = [];
  images: string[] = [];
  apiSizes = {};
  chosenSize: string | null = null;

  single = {
    id: 0,
    token: "",
    product: 0,
    store: 0,
    store_name: "",
    category_id: "",
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
    is_empty: false
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
    this.add_cart.size = sizeKey;
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
      // Guest mode — show the product and prices. Authenticated-only
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
    // catalog read — no authToken.
    this.networkAdapter.get_v3('GET /mobile/single-product', {
      pathParams: { id: String(this.rqst_param.product) },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.single = response.data;
            // Parse colors and normalize them
            this.colors = response.data.colors
              ? response.data.colors.split(',').map((c: string) => c.trim().toLowerCase())
              : [];
            // v3's transformProductDetailResponse emits `images` as a
            // comma-joined string (imagesAsCsvString); split it into the URL
            // array the gallery @for iterates — same handling as `colors` above.
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
              'CUSTOM': this.single.size_custom,
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
            };
            if (this.single.size_custom) {
              this.process_controls.is_custom = true;
            }
            this.ui_controls.is_loading = false;
            this.cdr.markForCheck();
          }
          this.load_cart();
        },
        error: () => {
          this.ui_controls.is_loading = false;
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
    // PUBLIC customer read of the store's published size guide via the v3
    // by-legacy-id endpoint (GET /v3/vendors/by-legacy-id/{store}/size-chart,
    // StoreSizeChartByLegacyIdController). No authToken — shoppers view it.
    //
    // `this.single.store` is the LEGACY store id now surfaced by v3's
    // detailShape vendor block (mapped in transformProductDetailResponse).
    // GUARD: if it's falsy/0 there's no store to resolve, so skip the call
    // and leave store_measurement empty (the size-guide sheet shows the
    // "no size guide" empty state).
    const storeId = Number(this.single.store);
    if (!storeId) {
      return;
    }

    this.networkAdapter.get_v3('GET /vendors/:vendorId/size-chart', {
      pathParams: { vendorId: String(storeId) },
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
    // Direct v3 (GET /v3/cart). Authed — authToken required. The
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

    if (!this.single.size_custom) {
      if (this.single.category_id !== "4" && this.single.category_id !== "5" && this.single.category_id !== "2") {
        if (this.add_cart.size.length === 0) {
          this.error_notification(this.i18n.t('text_select_size'));
          return;
        }
      }
    }

    if (this.single.category_id !== "5") {
      if (this.add_cart.color.length === 0) {
        this.error_notification(this.i18n.t('text_select_color'));
        return;
      }
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

    // Direct v3 (POST /v3/cart/items). Authed — authToken required. Build
    // the body EXPLICITLY per AddCartItemInput (product_id, quantity, size,
    // color, is_custom, measurement, extra_measurement, note). The server
    // derives everything else (price, store, customer, product snapshot) —
    // the legacy display fields on `add_cart` are intentionally NOT sent.
    // extra_measurement is included for made-to-measure products
    // (require_extra_msmt); the server rejects an empty value for those.
    // The transformAddCartResponse response transform applies via post_v3,
    // so response.data = {success, count, cart} — handled below.
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
            this.itemExists = true; // flip the CTA to "Already in cart — View"
            // Stay on the PDP (mirror web). Update the reactive cart-count
            // badge — the transformAddCartResponse transform exposes the new
            // total as response.data.count; publish it via the shared
            // CartCountService (replaces the write-only Preferences('count')).
            const newCount =
              response.data && typeof response.data === 'object' && response.data.count != null
                ? response.data.count
                : undefined;
            if (newCount !== undefined) {
              this.cartCount.setCount(Number(newCount));
            } else {
              // No authoritative count in the response — bump optimistically
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

      // Direct v3 (PUT /v3/me/measurements/default) — SAME as
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
          error: () => {
            this.ui_controls.is_loading_measurement = false;
            this.error_notification(this.i18n.t('text_unable_to_save_measurement'));
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
