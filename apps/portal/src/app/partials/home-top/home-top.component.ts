import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { TranslatePipe } from '../../translate.pipe';

import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-home-top',
  standalone: true,
  imports: [CommonModule, RouterLink, LanguageSwitcherComponent, TranslatePipe, IconComponent],
  templateUrl: './home-top.component.html',
  styleUrl: './home-top.component.css',
})
export class HomeTopComponent {
  menuOpen = false;
}
