import {
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  OnInit,
  signal,
  ViewChild
} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonCard,
  IonCardContent,
  IonContent,
  IonImg,
  NavController
} from '@ionic/angular/standalone';
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {Platform} from "@ionic/angular";
import {ActivatedRoute, Router} from "@angular/router";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
@Component({
  selector: 'app-single',
  templateUrl: './single.page.html',
  styleUrls: ['./single.page.scss'],
  standalone: true,
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  imports: [
    IonContent,
    IonButton,
    IonCard,
    IonCardContent,
    IonImg,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ]
})
export class SinglePage implements OnInit {
  @ViewChild('swiper', { static: true }) swiperEl!: ElementRef<HTMLElement>;
  index = signal(0);
  isOnline = true;
  private sub: Subscription;
  thumbSize = 65;
  visibleCount = 3;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.platform.backButton.subscribeWithPriority(10, () => {
    });
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnInit() {
    this.product.product = Number(this.route.snapshot.queryParamMap.get('id'));
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
      });
    };
    attach();
    this.get_single();
  }
  colors: string[] = [];
  images: string[] = [];
  apiSizes = { };
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
    size_52: false,
    size_54: false,
    size_56: false,
    size_58: false,
    size_60: false,
    size_62: false,
    require_extra_msmt: false,
    extra_msmt: "",
    size_custom: false,
    is_hot: false,
    is_new: false,
    is_sale: false,
    is_featured: false,
    delivery_note: "",
    colors: "",
    try_on_active: false,
    label: 0
  };
  ui_controls = {
    is_loading: true,
    is_creating: false,
    is_adding_to_cart: false,
    is_loading_measurement: false,
    is_empty: false
  }

  store_m = {
    store: 0
  }
product = {
    product: 0
}
  get_single() {
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.product, GlobalComponent.singleProductUtility)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.single = response.data;
            this.colors = response.data.colors.split(',');
            this.images = response.data.images.split(',');
            this.store_m.store = this.single.store;
            this.apiSizes = {
              'xs': this.single.size_xs,
              's': this.single.size_s,
              'm': this.single.size_m,
              'l': this.single.size_l,
              'xl': this.single.size_xl,
              'xxl': this.single.size_xxl,
              '50': this.single.size_50,
              '52': this.single.size_52,
              '54': this.single.size_54,
              '56': this.single.size_56,
              '58': this.single.size_58,
              '60': this.single.size_60,
              '62': this.single.size_62,
            };
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  imgLoaded: boolean[] = [false, false, false, false];
  onWillLoad(index: number) {
    this.imgLoaded[index] = false;
  }
  onDidLoad(index: number) {
    this.imgLoaded[index] = true;
  }
  triggerBack() {
    this.nav.back();
  }
  SignIn() {
    this.show_error(this.i18n.t('text_signin_to_continue'));
    this.router.navigate(['/', 'login']);
  }
  show_error(message: string) {
    this.toast.error(message, {
      position: 'top-center'
    });
  }
  show_success(message: string, position: any) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }
}
