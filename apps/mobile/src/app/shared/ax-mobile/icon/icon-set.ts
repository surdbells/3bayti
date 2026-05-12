/**
 * The full set of lucide icons used by abayti mobile.
 *
 * Each entry maps the kebab-case template name (used by callers as
 * `<ax-icon name="...">`) to the lucide-angular PascalCase symbol that
 * loads the icon's SVG path data.
 *
 * Adding a new icon: import the symbol from 'lucide-angular' and add
 * the entry here. The `provideAxIcons()` provider auto-registers
 * everything in this map at app boot.
 *
 * Note on names: 'home' was renamed to 'house' in lucide. M4b's
 * migration script handles the kebab-case rename in templates;
 * callers should use 'house', not 'home'.
 *
 * Generated 2026-04-26 from the M4 audit (58 unique tui-icon names
 * across 39 page files, with home -> house substitution).
 */

import {
  ArrowLeft,
  ArrowRight,
  Banknote,
  Bell,
  Blocks,
  BrushCleaning,
  Camera,
  Check,
  CheckCheck,
  CheckLine,
  ChevronDown,
  ChevronRight,
  ChevronUp,
  CircleAlert,
  Clock,
  Download,
  Eye,
  EyeOff,
  FolderPlus,
  Globe,
  Headphones,
  Headset,
  Heart,
  HeartPlus,
  House,
  Image,
  Info,
  LogOut,
  Mail,
  MapPin,
  MessageCircle,
  MessageSquare,
  MessageSquareMore,
  MessageSquareText,
  Minus,
  Navigation,
  Package,
  PackageX,
  Pencil,
  PencilRuler,
  Plus,
  RotateCcw,
  Ruler,
  Search,
  Send,
  SendHorizontal,
  Settings,
  Shirt,
  ShoppingBag,
  ShoppingBasket,
  ShoppingCart,
  SlidersHorizontal,
  Sparkles,
  Star,
  Store,
  Tickets,
  Trash2,
  Truck,
  User,
  UserCheck,
  UserMinus,
  UserPen,
  X
} from 'lucide-angular';

import type { LucideIconData } from 'lucide-angular';

/**
 * The icon map. Lucide's per-icon symbols are LucideIconData arrays
 * (paths + attrs). Casting to LucideIconData here ensures a uniform
 * type even though lucide-angular's type-only exports vary.
 */
export const AX_ICON_SET: Record<string, LucideIconData> = {
  'arrow-left': ArrowLeft,
  'arrow-right': ArrowRight,
  'banknote': Banknote,
  'bell': Bell,
  'blocks': Blocks,
  'brush-cleaning': BrushCleaning,
  'camera': Camera,
  'check': Check,
  'check-check': CheckCheck,
  'check-line': CheckLine,
  'chevron-down': ChevronDown,
  'chevron-right': ChevronRight,
  'chevron-up': ChevronUp,
  'circle-alert': CircleAlert,
  'clock': Clock,
  'download': Download,
  'eye': Eye,
  'eye-off': EyeOff,
  'folder-plus': FolderPlus,
  'globe': Globe,
  'headphones': Headphones,
  'headset': Headset,
  'heart': Heart,
  'heart-plus': HeartPlus,
  'house': House,
  'image': Image,
  'info': Info,
  'log-out': LogOut,
  'mail': Mail,
  'map-pin': MapPin,
  'message-circle': MessageCircle,
  'message-square': MessageSquare,
  'message-square-more': MessageSquareMore,
  'message-square-text': MessageSquareText,
  'minus': Minus,
  'navigation': Navigation,
  'package': Package,
  'package-x': PackageX,
  'pencil': Pencil,
  'pencil-ruler': PencilRuler,
  'plus': Plus,
  'rotate-ccw': RotateCcw,
  'ruler': Ruler,
  'search': Search,
  'send': Send,
  'send-horizontal': SendHorizontal,
  'settings': Settings,
  'shirt': Shirt,
  'shopping-bag': ShoppingBag,
  'shopping-basket': ShoppingBasket,
  'shopping-cart': ShoppingCart,
  'sliders-horizontal': SlidersHorizontal,
  'sparkles': Sparkles,
  'star': Star,
  'store': Store,
  'tickets': Tickets,
  'trash-2': Trash2,
  'truck': Truck,
  'user': User,
  'user-check': UserCheck,
  'user-minus': UserMinus,
  'user-pen': UserPen,
  'x': X
};
