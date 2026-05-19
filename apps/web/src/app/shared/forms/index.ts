/**
 * Public surface of the shared/forms module.
 *
 * App code imports primitives from this barrel; tests can still reach
 * into individual files for unit-test mocking.
 */

export { FormFieldComponent } from './form-field/form-field';
export {
  PhoneInputComponent,
  PHONE_COUNTRIES,
  DEFAULT_COUNTRY_CODE,
  parseE164,
} from './phone-input/phone-input';
export type { PhoneCountry } from './phone-input/phone-input';
export { PasswordStrengthComponent, loadPasswordScorer } from './password-strength/password-strength';
export { ToastService } from './toast/toast.service';
export type { Toast, ToastInput } from './toast/toast.service';
export { ToastContainerComponent } from './toast/toast.component';
export { mapApiErrors } from './api-error-mapping';
export type { ApiErrorMapping, MapApiErrorsResult } from './api-error-mapping';
