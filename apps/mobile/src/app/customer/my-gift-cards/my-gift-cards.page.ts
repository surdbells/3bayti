import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar,
  IonRefresher, IonRefresherContent, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { GlobalComponent } from '../../global-component';
import { cfImage } from '../../shared/cf-image';

@Component({
  selector: 'app-my-gift-cards',
  templateUrl: './my-gift-cards.page.html',
  styleUrls: ['./my-gift-cards.page.scss'],
  standalone: true,
  imports: [
    CommonModule, IonHeader, IonToolbar, IonTitle, IonButtons, IonButton,
    IonContent, IonRefresher, IonRefresherContent,
    AxLoaderComponent, AxIconComponent,
  ],
})
export class MyGiftCardsPage implements OnInit {

  cards: any[] = [];
  ui = { loading: true };

  readonly cfImage = cfImage;

  // Status display map
  readonly statusMeta: Record<string, { label: string; color: string }> = {
    pending_payment: { label: 'Pending payment', color: '#F5A623' },
    active:          { label: 'Active',           color: '#2D7D4F' },
    partially_used:  { label: 'Partially used',   color: '#2D7D4F' },
    exhausted:       { label: 'Used up',           color: '#8B6F47' },
    expired:         { label: 'Expired',           color: '#C0392B' },
    voided:          { label: 'Voided',            color: '#C0392B' },
  };

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
  ) {}

  ngOnInit() { this.load(); }

  load(event?: any) {
    this.ui.loading = true;
    this.network.get_v3('/v3/gift-cards/mine').subscribe({
      next: (res: any) => {
        this.ui.loading = false;
        this.cards = res?.data ?? [];
        event?.target?.complete();
      },
      error: () => {
        this.ui.loading = false;
        this.notify.error('Could not load gift cards.');
        event?.target?.complete();
      },
    });
  }

  openCard(card: any) {
    this.router.navigate(['/gift-card-detail'], { state: { card } });
  }

  buyNew() {
    this.router.navigate(['/gift-cards']);
  }

  redeemCode() {
    this.router.navigate(['/gift-card-redeem']);
  }

  goBack() { this.navCtrl.back(); }

  isSpendable(card: any): boolean {
    return card.is_spendable === true;
  }
}
