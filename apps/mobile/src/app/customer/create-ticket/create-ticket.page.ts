import {Component, OnDestroy, OnInit} from '@angular/core';

import {FormsModule} from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonSelect,
  IonSelectOption,
  IonTitle,
  IonToolbar
} from '@ionic/angular/standalone';
import {ActivatedRoute, Router, RouterLink} from "@angular/router";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {StoreRecord} from "../messages/messages.page";
import {NavController, Platform} from "@ionic/angular/standalone";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
@Component({
  selector: 'app-create-ticket',
  templateUrl: './create-ticket.page.html',
  styleUrls: ['./create-ticket.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButton,
    IonButtons,
    IonSelect,
    IonSelectOption,
    RouterLink,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AppTabBarComponent
  ]
})
export class CreateTicketPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  private backSub?: Subscription;
  stores: StoreRecord[] = [];
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private route: ActivatedRoute,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ui_controls = {
    is_loading: false
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
    is_customer: false
  }
  create = {
    id: 0,
    token: "",
    store: 0,
    subject: "",
    message: "",
  }
  ngOnInit() {
    this.getObject();
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  request = {
    id: 0,
    token: ""
  }
  message = {
    id: 0,
    token: "",
    storeId: 0,
    userId: 0,
    message: ""
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.request.id = this.single_user.id;
      this.request.token = this.single_user.token;
      this.get_vendors();
    }
  }
  IonOnViewDidEnter(){
    this.getObject();
  }
  get_vendors() {
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.request, GlobalComponent.readCustomerOrders)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.stores =  response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  create_ticket() {
    this.ui_controls.is_loading = true;
    this.create.id = this.single_user.id;
    this.create.token = this.single_user.token;
    if (this.create.subject.length == 0){
      this.error_notification(this.i18n.t('text_subject_required'));
      return;
    }
    if (this.create.message.length == 0){
      this.error_notification(this.i18n.t('text_message_required'));
      return;
    }
    if (this.create.store == 0){
      this.error_notification(this.i18n.t('text_reference_required'));
      return;
    }
    this.networkService.post_request(this.create, GlobalComponent.createTicket)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200) {
            this.success_notification(response.message)
            this.ui_controls.is_loading = false;
            this.router.navigate(['/', 'ticketlist']);
          }else{
            this.ui_controls.is_loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          this.ui_controls.is_loading = false;
          this.error_notification(this.i18n.t('text_unable_to_complete_request'));
          return;
        },
        complete: () => {
          this.ui_controls.is_loading = false;
          console.info('complete');
        }
      }))
  }
  orders() {
    this.router.navigate(['/', 'orders']);
  }
  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: "top-center"
    });
  }
}
