import { Component, OnInit } from '@angular/core';
import { NgIf } from '@angular/common';
import {
  IonButton,
  IonContent
} from '@ionic/angular/standalone';
import { ActivatedRoute, RouterLink } from "@angular/router";
import { TranslatePipe } from "../../translate.pipe";
import { AxIconComponent } from '../../shared/ax-mobile/icon';

@Component({
  selector: 'app-success',
  templateUrl: './success.page.html',
  standalone: true,
  imports: [IonContent, IonButton, RouterLink, TranslatePipe, AxIconComponent, NgIf]
})
export class SuccessPage implements OnInit {
  orderReference = '';
  giftCardPaid = false;

  constructor(private route: ActivatedRoute) {}

  ngOnInit(): void {
    const params = this.route.snapshot.queryParamMap;
    this.orderReference = params.get('orderReference') || '';
    this.giftCardPaid = params.get('gift_card_paid') === 'true' || params.get('gift_card_paid') === '1';
  }
}
