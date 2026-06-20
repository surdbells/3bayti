import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar,
  IonSpinner, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { Preferences } from '@capacitor/preferences';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

@Component({
  selector: 'app-gift-card-redeem',
  templateUrl: './gift-card-redeem.page.html',
  styleUrls: ['./gift-card-redeem.page.scss'],
  standalone: true,
  imports: [
    CommonModule, FormsModule,
    IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent, IonSpinner,
    AxLoaderComponent, AxIconComponent, AxTextFieldComponent, TranslatePipe,
  ],
})
export class GiftCardRedeemPage {

  code     = '';
  preview: any = null;   // balance preview result
  ui = { checking: false, redeeming: false };

  private authToken = '';

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.loadAuthToken();
  }

  private async loadAuthToken() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret?.value) {
      try { this.authToken = JSON.parse(ret.value)?.token ?? ''; } catch { /* ignore */ }
    }
  }

  goBack() { this.navCtrl.back(); }

  get formattedCode(): string {
    // Auto-insert hyphens as user types XXXXXXXXXXXXXXXX → XXXX-XXXX-XXXX-XXXX
    const raw = this.code.replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 16);
    return raw.match(/.{1,4}/g)?.join('-') ?? raw;
  }

  onCodeInput(event: any) {
    const raw = (event.target?.value ?? '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 16);
    this.code = raw.match(/.{1,4}/g)?.join('-') ?? raw;
    this.preview = null;
  }

  checkBalance() {
    const raw = this.code.replace(/-/g, '');
    if (raw.length !== 16) { this.notify.error(this.i18n.t('gift_card_redeem_full_code_required')); return; }
    this.ui.checking = true;
    // PUBLIC endpoint — no authToken. Send the normalized (de-hyphenated) code.
    // v3-direct surfaces HTTP errors through the SUCCESS channel as a legacy
    // envelope ({ status:'error', message, data:null }); only network-level
    // failures hit `error`.
    this.network.get_v3('GET /gift-cards/balance', { queryParams: { code: raw } }).subscribe({
      next: (res: any) => {
        this.ui.checking = false;
        if (res?.data) { this.preview = res.data; }
        else { this.notify.error(res?.message ?? this.i18n.t('gift_card_redeem_not_found')); }
      },
      error: () => {
        this.ui.checking = false;
        this.notify.error(this.i18n.t('gift_card_redeem_not_found'));
      },
    });
  }

  redeemCard() {
    if (this.ui.redeeming) return;
    // Normalize to the bare 16-char code the server expects (strip the display
    // hyphens + uppercase). The balance check already does this; redeem must too,
    // or the server gets "XXXX-XXXX-..." and returns card-not-found.
    const code = this.code.replace(/[\s-]/g, '').toUpperCase();
    if (code.length !== 16) { this.notify.error(this.i18n.t('gift_card_redeem_full_code_required')); return; }
    this.ui.redeeming = true;
    this.network.post_v3('POST /gift-cards/redeem', { code }, { authToken: this.authToken }).subscribe({
      next: (res: any) => {
        this.ui.redeeming = false;
        if (res?.data?.id) {
          this.notify.success(this.i18n.t('gift_card_redeem_added_success'));
          this.router.navigate(['/gift-card-detail'], { state: { card: res.data } });
          return;
        }
        // v3-direct surfaces HTTP errors through this success channel as a
        // legacy envelope ({ response_code, status:'error', message }). Redeem
        // requires auth — on 401 send the user to login, returning here (web
        // uses returnUrl=/gift-cards/redeem).
        if (res?.response_code === 401) {
          this.router.navigate(['/login'], { queryParams: { returnUrl: '/gift-cards/redeem' } });
          return;
        }
        this.notify.error(res?.message ?? this.i18n.t('gift_card_redeem_failed'));
      },
      error: () => {
        this.ui.redeeming = false;
        this.notify.error(this.i18n.t('gift_card_redeem_failed'));
      },
    });
  }
}
