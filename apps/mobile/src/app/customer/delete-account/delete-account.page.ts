import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  NavController,
  AlertController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { apiErrorMessage } from '../../core/http/api-error';
import { I18nService } from '../../i18n.service';
import { PushManager } from '../../core/services/push-manager.service';

/**
 * In-app account deletion (App Store requirement — Apple 5.1.1(v)).
 *
 * Backend contract (M3.2.Y.6-A):
 *   DELETE /v3/me  body { current_password }  -> 204
 *   - re-auths the password (wrong password => 401 AUTH_INVALID_CREDENTIALS)
 *   - soft-deletes + deactivates the account, revokes all refresh tokens
 *   - order history is retained
 *
 * Mirrors the web danger-zone flow (apps/web .../account-delete-page.ts):
 * password re-auth -> explicit confirm -> delete -> clear local session ->
 * home. Replaces the previous mailto-to-support stopgap in settings.
 */
@Component({
  selector: 'app-delete-account',
  templateUrl: './delete-account.page.html',
  styleUrls: ['./delete-account.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ],
})
export class DeleteAccountPage implements OnInit {
  password = '';
  busy = false;

  private token = '';

  constructor(
    private nav: NavController,
    private router: Router,
    private toast: AxNotificationService,
    private adapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private alertCtrl: AlertController,
    private pushManager: PushManager,
  ) {}

  async ngOnInit() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
      return;
    }
    this.token = JSON.parse(ret.value).token;
  }

  goBack() {
    this.nav.back();
  }

  async confirmDelete() {
    if (!this.password) {
      this.toast.error(this.i18n.t('delete_account_password_required'), { position: 'top-center' });
      return;
    }

    const alert = await this.alertCtrl.create({
      header: this.i18n.t('delete_account'),
      message: this.i18n.t('delete_account_action_header'),
      buttons: [
        { text: this.i18n.t('cancel'), role: 'cancel' },
        {
          text: this.i18n.t('delete_account'),
          role: 'destructive',
          handler: () => { this.performDelete(); },
        },
      ],
    });
    await alert.present();
  }

  private performDelete() {
    this.busy = true;
    this.adapter
      .delete_v3('DELETE /me', { authToken: this.token, body: { current_password: this.password } })
      .subscribe({
        next: (response: any) => {
          if (response.status === 'success') {
            void this.teardownAndLeave();
            return;
          }
          this.busy = false;
          // Re-auth failure is 401 AUTH_INVALID_CREDENTIALS.
          if (response.response_code === 401) {
            this.toast.error(this.i18n.t('wrong_password'), { position: 'top-center' });
          } else {
            this.toast.error(response.message ?? this.i18n.t('delete_failed'), { position: 'top-center' });
          }
        },
        error: (err: any) => {
          this.busy = false;
          this.toast.error(apiErrorMessage(err, this.i18n.t('delete_failed')), { position: 'top-center' });
        },
      });
  }

  /** Server already revoked the session; clear local state + leave. */
  private async teardownAndLeave() {
    // Best-effort: deactivate this device's push token while the (now
    // soon-invalid) auth token is still in Preferences. Fire-safe.
    try {
      await this.pushManager.onSignedOutReadingToken();
    } catch {
      /* swallow — account is gone regardless */
    }
    await Preferences.remove({ key: 'keep_session' });
    await Preferences.remove({ key: 'user' });
    this.busy = false;
    this.toast.success(this.i18n.t('account_deleted'), { position: 'top-center' });
    this.router.navigate(['/', 'home'], { replaceUrl: true });
  }
}
