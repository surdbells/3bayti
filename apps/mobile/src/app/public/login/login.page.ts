import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  IonButton,
  Platform, IonText
} from '@ionic/angular/standalone';
import { Preferences } from '@capacitor/preferences';
import { ConnectionService } from '../../service/connection.service';
import { Subscription } from 'rxjs';

import { FormsModule } from '@angular/forms';
import {Router} from "@angular/router";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {transformV3LoginResponse} from "./login-response.transform";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import {BlockerService} from "../../blocker.service";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import { CartMergeService } from '../../core/services/cart-merge.service';
import { PushManager } from '../../core/services/push-manager.service';

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
@Component({
  selector: 'app-login',
  templateUrl: './login.page.html',
  styleUrls: ['./login.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonButton,
    FormsModule,
    IonText,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
  ]
})
export class LoginPage implements OnInit, OnDestroy {
    isOnline = true;
    private sub: Subscription;
    constructor(
      private net: ConnectionService,
      private platform: Platform,
      private router: Router,
      private blocker: BlockerService,
      private networkAdapter: MobileNetworkAdapter,
      private toast: AxNotificationService,
      private i18n: I18nService,
      private cartMerge: CartMergeService,
      private pushManager: PushManager,
    ) {
      this.net.setReachabilityCheck(true);
      this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnInit() {
   // this.routerOutlet.swipeGesture = false;
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
    this.getObject();
  }
  ngOnDestroy(): void {
    this.blocker.unblock(); // ✅ restore when leaving
    this.sub?.unsubscribe();
  }
  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false,
    is_store_active: false,
    is_store_approved: false,
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      //
    }else{
      this.single_user = JSON.parse(ret.value);
      this.router.navigate(['/', 'account']);
    }
  }
  ui_controls = {
    page_loading: false,
    login_loading: false,
    logged_in: false
  };
  login = {
    email: "",
    password: "",
    remember: false,
  };
  async signIn() {
    if(this.isOnline){
      if (this.login.email.length == 0) {
        this.show_error(this.i18n.t('text_email_required'));
        return;
      }
      if (!GlobalComponent.validateEmail(this.login.email)) {
        this.show_error(this.i18n.t('text_invalid_email_detailed'));
        return;
      }
      if (this.login.password.length == 0) {
        this.show_error(this.i18n.t('text_password_required'));
        return;
      }
      if (this.login.remember) {
        Preferences.set({key: 'keep_session', value: JSON.stringify(this.login)});
      }
      if (!this.login.remember) {
        Preferences.remove({key: 'keep_session'});
      }
      this.ui_controls.login_loading = true;
      // Direct v3 (POST /v3/auth/login). Route was already target='new', so
      // this is behaviour-preserving (post_request Path 1 already reached v3
      // with this same body; no request transform is registered for auth).
      // transformV3LoginResponse below still maps the v3 envelope -> single_user.
      this.networkAdapter.post_v3('POST /auth/login', this.login)
        .subscribe(({
          next: (response: any) => {
            if (response.response_code === 200 && response.status === "success") {
              // Transform v3 login response shape -> legacy single_user
              // shape before storage. See login-response.transform.ts
              // for the field mapping and the rationale for each field.
              // Falls back to storing response.data as-is if the
              // transform doesn't recognize the shape (preserves
              // today's behaviour for any unexpected payload).
              const userToStore = transformV3LoginResponse(response.data) ?? response.data;
              Preferences.set({
                key: 'user',
                value: JSON.stringify(userToStore)
              });

              // M3.2.Z.5-B — now that the user is signed in (Q-Z5.1=A),
              // request push permission + register this device's token.
              // Fire-and-forget: PushManager swallows all errors and is
              // a no-op on web, so it never blocks or breaks login.
              void this.pushManager.onSignedIn();

              // M3.1.6i.2-E: merge the device-local guest cart into
              // the server-side cart, then navigate. Safe to call when
              // local is empty (returns { attempted: false } and
              // proceeds to navigate). On merge failure, the local
              // cart is preserved so the user can retry; we still
              // navigate so login isn't blocked by a network blip.
              const authBody = {
                id: typeof (userToStore as any)?.id === 'number'
                  ? (userToStore as any).id
                  : 0,
                token: typeof (userToStore as any)?.token === 'string'
                  ? (userToStore as any).token
                  : '',
              };
              this.cartMerge.mergeIfAny(authBody).then((result) => {
                if (result.attempted && !result.success) {
                  console.warn('[Login] cart merge failed (non-fatal)', result.error);
                }
                if (result.attempted && result.success && result.skipped.length > 0) {
                  // One-line toast: 'Some items in your cart are no
                  // longer available'. Non-blocking — user is already
                  // navigated to /account.
                  this.toast.info(this.i18n.t('text_cart_merge_skipped_some'));
                }
              }).catch((err) => {
                // Belt and braces — mergeIfAny is designed to never
                // throw (it captures into result.error), but just in
                // case, swallow and log.
                console.warn('[Login] cart merge threw (non-fatal)', err);
              });

              this.ui_controls.login_loading = false;
              this.router.navigate(['/account'], { replaceUrl: true });
              this.blocker.block({ disableSwipe: true, disableHardwareBack: true });

            }else{
              this.ui_controls.logged_in = false;
              this.ui_controls.login_loading = false;
              this.show_error(response.message);
              return;
            }
          },
          error: (e) => {
            this.ui_controls.logged_in = false;
            this.ui_controls.login_loading = false;
            this.show_error(e.toString());
            return;
          },
          complete: () => {
            console.info('complete');
          }
        }))
    }else {
      this.show_error(this.i18n.t('text_offline_check_connection'))
    }
  }
  show_error(message: string) {
    this.toast.error(message, {
      position: 'top-center'
    });
  }
  show_success(message: string, position: any) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }

  user_register() {
    this.router.navigate(['/', 'register']);
  }
  forgot_password() {
    this.router.navigate(['/', 'reset']);
  }
  google_signin(): void {
    this.show_error(this.i18n.t('text_google_signin_unavailable'));
  }
  apple_signin(): void {
    this.show_error(this.i18n.t('text_apple_signin_unavailable'));
  }

  goHome() {
    this.router.navigate(['/', 'home']);
  }
}
