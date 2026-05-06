import { Component, ChangeDetectionStrategy, Input } from '@angular/core';

/**
 * Stack — vertical or horizontal flex container with consistent gap.
 *
 * Replaces the common pattern of "div with display:flex and a gap I
 * keep typing the same numbers for." Standardizes spacing across the
 * site and lets us refactor the spacing scale in one place if we ever
 * need to tighten/loosen the design.
 *
 * Direction defaults to vertical (column). Set `direction="horizontal"`
 * for a row.
 *
 * Gap presets follow an 8px grid:
 *   xs   4px
 *   sm   8px
 *   md   16px (default)
 *   lg   24px
 *   xl   40px
 *   2xl  64px
 *
 * Usage:
 *   <ui-stack gap="lg">
 *     <h2>Title</h2>
 *     <p>Body</p>
 *     <ui-button>CTA</ui-button>
 *   </ui-stack>
 *
 *   <ui-stack direction="horizontal" gap="sm" align="center">
 *     <icon />
 *     <span>Label</span>
 *   </ui-stack>
 */
@Component({
  selector: 'ui-stack',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<ng-content />`,
  styles: [`
    :host {
      display: flex;
    }
    :host([data-dir='vertical'])   { flex-direction: column; }
    :host([data-dir='horizontal']) { flex-direction: row; }

    :host([data-gap='xs'])  { gap: 4px; }
    :host([data-gap='sm'])  { gap: 8px; }
    :host([data-gap='md'])  { gap: 16px; }
    :host([data-gap='lg'])  { gap: 24px; }
    :host([data-gap='xl'])  { gap: 40px; }
    :host([data-gap='2xl']) { gap: 64px; }

    :host([data-align='start'])    { align-items: flex-start; }
    :host([data-align='center'])   { align-items: center; }
    :host([data-align='end'])      { align-items: flex-end; }
    :host([data-align='stretch'])  { align-items: stretch; }

    :host([data-justify='start'])   { justify-content: flex-start; }
    :host([data-justify='center'])  { justify-content: center; }
    :host([data-justify='end'])     { justify-content: flex-end; }
    :host([data-justify='between']) { justify-content: space-between; }

    :host([data-wrap='true']) { flex-wrap: wrap; }
  `],
  host: {
    '[attr.data-dir]':     'direction',
    '[attr.data-gap]':     'gap',
    '[attr.data-align]':   'align',
    '[attr.data-justify]': 'justify',
    '[attr.data-wrap]':    'wrap',
  },
})
export class StackComponent {
  @Input() direction: 'vertical' | 'horizontal' = 'vertical';
  @Input() gap: 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' = 'md';
  @Input() align: 'start' | 'center' | 'end' | 'stretch' = 'stretch';
  @Input() justify: 'start' | 'center' | 'end' | 'between' = 'start';
  @Input() wrap = false;
}
