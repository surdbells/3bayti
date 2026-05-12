import { Component, OnInit, OnDestroy, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { IonContent, IonHeader, IonToolbar, IonTitle, IonButton, IonButtons, IonRefresher, IonRefresherContent, NavController } from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';
import { Preferences } from '@capacitor/preferences';
import { ChatService } from '../../service/chat.service';
import { ChatVendor } from '../../models/chat.models';
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
@Component({
  selector: 'app-chat-vendors',
  templateUrl: './chat-vendors.page.html',
  styleUrls: ['./chat-vendors.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CommonModule, IonContent, IonHeader, IonToolbar, IonTitle, IonButton, IonButtons, IonRefresher, IonRefresherContent, TranslatePipe, AxIconComponent]
})
export class ChatVendorsPage implements OnInit, OnDestroy {
  vendors: ChatVendor[] = [];
  isLoading = true;

  private userId = 0;
  private userToken = '';
  private subscriptions: Subscription[] = [];

  constructor(
    private chatService: ChatService,
    private router: Router,
    private nav: NavController,
    private cdr: ChangeDetectorRef
  ) {}

  ngOnInit() {
    this.loadUserAndVendors();
  }

  ngOnDestroy() {
    this.subscriptions.forEach(sub => sub.unsubscribe());
  }

  async loadUserAndVendors() {
    const userData = await Preferences.get({ key: 'user' });
    if (!userData.value) {
      this.router.navigate(['/login']);
      return;
    }

    const user = JSON.parse(userData.value);
    this.userId = user.id;
    this.userToken = user.token;
    this.loadVendors();
  }

  loadVendors() {
    this.isLoading = true;
    this.cdr.markForCheck();

    const sub = this.chatService.getVendorsWithOrders(this.userId, this.userToken).subscribe({
      next: (vendors) => {
        this.vendors = vendors;
        this.isLoading = false;
        this.cdr.markForCheck();
      },
      error: () => {
        this.vendors = [];
        this.isLoading = false;
        this.cdr.markForCheck();
      }
    });

    this.subscriptions.push(sub);
  }

  selectVendor(vendor: ChatVendor) {
    this.router.navigate(['/chat-orders'], {
      queryParams: { vendor_id: vendor.vendor_id, store_name: vendor.store_name }
    });
  }

  handleRefresh(event: any) {
    this.loadVendors();
    setTimeout(() => event.target.complete(), 500);
  }

  goBack() {
    this.nav.back();
  }

  goToShop() {
    this.router.navigate(['/account']);
  }
}
