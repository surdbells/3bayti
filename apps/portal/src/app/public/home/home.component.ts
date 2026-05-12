import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { HomeTopComponent } from '../../partials/home-top/home-top.component';
import { TranslatePipe } from '../../translate.pipe';
import {
  AxAccordionComponent,
  AxAccordionItemComponent,
  AxTabsComponent,
  AxTabComponent,
} from '../../shared/overlays';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    CommonModule,
    HomeTopComponent,
    RouterLink,
    TranslatePipe,
    AxAccordionComponent,
    AxAccordionItemComponent,
    AxTabsComponent,
    AxTabComponent,
  ],
  templateUrl: './home.component.html',
  styleUrl: './home.component.css',
})
export class HomeComponent {
  constructor() {}
}
