import { Component } from '@angular/core';
import {
  IonButton,
  IonContent
} from '@ionic/angular/standalone';
import { RouterLink } from "@angular/router";
import { TranslatePipe } from "../../translate.pipe";
import { AxIconComponent } from '../../shared/ax-mobile/icon';

@Component({
  selector: 'app-success',
  templateUrl: './success.page.html',
  standalone: true,
  imports: [IonContent, IonButton, RouterLink, TranslatePipe, AxIconComponent]
})
export class SuccessPage {}
