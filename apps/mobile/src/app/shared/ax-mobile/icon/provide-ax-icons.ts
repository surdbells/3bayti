/**
 * provideAxIcons — registers all icons in the abayti icon set with
 * lucide-angular's icon-provider system.
 *
 * Wire into main.ts providers:
 *
 *   import { provideAxIcons } from './app/shared/ax-mobile/icon';
 *
 *   bootstrapApplication(AppComponent, {
 *     providers: [
 *       provideAxIcons(),
 *       // ... other providers
 *     ],
 *   });
 *
 * Internally, this binds the LUCIDE_ICONS injection token (which
 * lucide-angular looks up at component-construction time) to a
 * LucideIconProvider holding our full icon set. The provider is
 * `multi: true`, so multiple `provideAxIcons()` calls (or other
 * code registering its own icons) compose cleanly.
 */

import type { Provider } from '@angular/core';
import { LUCIDE_ICONS, LucideIconProvider } from 'lucide-angular';

import { AX_ICON_SET } from './icon-set';

export function provideAxIcons(): Provider {
  return {
    provide: LUCIDE_ICONS,
    multi: true,
    useValue: new LucideIconProvider(AX_ICON_SET),
  };
}
