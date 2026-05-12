import { Component, inject, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { CommonModule, Location } from '@angular/common';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { FormsModule } from '@angular/forms';

import { Category } from '../../class/category';
import { Labels } from '../../class/labels';

// Ax design-system components
import { AxRichEditorComponent } from '../../shared/rich/ax-rich-editor.component';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxMultiselectComponent,
  AxMultiselectOption,
} from '../../shared/forms/ax-multiselect.component';
import {
  AxAccordionComponent,
  AxAccordionItemComponent,
} from '../../shared/overlays';

interface ColorOption {
  id: string;
  text: string;
  hex: string;
}

@Component({
  selector: 'app-admin-view-product',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxRichEditorComponent,
    AxMultiselectComponent,
    AxAccordionComponent,
    AxAccordionItemComponent,
  ],
  templateUrl: './admin-view-product.component.html',
  styleUrl: './admin-view-product.component.css',
})
export class AdminViewProductComponent implements OnInit {
  category?: Category[];
  labels?: Labels[];

  /** Server list of collections. */
  dropdownList: { id: number; collection: string }[] = [];
  /** Ids selected by the AxMultiselect. */
  selectedCollectionIds: (string | number)[] = [];

  get collectionOptions(): AxMultiselectOption[] {
    return this.dropdownList.map(c => ({ id: c.id, label: c.collection }));
  }

  get selectedItemsForPayload(): { id: number; collection: string }[] {
    const ids = new Set(this.selectedCollectionIds.map(String));
    return this.dropdownList.filter(c => ids.has(String(c.id)));
  }

  private readonly confirm = inject(AxConfirmService);
  colorOptions: ColorOption[] = [];
  base64String: any;

  ui_controls = {
    is_loading: false,
    is_creating_label: false,
    page_loading: false,
    nav_open: false,
  };

  session_data: any = '';
  image_url: any = 'https://api.3bayti.ae/vendors/products/';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  update: any = {
    id: 0,
    token: '',
    product: 0,
    store: 0,
    category: 0,
    name: '',
    description: '',
    image_1: 'assets/img/placeholder-1.png',
    images: [] as string[],
    collection: {},
    quantity: 0,
    allow_checkout_when_out_of_stock: false,
    with_storehouse_management: false,
    stock_status: 'in_stock',
    price: 0,
    minimum_order_quantity: 1,
    maximum_order_quantity: 1,
    cost_per_item: 0,
    delivery_time: '',
    custom_delivery_time: '',
    size_xs: false, size_s: false, size_m: false, size_l: false,
    size_xl: false, size_xxl: false,
    size_50: false, size_52: false, size_54: false, size_56: false,
    size_58: false, size_60: false, size_62: false,
    require_extra_msmt: false,
    extra_msmt: '',
    size_custom: false,
    is_hot: false, is_new: false, is_sale: false, is_featured: false,
    delivery_note: '',
    colors: '',
    label: 0,
  };

  vendor_labels = { id: 0, token: '' };
  vendor_label_create = { id: 0, token: '', label: '' };
  single_product = { id: 0, product: 0, token: '' };

  selected = new Set<string>();
  trackById = (_: number, item: ColorOption) => item.id;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private crudService: CrudService,
    private location: Location,
    private toast: HotToastService,
  ) {}

  toggle(id: string, checked: boolean) {
    checked ? this.selected.add(id) : this.selected.delete(id);
  }

  isSelected(id: string): boolean {
    return this.selected.has(id);
  }

  get selectedColors(): ColorOption[] {
    return this.colorOptions.filter(c => this.selected.has(c.id));
  }

  needsBorder(hex: string): boolean {
    if (hex.startsWith('linear')) return false;
    const rgb = this.hexToRgb(hex);
    const brightness = 0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b;
    return brightness > 220;
  }

  private hexToRgb(hex: string) {
    const n = hex.replace('#', '');
    const bigint = parseInt(n.length === 3 ? n.split('').map(c => c + c).join('') : n, 16);
    return { r: (bigint >> 16) & 255, g: (bigint >> 8) & 255, b: bigint & 255 };
  }

  getSelectedIdsCsv(delimiter = ','): string {
    return [...this.selected].map(String).join(delimiter);
  }

  getImageUrl(src: string): string {
    if (!src) return 'assets/img/placeholder-1.png';
    return src.length > 100 ? src : this.image_url + src;
  }

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    const productId = Number(this.route.snapshot.queryParamMap.get('id'));

    this.update.id = this.user_session.id;
    this.update.token = this.user_session.token;
    this.update.product = productId;

    this.single_product.id = this.user_session.id;
    this.single_product.token = this.user_session.token;
    this.single_product.product = productId;

    this.vendor_labels.id = this.user_session.id;
    this.vendor_labels.token = this.user_session.token;
    this.vendor_label_create.id = this.user_session.id;
    this.vendor_label_create.token = this.user_session.token;

    this.get_product_by_id();
    this.get_category();
    this.get_collections();
    this.get_vendor_labels();

    this.colorOptions = [
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
      { id: 'gold', text: 'Gold (Metallic)', hex: '#D4AF37' },
      { id: 'silver', text: 'Silver (Metallic)', hex: '#C0C0C0' },
      { id: 'bronze', text: 'Bronze', hex: '#CD7F32' },
      { id: 'champagne', text: 'Champagne', hex: '#F7E7CE' },
      { id: 'ivory', text: 'Ivory', hex: '#FFFFF0' },
      { id: 'multicolor', text: 'Multicolor', hex: 'linear-gradient(90deg, red, orange, yellow, green, blue, indigo, violet)' },
    ];
  }

  goBack() {
    this.location.back();
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  updateProduct() {
    this.update.colors = this.getSelectedIdsCsv();
    this.update.collection = this.selectedItemsForPayload;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.update, GlobalComponent.updateProduct).subscribe({
      next: (response: any) => {
        this.ui_controls.is_loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
        } else if ((response.response_code === 200 || response.response_code === 400) && response.status === 'failed') {
          this.error_notification(response.message);
        }
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
      },
    });
  }

  create_vendor_labels() {
    if (this.vendor_label_create.label.length === 0) {
      this.error_notification('Label name is required');
      return;
    }
    this.ui_controls.is_creating_label = true;
    this.crudService.post_request(this.vendor_label_create, GlobalComponent.createLabel).subscribe({
      next: (response: any) => {
        this.ui_controls.is_creating_label = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.vendor_label_create.label = '';
          this.success_notification(response.message);
          this.get_vendor_labels();
        } else if ((response.response_code === 200 || response.response_code === 400) && response.status === 'failed') {
          this.error_notification(response.message);
        }
      },
      error: (e: any) => {
        console.error(e);
        this.ui_controls.is_creating_label = false;
        this.error_notification('Unable to complete your request at this time.');
      },
    });
  }

  select_image_1(event: any) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onloadend = () => {
      this.base64String = reader.result as string;
      this.update.image_1 = this.base64String;
    };
    reader.readAsDataURL(file);
  }

  get_category() {
    this.ui_controls.page_loading = true;
    this.crudService.get_request(GlobalComponent.UtilityCategory).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.category = response.data;
          this.get_vendor_labels();
        }
      },
    });
  }

  get_vendor_labels() {
    this.crudService.post_request(this.vendor_labels, GlobalComponent.readLabel).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.labels = response.data;
        }
      },
    });
  }

  get_collections() {
    this.ui_controls.page_loading = true;
    this.crudService.get_request(GlobalComponent.UtilityCollections).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.dropdownList = response.data;
          this.ui_controls.page_loading = false;
        }
      },
    });
  }

  get_product_by_id() {
    this.crudService.post_request(this.single_product, GlobalComponent.getProductById).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.update = response.data;

          // Seed multiselect from server shape [{id, collection}]
          const serverCollection = response.data.collection ?? [];
          this.selectedCollectionIds = Array.isArray(serverCollection)
            ? serverCollection.map((c: any) => c.id)
            : [];

          // Restore colours CSV into Set
          for (const item of (this.update.colors || '').split(',').map((s: string) => s.trim()).filter(Boolean)) {
            this.selected.add(item);
          }
          this.ui_controls.page_loading = false;
        }
      },
    });
  }

  start_update() {
    this.confirm
      .confirm({
        title: 'Confirm update',
        message: 'Save your changes. Your product update will go live immediately.',
        confirmLabel: 'Save',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.updateProduct();
      });
  }
}
