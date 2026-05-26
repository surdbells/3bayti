import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar,
  IonSpinner, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';

@Component({
  selector: 'app-gift-card-redeem',
  templateUrl: './gift-card-redeem.page.html',
  styleUrls: ['./gift-card-redeem.page.scss'],
  standalone: true,
  imports: [
    CommonModule, FormsModule,
    IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent, IonSpinner,
    AxLoaderComponent, AxIconComponent, AxTextFieldComponent,
  ],
})
export class GiftCardRedeemPage {

  code     = '';
  preview: any = null;   // balance preview result
  ui = { checking: false, redeeming: false };

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
  ) {}

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
    if (raw.length !== 16) { this.notify.error('Please enter a full 16-character code.'); return; }
    this.ui.checking = true;
    this.network.get_v3(`/v3/gift-cards/balance?code=${this.code}`).subscribe({
      next: (res: any) => {
        this.ui.checking = false;
        if (res?.data) { this.preview = res.data; }
        else { this.notify.error('Gift card not found.'); }
      },
      error: (err: any) => {
        this.ui.checking = false;
        this.notify.error(err?.error?.message ?? 'Gift card not found.');
      },
    });
  }

  redeemCard() {
    if (this.ui.redeeming) return;
    this.ui.redeeming = true;
    this.network.post_v3('/v3/gift-cards/redeem', { code: this.code }).subscribe({
      next: (res: any) => {
        this.ui.redeeming = false;
        if (res?.data?.id) {
          this.notify.success('Gift card added to your wallet!');
          this.router.navigate(['/gift-card-detail'], { state: { card: res.data } });
        } else {
          this.notify.error(res?.message ?? 'Could not redeem this code.');
        }
      },
      error: (err: any) => {
        this.ui.redeeming = false;
        this.notify.error(err?.error?.message ?? 'Could not redeem this code.');
      },
    });
  }
}
