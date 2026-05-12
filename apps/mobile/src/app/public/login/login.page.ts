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
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import {BlockerService} from "../../blocker.service";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";

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
      private networkService: NetworkService,
      private toast: AxNotificationService,
      private i18n: I18nService
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
      this.networkService.post_request(this.login, GlobalComponent.UserLogin)
        .subscribe(({
          next: (response) => {
            if (response.response_code === 200 && response.status === "success") {
              Preferences.set({
                key: 'user',
                value: JSON.stringify(response.data)
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
