/**
 * Public types for the abayti mobile text field primitive.
 */

/**
 * Supported native input types.
 *
 * Note: when `multiline` is true on the component, this type is ignored
 * (textareas don't take a type attribute).
 */
export type AxTextFieldType =
  | 'text'
  | 'email'
  | 'password'
  | 'tel'
  | 'number'
  | 'url'
  | 'search';

/** Native inputmode hints (subset we expose). */
export type AxTextFieldInputMode =
  | 'text'
  | 'email'
  | 'tel'
  | 'numeric'
  | 'decimal'
  | 'search'
  | 'url';
