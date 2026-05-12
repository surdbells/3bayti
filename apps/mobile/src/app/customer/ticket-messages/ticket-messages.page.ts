import {Component, HostListener, OnDestroy, OnInit, ViewChild} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonFooter,
  IonHeader,
  IonTitle,
  IonToolbar, NavController, Platform
} from '@ionic/angular/standalone';
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router, RouterLink} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {TranslatePipe} from "../../translate.pipe";
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
export interface Messages {
  message: string;
  timestamp: string;
  currentId: number;
}
@Component({
  selector: 'app-conversations',
  templateUrl: './ticket-messages.page.html',
  styleUrls: ['./ticket-messages.page.scss'],
  standalone: true,
  imports: [IonContent, IonHeader, IonTitle, IonToolbar, FormsModule, IonFooter, IonButton, IonButtons, RouterLink, TranslatePipe, AxIconComponent, AxLoaderComponent]
})
export class TicketMessagesPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  private backSub?: Subscription;
  messages: Messages[] = [];
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private route: ActivatedRoute,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private toast: AxNotificationService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ui_controls = {
    is_loading: false,
    sending: false
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
  delete = {
    id: 0,
    token: '',
    review: 0
  };
  ngOnInit() {
    this.request.ticket = Number(this.route.snapshot.queryParamMap.get('ticket'));
    this.request.subject = this.route.snapshot.queryParamMap.get('subject') || '';
    this.getObject();
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  request = {
    id: 0,
    token: "",
    ticket: 0,
    subject: ""
  }
  message = {
    id: 0,
    token: "",
    ticket: 0,
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

      this.get_conversations(true);
    }
  }
  IonOnViewDidEnter(){
    this.getObject();
  }
  get_conversations(show_loading: boolean = false) {
    if (show_loading){
      this.ui_controls.is_loading = true;
    }
    this.networkService.post_request(this.request, GlobalComponent.readTicketMessages)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.messages =  response.data;
            this.ui_controls.is_loading = false;
          }else{
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  send_message(){
    this.message.id = this.single_user.id;
    this.message.token = this.single_user.token;
    this.message.ticket = this.request.ticket;
    this.message.userId = this.single_user.id;
    if (this.message.message.length == 0){
      return;
    }
    this.ui_controls.sending = true;
    this.networkService.post_request(this.message, GlobalComponent.sendTicketMessage)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.message.message = "";
            this.ui_controls.sending = false;
            this.get_conversations();
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
}
