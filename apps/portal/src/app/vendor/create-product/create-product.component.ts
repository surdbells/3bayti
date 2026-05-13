import { Component, inject, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { Category } from '../../class/category';
import { Labels } from '../../class/labels';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import imageCompression from 'browser-image-compression';

// Ax design-system components (Phase 2, 3, 5)
import { AxRichEditorComponent } from '../../shared/rich/ax-rich-editor.component';
import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxFileUploadComponent,
  AxUploadFile,
} from '../../shared/rich/ax-file-upload.component';
import {
  AxMultiselectComponent,
  AxMultiselectOption,
} from '../../shared/forms/ax-multiselect.component';
import {
  AxAccordionComponent,
  AxAccordionItemComponent,
} from '../../shared/overlays';

// ── Shared types ─────────────────────────────────────────────────
interface ColorOption {
  id: string;
  text: string;
  hex: string;
}

type EncodedFile = {
  file: File;
  name: string;
  type: string;
  size: number;
  dataUrl: string;
  base64: string;
};

// ── Color palette (full list) ────────────────────────────────────
const COLOR_OPTIONS: ColorOption[] = [
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
];

// Sized categories — only show sizing controls for these
const SIZED_CATEGORIES = [1, 2, 3, 6, 7];

@Component({
  selector: 'app-create-product',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxRichEditorComponent,
    AxFileUploadComponent,
    AxMultiselectComponent,
    AxAccordionComponent,
    AxAccordionItemComponent,
  ],
  templateUrl: './create-product.component.html',
  styleUrl: './create-product.component.css',
})
export class CreateProductComponent implements OnInit {
  // ── Lookup data ────────────────────────────────────────────────
  category?: Category[];
  labels?: Labels[];
  colorOptions: ColorOption[] = COLOR_OPTIONS;

  /** Server list of collections ({ id, collection }). */
  dropdownList: { id: number; collection: string }[] = [];
  /** Ids selected by the AxMultiselect. */
  selectedCollectionIds: (string | number)[] = [];

  /** Options shape consumed by AxMultiselect (derived from dropdownList). */
  get collectionOptions(): AxMultiselectOption[] {
    return this.dropdownList.map(c => ({ id: c.id, label: c.collection }));
  }

  /** Mapped back to {id, collection} for the create payload. */
  get selectedItemsForPayload(): { id: number; collection: string }[] {
    const ids = new Set(this.selectedCollectionIds.map(String));
    return this.dropdownList.filter(c => ids.has(String(c.id)));
  }

  // ── Image handling ─────────────────────────────────────────────
  /** AxFileUpload featured-image value (single). */
  featuredFiles: AxUploadFile[] = [];
  /** AxFileUpload gallery value (up to 5). */
  galleryFiles: AxUploadFile[] = [];
  /** Encoded gallery (base64 dataUrls) sent to backend. */
  private galleryEncoded: EncodedFile[] = [];

  // ── Color selection ────────────────────────────────────────────
  selected = new Set<string>();

  // ── Session ────────────────────────────────────────────────────
  private readonly confirm = inject(AxConfirmService);
  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  // ── UI controls ────────────────────────────────────────────────
  ui = {
    loading: false,
    creating_label: false,
    page_loading: false,
  };

  // ── Form model ─────────────────────────────────────────────────
  create = {
    id: 0, token: '',
    category: 0, name: '', description: '',
    image_1: 'assets/img/placeholder-1.png',
    images: [] as string[],
    collection: {} as any,
    quantity: 0,
    allow_checkout_when_out_of_stock: false,
    with_storehouse_management: false,
    stock_status: 'in_stock',
    price: 0, cost_per_item: 0,
    minimum_order_quantity: 1, maximum_order_quantity: 1,
    delivery_time: '', custom_delivery_time: '',
    delivery_note: '',
    // Sizes — letter
    size_xs: false, size_s: false, size_m: false,
    size_l: false, size_xl: false, size_xxl: false,
    // Sizes — numeric
    size_50: false, size_51: false, size_52: false, size_53: false,
    size_54: false, size_55: false, size_56: false, size_57: false,
    size_58: false, size_59: false, size_60: false, size_61: false,
    size_62: false, size_63: false, size_64: false,
    size_custom: false,
    require_extra_msmt: false, extra_msmt: '',
    is_featured: false,
    is_hot: false, is_new: false, is_sale: false,
    colors: '', status: '', label: 0,
  };

  // ── Label create model ─────────────────────────────────────────
  vendor_labels = { id: 0, token: '' };
  vendor_label_create = { id: 0, token: '', label: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  // ═══════════════════════════════════════════════════════════════
  //  LIFECYCLE
  // ═══════════════════════════════════════════════════════════════
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    this.create.id = this.user_session.id;
    this.create.token = this.user_session.token;
    this.vendor_labels.id = this.user_session.id;
    this.vendor_labels.token = this.user_session.token;
    this.vendor_label_create.id = this.user_session.id;
    this.vendor_label_create.token = this.user_session.token;

    this.fetchCategory();
    this.fetchCollections();
  }

  // ═══════════════════════════════════════════════════════════════
  //  DATA LOADING
  // ═══════════════════════════════════════════════════════════════
  fetchCategory(): void {
    this.ui.page_loading = true;
    this.crudService.get_request(GlobalComponent.UtilityCategory).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.category = response.data;
          this.fetchVendorLabels();
          this.ui.page_loading = false;
        }
      },
    });
  }

  fetchCollections(): void {
    this.crudService.get_request(GlobalComponent.UtilityCollections).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.dropdownList = response.data;
        }
      },
    });
  }

  fetchVendorLabels(): void {
    this.crudService.post_request(this.vendor_labels, GlobalComponent.readLabel).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.labels = response.data;
        }
      },
    });
  }

  createVendorLabel(): void {
    if (!this.vendor_label_create.label.length) {
      this.toast.error('Label name is required');
      return;
    }
    this.ui.creating_label = true;
    this.crudService.post_request(this.vendor_label_create, GlobalComponent.createLabel).subscribe({
      next: (response: any) => {
        this.ui.creating_label = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.vendor_label_create.label = '';
          this.toast.success(response.message);
          this.fetchVendorLabels();
        } else {
          this.toast.error(response.message);
        }
      },
      error: () => {
        this.ui.creating_label = false;
        this.toast.error('Unable to complete your request at this time.');
      },
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  COLOR SELECTION
  // ═══════════════════════════════════════════════════════════════
  trackById = (_: number, item: ColorOption) => item.id;

  toggle(id: string, checked: boolean): void {
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
    const n = hex.replace('#', '');
    const bigint = parseInt(n.length === 3 ? n.split('').map(c => c + c).join('') : n, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return (0.299 * r + 0.587 * g + 0.114 * b) > 220;
  }

  getSelectedIdsCsv(): string {
    return [...this.selected].join(',');
  }

  // ═══════════════════════════════════════════════════════════════
  //  SIZING HELPER
  // ═══════════════════════════════════════════════════════════════
  get showSizing(): boolean {
    return SIZED_CATEGORIES.includes(Number(this.create.category));
  }

  // ═══════════════════════════════════════════════════════════════
  //  IMAGE HANDLING — Featured image (single via AxFileUpload)
  // ═══════════════════════════════════════════════════════════════
  /**
   * Fired whenever the featured-image AxFileUpload emits a change.
   * Compresses the file, reads it as a base64 dataUrl, and stores
   * it on create.image_1 for the submit payload.
   */
  async onFeaturedChange(files: AxUploadFile[]): Promise<void> {
    this.featuredFiles = files;
    const first = files[0];
    if (!first) {
      this.create.image_1 = 'assets/img/placeholder-1.png';
      return;
    }
    try {
      const compressed = await imageCompression(first.file, {
        maxSizeMB: 3,
        maxWidthOrHeight: 1920,
        useWebWorker: true,
      });
      const reader = new FileReader();
      reader.onloadend = () => {
        this.create.image_1 = reader.result as string;
      };
      reader.readAsDataURL(compressed);
    } catch (error) {
      this.toast.error('Image compression failed: ' + error);
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  IMAGE HANDLING — Gallery (multi via AxFileUpload)
  // ═══════════════════════════════════════════════════════════════
  /**
   * Fired whenever the gallery AxFileUpload emits a change.
   * Compresses each newly added file, caches the base64 dataUrl,
   * and syncs the combined array into create.images for submission.
   *
   * This function is resilient to reorders and removals: we key the
   * encoded-file cache by AxUploadFile.id so we only compress files
   * we haven't seen yet.
   */
  async onGalleryChange(files: AxUploadFile[]): Promise<void> {
    if (files.length > 5) {
      this.toast.error('You can only add up to 5 gallery images');
      // Truncate to the max — AxFileUpload already enforces its own
      // maxFiles, but belt-and-braces.
      files = files.slice(0, 5);
    }
    this.galleryFiles = files;

    // Remove cache entries for files no longer present.
    this.galleryEncoded = this.galleryEncoded.filter(e =>
      files.some(f => f.name === e.name && f.size === e.size)
    );
    const existingKeys = new Set(this.galleryEncoded.map(e => e.name + ':' + e.size));

    // Compress + encode new files only.
    const newlyAdded = files.filter(f => !existingKeys.has(f.name + ':' + f.size));
    if (newlyAdded.length === 0) {
      this.syncGalleryImages();
      return;
    }

    try {
      const results = await Promise.all(
        newlyAdded.map(async (uf) => {
          const compressed = await imageCompression(uf.file, {
            maxSizeMB: 3,
            maxWidthOrHeight: 1920,
            useWebWorker: true,
          });
          const dataUrl = await this.fileToDataURL(compressed);
          return {
            file: compressed,
            name: compressed.name,
            type: compressed.type,
            size: compressed.size,
            dataUrl,
            base64: dataUrl.split(',')[1] ?? '',
          } as EncodedFile;
        }),
      );
      this.galleryEncoded.push(...results);
      this.syncGalleryImages();
    } catch (error) {
      this.toast.error('Image compression failed: ' + error);
    }
  }

  private syncGalleryImages(): void {
    this.create.images = this.galleryEncoded.map(e => e.dataUrl).slice(0, 5);
  }

  private fileToDataURL(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result as string);
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(file);
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  PUBLISH / SAVE FLOWS
  // ═══════════════════════════════════════════════════════════════
  private validate(): boolean {
    if (this.create.category === 0) { this.toast.error('Product category is required'); return false; }
    if (!this.create.name.length) { this.toast.error('Name is required'); return false; }
    if (!this.create.description.length) { this.toast.error('Briefly describe your product'); return false; }
    if (!this.create.image_1.length || this.create.image_1.includes('placeholder')) {
      this.toast.error('Featured image is required');
      return false;
    }
    if (!this.create.delivery_time.length) { this.toast.error('Delivery time is required'); return false; }
    return true;
  }

  startPublish(): void {
    if (!this.validate()) return;
    this.confirm
      .confirm({
        title: 'Confirm publish',
        message: 'Make this product live now. It will be visible to customers immediately. Please review price, stock, and images before publishing.',
        confirmLabel: 'Publish',
        cancelLabel: 'Cancel'
      })
      .then((ok) => {
        if (ok) this.submitProduct('published');
      });
  }

  startSave(): void {
    if (!this.validate()) return;
    this.confirm
      .confirm({
        title: 'Confirm save',
        message: "Save your changes as a draft. They won't be visible to customers until you publish.",
        confirmLabel: 'Save',
        cancelLabel: 'Cancel'
      })
      .then((ok) => {
        if (ok) this.submitProduct('draft');
      });
  }

  private submitProduct(status: string): void {
    this.create.colors = this.getSelectedIdsCsv();
    this.create.collection = this.selectedItemsForPayload;
    this.create.status = status;
    this.create.images = this.galleryEncoded.map(e => e.dataUrl).slice(0, 5);

    this.ui.loading = true;

    this.crudService.post_request(this.create, GlobalComponent.createProduct).subscribe({
      next: (response: any) => {
        this.ui.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.toast.success(response.message);
          this.router.navigate(['/products']);
        } else {
          this.toast.error(response.message);
        }
      },
      error: () => {
        this.ui.loading = false;
        this.toast.error('Unable to complete your request at this time.');
      },
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  NAVIGATION
  // ═══════════════════════════════════════════════════════════════
  goBack(): void {
    if (this.user_session.is_vendor) this.router.navigate(['/account']);
    if (this.user_session.is_admin) this.router.navigate(['/backend']);
  }
}
