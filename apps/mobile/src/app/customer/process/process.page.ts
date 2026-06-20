import { Component, OnInit } from '@angular/core';

import {
  IonContent,
  Platform
} from '@ionic/angular/standalone';
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router} from "@angular/router";
import {BlockerService} from "../../blocker.service";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {TranslatePipe} from "../../translate.pipe";
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { CheckoutStatusPollService, type PollOutcome } from '../../core/services/checkout-status-poll.service';
import { I18nService } from '../../i18n.service';

@Component({
  selector: 'app-process',
  templateUrl: './process.page.html',
  standalone: true,
  imports: [IonContent, TranslatePipe, AxLoaderComponent]
})
export class ProcessPage implements OnInit {
  isOnline = true;
  private sub: Subscription;
  private orderReference: string = '';
  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private blocker: BlockerService,
    private route: ActivatedRoute,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private pollService: CheckoutStatusPollService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnInit() {
    this.rqst_param.orderId = this.route.snapshot.queryParamMap.get('orderId') || '';
    this.rqst_param.merchantReference = this.route.snapshot.queryParamMap.get('merchantReference') || '';
    this.rqst_param.paymentType = this.route.snapshot.queryParamMap.get('paymentType') || '';
    this.rqst_param.delivery_fee = Number(this.route.snapshot.queryParamMap.get('deliveryFee') || 0);
    // v3-only: presence of orderReference triggers polling instead of
    // the legacy finalizePayment call.
    this.orderReference = this.route.snapshot.queryParamMap.get('orderReference') || '';
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
    this.getObject();

  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id;
      this.rqst_param.token = this.single_user.token;
      this.rqst_param.email = this.single_user.email;
      this.rqst_param.first_name = this.single_user.first_name;
      this.rqst_param.delivery_name = this.single_user.billing_name;
      this.rqst_param.delivery_phone = this.single_user.billing_phone;
      this.rqst_param.delivery_email = this.single_user.billing_email;
      this.rqst_param.delivery_city = this.single_user.billing_city;
      this.rqst_param.delivery_area = this.single_user.billing_area;
      this.rqst_param.delivery_street_address = this.single_user.delivery_address;
      this.rqst_param.villa_number = this.single_user.villa_number;
      this.finalize();
    }
  }
  rqst_param = {
    id: 0,
    token: "",
    orderId: "",
    email: "",
    first_name: "",
    delivery_fee: 0,
    delivery_name: "",
    delivery_phone: "",
    delivery_email: "",
    delivery_city: "",
    delivery_area: "",
    delivery_street_address: "",
    villa_number: "",
    merchantReference: "",
    paymentType: ""
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
    delivery_address: "",
    billing_name: "",
    billing_phone: "",
    billing_email: "",
    billing_country: "",
    billing_city: "",
    billing_area: "",
    billing_street: "",
    villa_number: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  }
  ui_controls = {
    confirming_transaction: true,
    isConfirmed: false
  }
finalize() {
      this.ui_controls.confirming_transaction = true;

      // Unified v3 confirmation. orderReference is set when checkout.page
      // detected the v3 (path-based) return; for older-format returns we
      // fall back to the merchant reference / order id. Either way we poll
      // GET /v3/checkout/status/{ref} to a terminal state instead of the
      // retired legacy finalizePayment endpoint.
      const reference = (
        this.orderReference ||
        this.rqst_param.merchantReference ||
        String(this.rqst_param.orderId || '')
      ).trim();

      if (!reference) {
        // Nothing to confirm against — don't claim success or failure;
        // send the user to their orders so they can see the real state.
        this.ui_controls.confirming_transaction = false;
        this.router.navigate(['/my-orders'], { replaceUrl: true });
        return;
      }

      this.pollService.pollUntilTerminal(reference, this.single_user.token).subscribe({
        next: (outcome: PollOutcome) => {
          this.ui_controls.confirming_transaction = false;
          switch (outcome.kind) {
            case 'paid':
              this.router.navigate(['/success'], { replaceUrl: true });
              break;
            case 'failed':
              this.router.navigate(['/failed'], { replaceUrl: true });
              break;
            case 'timeout':
              // The order may still complete via a delayed webhook. Send
              // the user to my-orders so they can see the order and check
              // back; the order is real even if not yet confirmed.
              this.toast.info(
                this.i18n.t('text_payment_processing_check_later'),
              );
              this.router.navigate(['/my-orders'], { replaceUrl: true });
              break;
            case 'error':
            default:
              this.router.navigate(['/failed'], { replaceUrl: true });
              break;
          }
        },
        error: (err) => {
          // Should not happen (the service catches its own errors), but
          // defensive: treat as failed.
          console.error('[ProcessPage] poll subscribe error', err);
          this.ui_controls.confirming_transaction = false;
          this.router.navigate(['/failed'], { replaceUrl: true });
        },
      });
  }
}
