import { Component, OnInit } from '@angular/core';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonSpinner,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';

interface Money {
  amount: number;
  currency: string;
}

interface VisualItem {
  id: number;
  name: string;
  price: Money | null;
  sale_price: Money | null;
  primary_image: { url: string } | null;
  vendor: { slug: string; name: string; id: number } | null;
  in_stock: boolean;
}

interface StoredUser {
  id: number;
  token: string;
}

/**
 * "Search by photo": snap or pick a photo and Ain finds visually similar real,
 * in-stock pieces (POST /ai/visual-search). Uses the OS file/photo picker via a
 * standard <input type="file"> (no Capacitor Camera plugin; deliberately no
 * `capture`, see the template); the image is downscaled client-side (never
 * uploaded full-size) and never persisted server-side.
 *
 * iOS: taking a photo needs NSCameraUsageDescription in ios/App/App/Info.plist.
 * Shells built without it are killed by iOS the moment the camera opens, which
 * is why `capture` (camera-only) was removed: picking from the library needs no
 * permission.
 */
@Component({
  selector: 'app-visual-search',
  templateUrl: './visual-search.page.html',
  styleUrls: ['./visual-search.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonSpinner,
    TranslatePipe,
    AxIconComponent,
  ],
})
export class VisualSearchPage implements OnInit {
  readonly cfImage = cfImage;

  preview: string | null = null;
  cards: VisualItem[] = [];
  description: string | null = null;
  isSending = false;
  hasSearched = false;
  isError = false;
  imageLoaded: { [key: number]: boolean } = {};

  private user: StoredUser | null = null;
  private sessionId = '';
  private interactionId: number | null = null;

  constructor(
    private nav: NavController,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private toast: AxNotificationService,
  ) {}

  async ngOnInit(): Promise<void> {
    const ret = await Preferences.get({ key: 'user' });
    if (ret.value) {
      try {
        this.user = JSON.parse(ret.value) as StoredUser;
      } catch {
        this.user = null;
      }
    }
    this.sessionId = await this.ensureSession();
    this.recordEvent('ai_opened', { feature: 'visual_search' });
  }

  goBack(): void {
    this.nav.back();
  }

  get isEmpty(): boolean {
    return this.hasSearched && !this.isSending && this.cards.length === 0;
  }

  async onFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files && input.files[0];
    if (!file) {
      return;
    }
    await this.searchFrom(file);
    input.value = '';
  }

  private async searchFrom(file: File): Promise<void> {
    this.isSending = true;
    this.hasSearched = true;
    this.isError = false;
    this.cards = [];
    this.description = null;
    this.imageLoaded = {};

    let dataUrl: string;
    try {
      dataUrl = await this.toResizedDataUrl(file);
    } catch {
      this.isSending = false;
      this.isError = true;
      return;
    }
    this.preview = dataUrl;
    this.recordEvent('ai_visual_search_started');

    const body: Record<string, unknown> = {
      image: dataUrl,
      locale: this.i18n.lang,
      session_id: this.sessionId,
      channel: 'MOBILE',
    };
    const opts = this.user?.token ? { authToken: this.user.token } : {};

    this.networkAdapter.post_v3('POST /ai/visual-search', body, opts).subscribe({
      next: (res: any) => {
        this.isSending = false;
        if (res?.response_code === 200 && res?.status === 'success' && res?.data) {
          this.interactionId = res.data.interaction_id ?? null;
          this.cards = Array.isArray(res.data.products) ? (res.data.products as VisualItem[]) : [];
          this.description = res.data.description ?? null;
          this.recordEvent('ai_visual_search_results', { count: this.cards.length });
        } else {
          this.isError = true;
        }
      },
      error: () => {
        this.isSending = false;
        this.isError = true;
      },
    });
  }

  clear(): void {
    this.preview = null;
    this.cards = [];
    this.description = null;
    this.hasSearched = false;
    this.isError = false;
  }

  onImageLoad(id: number): void {
    this.imageLoaded[id] = true;
  }
  onImageError(id: number): void {
    this.imageLoaded[id] = true;
  }

  open_product(card: VisualItem): void {
    this.recordEvent('ai_visual_search_product_clicked', { product_id: card.id });
    this.router.navigate(['/', 'product'], { queryParams: { id: card.id, name: card.name } });
  }

  /**
   * Downscale to <= `max` px on the long edge and re-encode as JPEG. Tries
   * createImageBitmap first, then an <img> decode; it never falls back to
   * uploading the original (a phone photo is several MB, ~1.37x more as
   * base64, which can exceed the API's 6 MB cap). Rejects if neither decoder
   * can read the file, so the page shows its error state instead.
   */
  private async toResizedDataUrl(file: File, max = 1024): Promise<string> {
    const draw = (src: CanvasImageSource, width: number, height: number): string => {
      const scale = Math.min(1, max / Math.max(width, height));
      const w = Math.max(1, Math.round(width * scale));
      const h = Math.max(1, Math.round(height * scale));
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      if (!ctx) {
        throw new Error('no 2d context');
      }
      ctx.drawImage(src, 0, 0, w, h);
      return canvas.toDataURL('image/jpeg', 0.85);
    };

    if (typeof createImageBitmap === 'function') {
      try {
        const bitmap = await createImageBitmap(file);
        try {
          return draw(bitmap, bitmap.width, bitmap.height);
        } finally {
          bitmap.close();
        }
      } catch {
        // fall through to the <img> decoder
      }
    }

    const url = URL.createObjectURL(file);
    try {
      const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = () => reject(new Error('decode failed'));
        el.src = url;
      });
      return draw(img, img.naturalWidth, img.naturalHeight);
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  private recordEvent(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId, ...extra };
      if (this.interactionId) {
        body['interaction_id'] = this.interactionId;
      }
      const opts = this.user?.token ? { authToken: this.user.token } : {};
      this.networkAdapter.post_v3('POST /ai/events', body, opts).subscribe({ next: () => {}, error: () => {} });
    } catch {
      // Analytics must never break the page.
    }
  }

  private async ensureSession(): Promise<string> {
    const got = await Preferences.get({ key: 'ain_session' });
    if (got.value) {
      return got.value;
    }
    const id =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : 'm-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
    await Preferences.set({ key: 'ain_session', value: id });
    return id;
  }
}
