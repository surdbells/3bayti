import { Component, inject } from '@angular/core';
import {NgFor, AsyncPipe, CommonModule} from '@angular/common';
import { I18nService, LangCode } from './i18n.service';

@Component({
  selector: 'app-language-switcher',
  standalone: true,
  imports: [NgFor, AsyncPipe, CommonModule],
  template: `
    <div class="lang-switcher">
      <button class="btn" type="button" (click)="open = !open" [attr.aria-expanded]="open">
        <span class="label">{{ currentLabel() }}</span>
        <span class="chev" aria-hidden="true">▾</span>
      </button>

      <ul *ngIf="open" class="menu" role="listbox" (keydown.escape)="open = false">
        <li *ngFor="let l of langs"
            role="option"
            [attr.aria-selected]="(i18n.lang$ | async) === l.code"
            (click)="select(l.code)">
          <span class="flag">{{ l.flag }}</span>
          <span class="text">{{ l.label }} <small class="native">({{ l.native }})</small></span>
        </li>
      </ul>
    </div>
  `,
  styles: [`
    .lang-switcher { position: relative; display: inline-block; }
    .btn { padding:.5rem .75rem; border:1px solid #ddd; border-radius:.5rem; background:#fff; cursor:pointer; }
    .menu { position:absolute; right:0; z-index:10; margin-top:.25rem; list-style:none; padding:.25rem;
            border:1px solid #ddd; border-radius:.5rem; background:#fff; min-width:12rem;
            box-shadow:0 10px 30px rgba(0,0,0,.08); }
    .menu li { padding:.5rem .75rem; cursor:pointer; display:flex; gap:.5rem; align-items:center; }
    .menu li[aria-selected="true"] { font-weight:600; }
    .flag { width:1.25rem; display:inline-block; text-align:center; }
    .native { opacity:.7; }
    /* RTL support for the control itself */
    :host-context(html[dir="rtl"]) .menu,
    :host-context(html[dir="rtl"]) .btn { direction: rtl; }
  `]
})
export class LanguageSwitcherComponent {
  i18n = inject(I18nService);
  open = false;

  langs = [
    { code: 'en' as LangCode, label: 'English', native: 'English',   flag: '🇺🇸' },
    { code: 'ar' as LangCode, label: 'Arabic',  native: 'العربية',   flag: '🇦🇪' }
  ];

  select(code: LangCode) {
    this.i18n.use(code);
    this.open = false;
  }

  currentLabel() {
    const active = this.langs.find(l => l.code === this.i18n.lang);
    return active ? `${active.flag} ${active.label}` : 'Language';
  }
}
