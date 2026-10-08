import { CanDeactivateFn } from '@angular/router';

/** A routed component that can veto leaving (e.g. it has unsaved changes). */
export interface HasUnsavedChanges {
  /** true / resolves true to allow leaving; false keeps the user on the page. */
  canDeactivate(): boolean | Promise<boolean>;
}

/**
 * Functional CanDeactivate guard: asks the component whether it may be left.
 * The component owns the prompt (e.g. an AxConfirmService dialog), so the
 * guard stays generic. Pair with `runGuardsAndResolvers:
 * 'paramsOrQueryParamsChange'` when the page is re-used for another record
 * via query params, so switching records is guarded too.
 */
export const unsavedChangesGuard: CanDeactivateFn<HasUnsavedChanges> = (component) =>
  component?.canDeactivate ? component.canDeactivate() : true;
