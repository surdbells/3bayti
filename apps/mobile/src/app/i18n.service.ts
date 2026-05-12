import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, firstValueFrom } from 'rxjs';

export type LangCode = 'en' | 'ar';
type Dict = Record<string, string>;

const STORAGE_KEY = 'app_lang';

@Injectable({ providedIn: 'root' })
export class I18nService {
  private http = inject(HttpClient);

  private _lang$ = new BehaviorSubject<LangCode>('en');
  readonly lang$ = this._lang$.asObservable();

  private _dict: Dict = {};

  get lang(): LangCode {
    return this._lang$.value;
  }

  async init() {
    const saved = (localStorage.getItem(STORAGE_KEY) as LangCode) || 'en';
    await this.use(saved);
  }

  async use(lang: LangCode) {
    try {
      const dict = await firstValueFrom(this.http.get<Dict>(`assets/i18n/${lang}.json`));
      this._dict = dict ?? {};
      this._lang$.next(lang);
      localStorage.setItem(STORAGE_KEY, lang);

      // Make Ionic components respect direction
      document.documentElement.setAttribute('lang', lang);
      document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
    } catch (e) {
      console.error('i18n load failed', e);
      if (lang !== 'en') await this.use('en');
    }
  }

  t(key: string, params?: Record<string, any>): string {
    const raw = this._dict[key] ?? key;
    if (!params) return raw;
    return raw.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, m) => params[m] != null ? String(params[m]) : '');
  }
}
