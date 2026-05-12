import { Component, OnDestroy } from '@angular/core';
import { IonButton, IonContent, Platform } from '@ionic/angular/standalone';
import {Subscription} from "rxjs";
import {ConnectionService} from "../service/connection.service";
import {Router} from "@angular/router";
import {TranslatePipe} from "../translate.pipe";


@Component({
  selector: 'app-welcome',
  templateUrl: './welcome.page.html',
  styleUrls: ['./welcome.page.scss'],
  imports: [
    IonContent,
    IonButton,
    TranslatePipe,
  ],
  standalone: true
})
export class WelcomePage implements OnDestroy {
  isOnline = true;
  private sub: Subscription;
  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private router: Router
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  ui_controls = {
    page_loading: false,
    login_loading: false,
    logged_in: false
  };

  start_shopping() {
    this.router.navigate(['/', 'login']);
  }
  explore() {
    this.router.navigate(['/', 'home']);
  }

}
