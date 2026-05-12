import {Component, inject} from '@angular/core';
import { I18nService, LangCode } from './i18n.service';
import { IonicModule } from '@ionic/angular';
@Component({
  selector: 'app-language-switcher',
  standalone: true,
  imports: [IonicModule],
  template: `
      <div class="language-pills">
        @for (l of langs; track l) {
          <button
            [value]="l.code"
            type="button"
            class="lang-pill"
            [class.active]="this.i18n.lang === l.code"
            (click)="select(l.code)">
            <span><span style="font-size: 32px; font-weight: bolder;">{{ l.flag }}</span><br> {{ l.native }}</span>
          </button>
        }
      </div>
      `,
  styles: [`
    .language-pills {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 14px;
    }
    .lang-pill {
      color: #ffffff;
      display: block;
      align-items: center;
      gap: 10px;
      background-color: #000000;
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      padding: 12px 14px;
      box-shadow: var(--shadow-sm);
      font-weight: 700;
    }
    .lang-pill .dot {
      width: 12px; height: 12px; border-radius: 999px; background: var(--ink);
      box-shadow: inset 0 0 0 2px #fff4;
    }
    .lang-pill.active {
      outline: 3px solid rgba(91, 68, 55, .12);
      box-shadow: 0 8px 20px rgba(91,68,55,.18);
    }
  `]
})
export class LanguageSwitcherComponent {
  i18n = inject(I18nService);
  langs = [
    { code: 'en' as LangCode, label: 'English', native: 'English', flag: '🇺🇸' },
    { code: 'ar' as LangCode, label: 'Arabic',  native: 'العربية', flag: '🇦🇪' }
  ];

  select(code: LangCode) {
    this.i18n.use(code);
  }
}
