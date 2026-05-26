import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle,
  IonToolbar, IonSpinner, IonIcon, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { TranslatePipe } from '../../translate.pipe';
import { GlobalComponent } from '../../global-component';

export interface GiftCardTheme {
  label: string;
  primary_color: string;
  accent_color: string;
  supports_photo: boolean;
  arabic_label: string;
  presets: string[];
  min_denomination: string;
  max_denomination: string;
}

@Component({
  selector: 'app-gift-cards',
  templateUrl: './gift-cards.page.html',
  styleUrls: ['./gift-cards.page.scss'],
  standalone: true,
  imports: [
    CommonModule, FormsModule, TranslatePipe,
    IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent,
    IonSpinner, IonIcon,
    AxLoaderComponent, AxIconComponent, AxTextFieldComponent,
  ],
})
export class GiftCardsPage implements OnInit {

  // ── State ──────────────────────────────────────────────────────────

  themes: Record<string, GiftCardTheme> = {};
  themeKeys: string[] = [];

  ui = {
    loading_themes: true,
    purchasing: false,
    step: 1 as 1 | 2 | 3,   // 1=pick theme, 2=personalise, 3=confirm
    show_custom_amount: false,
  };

  // Purchase form
  form = {
    theme: '',
    denomination: '',
    custom_amount: '',
    recipient_name: '',
    recipient_message: '',
    recipient_photo_url: null as string | null,
    scheduled_delivery_at: null as string | null,
  };

  // Preset denomination options (filtered for the selected theme)
  get presets(): string[] {
    return this.themes[this.form.theme]?.presets ?? [];
  }

  get selectedTheme(): GiftCardTheme | null {
    return this.themes[this.form.theme] ?? null;
  }

  get effectiveDenomination(): string {
    return this.ui.show_custom_amount ? this.form.custom_amount : this.form.denomination;
  }

  get isPhotoTheme(): boolean {
    return this.selectedTheme?.supports_photo === true;
  }

  private v3BaseUrl = 'https://api-v3.3bayti.ae';

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
  ) {}

  ngOnInit() {
    this.loadThemes();
  }

  loadThemes() {
    this.ui.loading_themes = true;
    this.network.get_v3('/v3/gift-cards/themes').subscribe({
      next: (res: any) => {
        this.ui.loading_themes = false;
        if (res?.data) {
          this.themes = res.data;
          this.themeKeys = Object.keys(res.data);
        }
      },
      error: () => {
        this.ui.loading_themes = false;
        this.notify.error('Failed to load themes. Please try again.');
      },
    });
  }

  // ── Step navigation ────────────────────────────────────────────────

  selectTheme(key: string) {
    this.form.theme = key;
    this.form.denomination = '';
    this.form.custom_amount = '';
    this.ui.show_custom_amount = false;
    this.ui.step = 2;
  }

  selectDenomination(preset: string) {
    this.form.denomination = preset;
    this.ui.show_custom_amount = false;
  }

  toggleCustomAmount() {
    this.ui.show_custom_amount = !this.ui.show_custom_amount;
    if (this.ui.show_custom_amount) this.form.denomination = '';
  }

  nextToConfirm() {
    const amount = this.effectiveDenomination;
    if (!amount || isNaN(Number(amount))) {
      this.notify.error('Please select or enter an amount.'); return;
    }
    const n = Number(amount);
    if (n < 100 || n > 10000) {
      this.notify.error('Amount must be between AED 100 and AED 10,000.'); return;
    }
    this.ui.step = 3;
  }

  goBack() {
    if (this.ui.step > 1) { this.ui.step = (this.ui.step - 1) as 1 | 2 | 3; }
    else { this.navCtrl.back(); }
  }

  // ── Photo upload (luxury theme only) ──────────────────────────────

  async onPhotoSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const sessionRaw = sessionStorage.getItem('SESSION') ?? '';
    const session = sessionRaw ? JSON.parse(atob(sessionRaw)) : {};
    const token = session?.token ?? '';
    const form = new FormData();
    form.append('image', file, file.name);
    const headers = { Authorization: `Bearer ${token}` };

    const http = (await import('@angular/common/http')).HttpClient;
    // Simple fetch for the upload since we don't have HttpClient injected here
    try {
      const resp = await fetch(`${this.v3BaseUrl}/v3/upload?context=gift_card_photo`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: form,
      });
      const data = await resp.json();
      if (data?.data?.url) {
        this.form.recipient_photo_url = data.data.url;
        this.notify.success('Photo uploaded!');
      }
    } catch {
      this.notify.error('Photo upload failed. Please try again.');
    }
  }

  removePhoto() {
    this.form.recipient_photo_url = null;
  }

  // ── Submit ─────────────────────────────────────────────────────────

  purchaseCard() {
    if (this.ui.purchasing) return;
    this.ui.purchasing = true;

    const amount = Number(this.effectiveDenomination);
    const denominationStr = amount.toFixed(2);

    const payload: any = {
      denomination: denominationStr,
      theme: this.form.theme,
      recipient_name: this.form.recipient_name || null,
      recipient_message: this.form.recipient_message || null,
      recipient_photo_url: this.form.recipient_photo_url,
      scheduled_delivery_at: this.form.scheduled_delivery_at,
    };

    this.network.post_v3('/v3/gift-cards/purchase', payload).subscribe({
      next: (res: any) => {
        this.ui.purchasing = false;
        if (res?.data?.id) {
          // Navigate to checkout to pay for the gift card
          this.router.navigate(['/gift-card-checkout'], {
            state: { gift_card: res.data },
          });
        } else {
          this.notify.error(res?.message ?? 'Could not create gift card. Please try again.');
        }
      },
      error: (err: any) => {
        this.ui.purchasing = false;
        this.notify.error(err?.error?.message ?? 'Purchase failed. Please try again.');
      },
    });
  }
}
