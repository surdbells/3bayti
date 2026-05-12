/**
 * Public API for the abayti mobile icon primitive.
 *
 * Callers import from this barrel:
 *
 *   import { AxIconComponent } from '@/shared/ax-mobile/icon';
 *
 * (or via relative path, depending on tsconfig).
 */

export { AxIconComponent } from './ax-icon.component';
export { provideAxIcons } from './provide-ax-icons';
export {
  AX_ICON_SIZES,
  AX_ICON_STROKES,
  type AxIconSize,
  type AxIconStrokeWidth,
} from './ax-icon.types';
