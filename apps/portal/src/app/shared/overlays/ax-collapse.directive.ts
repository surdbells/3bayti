import {
  Directive,
  ElementRef,
  Input,
  OnChanges,
  SimpleChanges,
  inject,
} from '@angular/core';

/**
 * Toggles the expanded/collapsed state of an inline element by animating
 * its height. Useful for inline details sections, row expand/collapse,
 * filter panels.
 *
 *   <section [axCollapse]="isOpen">...</section>
 *   <button (click)="isOpen = !isOpen">Toggle</button>
 *
 * Collapsed (`false`) sets height 0 + overflow hidden. Expanded (`true`)
 * animates to the natural scrollHeight then to 'auto' once the transition
 * completes (so nested content can grow freely).
 */
@Directive({
  selector: '[axCollapse]',
  standalone: true,
})
export class AxCollapseDirective implements OnChanges {
  @Input('axCollapse') expanded = false;

  private readonly hostRef = inject(ElementRef<HTMLElement>);
  private host!: HTMLElement;

  ngOnChanges(changes: SimpleChanges): void {
    this.host = this.hostRef.nativeElement;

    if (!changes['expanded']) return;

    // Apply base styles lazily once we know we're animating
    this.host.classList.add('ax-collapse');

    if (this.expanded) {
      this.expand();
    } else {
      this.collapse();
    }
  }

  private expand(): void {
    // From whatever current height -> scrollHeight, then set to 'auto'
    const target = this.host.scrollHeight;
    this.host.style.height = target + 'px';
    const onEnd = () => {
      this.host.style.height = 'auto';
      this.host.removeEventListener('transitionend', onEnd);
    };
    this.host.addEventListener('transitionend', onEnd);
  }

  private collapse(): void {
    // If it's at 'auto', we need to snap to a pixel value first so the
    // transition has a starting point.
    const current = this.host.scrollHeight;
    this.host.style.height = current + 'px';
    // Force reflow
    this.host.offsetHeight;
    requestAnimationFrame(() => {
      this.host.style.height = '0px';
    });
  }
}
