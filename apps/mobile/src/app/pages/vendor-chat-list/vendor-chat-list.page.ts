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
 * Vendor conversation list (v3). The vendor's order chats with customers;
 * tapping a row opens the shared thread by uuid in the vendor role.
 */
@Component({
  selector: 'app-vendor-chat-list',
  templateUrl: './vendor-chat-list.page.html',
  styleUrls: ['./vendor-chat-list.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule, IonContent, IonHeader, IonToolbar, IonTitle, IonButton, IonButtons,
    IonRefresher, IonRefresherContent, IonSpinner, TranslatePipe, AxIconComponent,
  ],
})
export class VendorChatListPage implements OnInit, OnDestroy {
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

  private async bootstrap(): Promise<void> {
    const userData = await Preferences.get({ key: 'user' });
    if (!userData.value) {
      this.router.navigate(['/login']);
      return;
    }
    this.load();
  }

  load(event?: CustomEvent): void {
    if (!event) {
      this.isLoading = true;
      this.cdr.markForCheck();
    }
    const sub = this.chatService.listConversations('vendor').subscribe({
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
    this.router.navigate(['/chat'], { queryParams: { uuid: c.uuid, role: 'vendor' } });
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
    this.nav.back();
  }
}
