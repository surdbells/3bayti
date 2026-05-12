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
import {GlobalComponent} from "../../global-component";
import {TranslatePipe} from "../../translate.pipe";
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';

@Component({
  selector: 'app-process',
  templateUrl: './process.page.html',
  standalone: true,
  imports: [IonContent, TranslatePipe, AxLoaderComponent]
})
export class ProcessPage implements OnInit {
  isOnline = true;
  private sub: Subscription;
  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private blocker: BlockerService,
    private route: ActivatedRoute,
    private networkService: NetworkService,
    private toast: AxNotificationService
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnInit() {
    this.rqst_param.orderId = this.route.snapshot.queryParamMap.get('orderId') || '';
    this.rqst_param.merchantReference = this.route.snapshot.queryParamMap.get('merchantReference') || '';
    this.rqst_param.paymentType = this.route.snapshot.queryParamMap.get('paymentType') || '';
    this.rqst_param.delivery_fee = Number(this.route.snapshot.queryParamMap.get('deliveryFee') || 0);
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
      this.networkService.post_request(this.rqst_param, GlobalComponent.finalizePayment)
        .subscribe(({
          next: (response) => {
            if (response.status === "SUCCESS") {
              this.ui_controls.confirming_transaction = false;
              this.router.navigate(['/success'], {replaceUrl: true});
            }
            if (response.status === "FAILED") {
              this.ui_controls.confirming_transaction = false;
              this.router.navigate(['/failed'], {replaceUrl: true});
            }
            if (response.status === "UNKNOWN") {
              this.ui_controls.confirming_transaction = false;
              this.router.navigate(['/failed'], {replaceUrl: true});
            }
            if (response.status === "ERROR") {
              this.ui_controls.confirming_transaction = false;
              this.router.navigate(['/failed'], {replaceUrl: true});
            }
          },
          error: (e) => {
            this.ui_controls.confirming_transaction = false;
            this.router.navigate(['/failed'], {replaceUrl: true});
          },
          complete: () => {
            console.info('complete');
          }
        }))
  }
}
