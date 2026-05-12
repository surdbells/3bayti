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

  /** Call once at app startup */
  async init() {
    const saved = (localStorage.getItem(STORAGE_KEY) as LangCode) || 'en';
    await this.use(saved);
  }

  /** Switch language and load its dictionary */
  async use(lang: LangCode) {
    const url = `assets/i18n/${lang}.json`;
    try {
      const dict = await firstValueFrom(this.http.get<Dict>(url));
      this._dict = dict ?? {};
      this._lang$.next(lang);
      localStorage.setItem(STORAGE_KEY, lang);

      // Update document language + direction
      document.documentElement.setAttribute('lang', lang);
      document.documentElement.setAttribute('dir', lang === 'ar' ? 'ltr' : 'ltr');
    } catch (err) {
      console.error('Failed to load language file:', err);
      // Optional: fallback to English if requested lang fails
      if (lang !== 'en') await this.use('en');
    }
  }

  /** Translate a key with optional {{placeholders}} */
  t(key: string, params?: Record<string, any>): string {
    const raw = this._dict[key] ?? key;
    if (!params) return raw;
    return raw.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, m) =>
      params[m] != null ? String(params[m]) : ''
    );
  }
}
