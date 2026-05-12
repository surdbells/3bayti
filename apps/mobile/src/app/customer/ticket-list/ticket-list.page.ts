import { Component, OnInit } from '@angular/core';
import {FormBuilder, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ActionSheetController} from "@ionic/angular";
import {Router, RouterLink} from "@angular/router";
import {CommonModule, DatePipe} from "@angular/common";
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from "@ionic/angular/standalone";
import {ConnectionService} from "../../service/connection.service";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Subscription} from "rxjs";
import {GlobalComponent} from "../../global-component";
import {Preferences} from "@capacitor/preferences";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
interface Ticket {
  id: number;
  subject: string;
  summary: string;
  status: 'Open' | 'Pending' | 'Resolved';
  priority?: 'Low' | 'Medium' | 'High';
  created: Date;
  email?: string;
}

@Component({
  selector: 'app-ticket-list',
  templateUrl: './ticket-list.page.html',
  standalone: true,
  styleUrls: ['./ticket-list.page.scss'],
  imports: [
    IonContent,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButton,
    IonButtons,
    CommonModule,
    RouterLink,
    DatePipe,
    ReactiveFormsModule,
    TranslatePipe, AxIconComponent, AxLoaderComponent]
})
export class TicketListPage implements OnInit {
  tickets: Ticket[] = [];
  isOnline = true;
  isWishOpen = false; // or control this as you like
  private sub: Subscription;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private fb: FormBuilder
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ui_controls = {
    is_loading: false,
    is_empty: false
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
  user_tickets = {
    id: 0,
    token: ""
  }
  ngOnInit() {
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.user_tickets.id = this.single_user.id
      this.user_tickets.token = this.single_user.token;
      this.get_tickets();
    }
  }
  get_tickets() {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.networkService.post_request(this.user_tickets, GlobalComponent.readTicket)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.tickets =  response.data;
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = false;
          }else{
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  openTicket(ticket: number, subject: string) {
    this.router.navigate(['/', 'ticketmessages'],
      { queryParams: { ticket, subject } }
    );
  }
}
