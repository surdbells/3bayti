/**
 * Shared UI primitives — building blocks used across the site.
 *
 * Import like:
 *   import { ButtonComponent, ContainerComponent, HeadingComponent } from '../shared/ui';
 *
 * All primitives are standalone, OnPush, and SSR-safe.
 */

export { ButtonComponent } from './button';
export { ContainerComponent } from './container';
export { HeadingComponent } from './heading';
export { TextComponent } from './text';
export { StackComponent } from './stack';
export { SectionHeaderComponent } from './section-header';
export { SkeletonShimmerComponent } from './skeleton-shimmer';
export { ConfirmModalComponent } from './confirm-modal';
/* ProductStripComponent is exported separately because it depends on
 * features/catalog (ProductCard) and we don't want to introduce a
 * shared → features dependency through the barrel. Consumers import
 * it directly from './product-strip'. */
