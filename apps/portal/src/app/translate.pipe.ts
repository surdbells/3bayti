import { Pipe, PipeTransform, inject } from '@angular/core';
import { I18nService } from './i18n.service';

@Pipe({
  name: 'translate',
  standalone: true,
  pure: false // re-evaluates when language changes
})
export class TranslatePipe implements PipeTransform {
  private i18n = inject(I18nService);
  transform(key: string, params?: Record<string, any>) {
    return this.i18n.t(key, params);
  }
}
