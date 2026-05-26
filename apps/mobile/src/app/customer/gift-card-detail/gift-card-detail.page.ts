import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar,
  NavController,
} from '@ionic/angular/standalone';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { cfImage } from '../../shared/cf-image';

@Component({
  selector: 'app-gift-card-detail',
  templateUrl: './gift-card-detail.page.html',
  styleUrls: ['./gift-card-detail.page.scss'],
  standalone: true,
  imports: [
    CommonModule, IonHeader, IonToolbar, IonTitle, IonButtons, IonButton,
    IonContent, AxIconComponent,
  ],
})
export class GiftCardDetailPage implements OnInit {
  card: any = null;
  readonly cfImage = cfImage;

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private notify: AxNotificationService,
  ) {}

  ngOnInit() {
    const nav = this.router.getCurrentNavigation() ?? (this.router as any).lastSuccessfulNavigation;
    this.card = nav?.extras?.state?.['card'] ?? history.state?.card ?? null;
    if (!this.card) this.navCtrl.back();
  }

  goBack() { this.navCtrl.back(); }

  get themeMeta() { return this.card?.theme_meta ?? {}; }

  get transactions(): any[] { return this.card?.transactions ?? []; }

  get balancePct(): number {
    if (!this.card) return 0;
    return Math.round((Number(this.card.balance) / Number(this.card.denomination)) * 100);
  }

  async shareCard() {
    const code = this.card?.code ?? '';
    const text = `Here's a 3bayti gift card for you! Code: ${code}\nRedeem it in the 3bayti app.`;
    try {
      if (navigator.share) {
        await navigator.share({ title: '3bayti Gift Card', text });
      } else {
        await navigator.clipboard.writeText(text);
        this.notify.success('Gift card details copied!');
      }
    } catch {
      // User dismissed — no-op
    }
  }

  async copyCode() {
    try {
      await navigator.clipboard.writeText(this.card?.code ?? '');
      this.notify.success('Code copied to clipboard!');
    } catch {
      this.notify.info(this.card?.code ?? '');
    }
  }
}
