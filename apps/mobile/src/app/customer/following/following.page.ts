import { Component, ChangeDetectionStrategy, ChangeDetectorRef, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxSkeletonBlockComponent, AxSkeletonLineComponent } from '../../shared/ax-mobile/skeleton';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { storeFollowMessage } from '../../shared/store-follow-messages';

/** A store the user follows (v3 VendorSerializer::publicShape, is_following=true). */
interface FollowedStore {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  logo_url: string | null;
  is_verified: boolean;
}

/**
 * /following, the stores the user follows.
 *
 * Auth-only (guests bounce to /login). Lists each followed store with a link
 * to the storefront and an inline Unfollow that removes it from the list.
 * Mirrors the web account-following page.
 */
@Component({
  selector: 'app-following',
  templateUrl: './following.page.html',
  styleUrls: ['./following.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    TranslatePipe,
    AxIconComponent,
    AxSkeletonBlockComponent,
    AxSkeletonLineComponent,
    AppTabBarComponent,
  ],
})
export class FollowingPage implements OnInit {
  stores: FollowedStore[] = [];
  isLoading = false;
  hasLoaded = false;

  private unfollowing = new Set<number>();
  private token = '';

  constructor(
    private router: Router,
    private nav: NavController,
    private networkAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef,
  ) {}

  async ngOnInit(): Promise<void> {
    const ret: any = await Preferences.get({ key: 'user' });
    if (!ret.value) {
      this.router.navigate(['/', 'login']);
      return;
    }
    this.token = JSON.parse(ret.value).token ?? '';
    this.load();
  }

  private load(): void {
    this.isLoading = true;
    this.cdr.markForCheck();
    this.networkAdapter.get_v3('GET /following', {
      authToken: this.token,
      queryParams: { limit: 50, offset: 0 },
    }).subscribe({
      next: (res: any) => {
        this.stores = (res?.response_code === 200 && res?.status === 'success' && Array.isArray(res.data))
          ? res.data
          : [];
        this.isLoading = false;
        this.hasLoaded = true;
        this.cdr.markForCheck();
      },
      error: () => {
        this.isLoading = false;
        this.hasLoaded = true;
        this.cdr.markForCheck();
      },
    });
  }

  isUnfollowing(id: number): boolean {
    return this.unfollowing.has(id);
  }

  async unfollow(store: FollowedStore): Promise<void> {
    if (this.unfollowing.has(store.id)) {
      return;
    }
    this.unfollowing.add(store.id);
    this.cdr.markForCheck();
    try {
      const res: any = await firstValueFrom(
        this.networkAdapter.delete_v3('DELETE /following/:vendorId', {
          authToken: this.token,
          pathParams: { vendorId: String(store.id) },
        }),
      );
      // HTTP failures resolve through the SUCCESS channel with an error
      // envelope (only transport errors throw), so check the envelope rather
      // than treating any resolution as success — that dropped the row even
      // when the server refused the unfollow.
      if (res?.response_code === 200 && res?.status === 'success') {
        this.stores = this.stores.filter((s) => s.id !== store.id);
        this.toast.success(storeFollowMessage(this.i18n, 'unfollowed', store.name), { position: 'top-center' });
      } else {
        this.toast.error(storeFollowMessage(this.i18n, 'unfollow_failed'), { position: 'top-center' });
      }
    } catch {
      this.toast.error(storeFollowMessage(this.i18n, 'unfollow_failed'), { position: 'top-center' });
    } finally {
      this.unfollowing.delete(store.id);
      this.cdr.markForCheck();
    }
  }

  openStore(store: FollowedStore): void {
    this.router.navigate(['/', 'vendors'], { queryParams: { slug: store.slug, name: store.name } });
  }

  browseStores(): void {
    this.router.navigate(['/', 'vendors']);
  }

  initialOf(store: FollowedStore): string {
    return (store.name ?? '?').trim().charAt(0).toUpperCase() || '?';
  }

  triggerBack(): void {
    this.nav.back();
  }
}
