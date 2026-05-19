import { Component, ChangeDetectionStrategy } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { LocaleSwitcherComponent } from './locale-switcher';

/**
 * Site-wide header. Persistent across all pages, sticky to the viewport top.
 *
 * Phase 1 (M3.2.0): brand mark + minimal nav placeholder.
 * Phase Y.1-A: + locale switcher (EN ⇄ AR). Auth CTAs land in Y.1-I.
 *
 * The TranslateModule import gives the template access to the `translate`
 * pipe. All visible strings come from public/i18n/<locale>.json via
 * LocaleService → TranslateService.
 */
@Component({
  selector: 'app-header',
  standalone: true,
  imports: [RouterLink, TranslateModule, LocaleSwitcherComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './header.html',
  styleUrl: './header.scss',
})
export class HeaderComponent {}
