import { Component, OnInit, OnDestroy, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonTitle, IonButton, IonButtons,
  IonRefresher, IonRefresherContent, IonSpinner, NavController,
} from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';
import { Preferences } from '@capacitor/preferences';

import { ChatService } from '../../service/chat.service';
import { ChatConversationSummary } from '../../models/chat.models';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';

/**
 * Customer conversation list (v3). A flat list of the customer's order chats;
 * tapping a row opens the thread by uuid. Replaces the legacy vendor->orders
 * drill-down (the order details now live in each thread's system message).
 */
@Component({
  selector: 'app-chat-vendors',
  templateUrl: './chat-vendors.page.html',
  styleUrls: ['./chat-vendors.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule, IonContent, IonHeader, IonToolbar, IonTitle, IonButton, IonButtons,
    IonRefresher, IonRefresherContent, IonSpinner, TranslatePipe, AxIconComponent,
  ],
})
export class ChatVendorsPage implements OnInit, OnDestroy {
  conversations: ChatConversationSummary[] = [];
  isLoading = true;

  private subscriptions: Subscription[] = [];

  constructor(
    private chatService: ChatService,
    private router: Router,
    private nav: NavController,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.bootstrap();
  }

  ngOnDestroy(): void {
    this.subscriptions.forEach((s) => s.unsubscribe());
  }

  ionViewWillEnter(): void {
    // Refresh the inbox each time the page is shown so unread counts, order
    // references and last-message previews reflect activity that happened
    // while the user was away — without needing to open a thread.
    this.load();
  }

  private async bootstrap(): Promise<void> {
    const userData = await Preferences.get({ key: 'user' });
    if (!userData.value) {
      this.router.navigate(['/login']);
      return;
    }
    // The initial load is driven by ionViewWillEnter (which fires after
    // ngOnInit on first entry and on every subsequent return to the page),
    // so we don't call load() here to avoid a duplicate first fetch.
  }

  load(event?: CustomEvent): void {
    if (!event) {
      this.isLoading = true;
      this.cdr.markForCheck();
    }
    const sub = this.chatService.listConversations('customer').subscribe({
      next: (list) => {
        this.conversations = list;
        this.isLoading = false;
        (event?.target as { complete?: () => void } | null)?.complete?.();
        this.cdr.markForCheck();
      },
      error: () => {
        this.isLoading = false;
        (event?.target as { complete?: () => void } | null)?.complete?.();
        this.cdr.markForCheck();
      },
    });
    this.subscriptions.push(sub);
  }

  openConversation(c: ChatConversationSummary): void {
    this.router.navigate(['/chat'], { queryParams: { uuid: c.uuid } });
  }

  trackByUuid(_: number, c: ChatConversationSummary): string {
    return c.uuid;
  }

  formatTime(iso: string | null): string {
    if (!iso) {
      return '';
    }
    const d = new Date(iso);
    const sameDay = d.toDateString() === new Date().toDateString();
    return sameDay
      ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString([], { day: '2-digit', month: 'short' });
  }

  goBack(): void {
    this.router.navigate(['/account']);
  }
}
