import {
  AfterViewInit,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ElementRef,
  NgZone,
  OnDestroy,
  ViewChild,
} from '@angular/core';
import {fromEvent, Subscription} from 'rxjs';
import {throttleTime} from 'rxjs/operators';

@Component({
  selector: 'app-h-scroll-progress',
  templateUrl: './h-scroll-progress.component.html',
  styleUrls: ['./h-scroll-progress.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  standalone: true
})
export class HScrollProgressComponent implements AfterViewInit, OnDestroy {
  @ViewChild('scrollEl', { static: true }) scrollElRef!: ElementRef<HTMLElement>;

  progress = 10;
  isScrollable = false;

  private sub = new Subscription();
  private rafId = 0;

  constructor(private ngZone: NgZone, private cdr: ChangeDetectorRef) {}

  ngAfterViewInit(): void {
    const el = this.scrollElRef.nativeElement;

    // Check initial scrollability and progress
    this.updateScrollStatusAndProgress();

    // Listen to scroll events (outside Angular for perf)
    this.ngZone.runOutsideAngular(() => {
      const scroll$ = fromEvent(el, 'scroll', { passive: true } as any).pipe(throttleTime(16));
      this.sub.add(
        scroll$.subscribe(() => {
          if (this.rafId) cancelAnimationFrame(this.rafId);
          this.rafId = requestAnimationFrame(() => {
            this.updateProgress();
          });
        })
      );
    });

    // Listen to window resize/orientation
    const resize$ = fromEvent(window, 'resize').pipe(throttleTime(100));
    this.sub.add(resize$.subscribe(() => this.updateScrollStatusAndProgress()));

    // Watch for content changes
    const mo = new MutationObserver(() => this.updateScrollStatusAndProgress());
    mo.observe(el, { childList: true, subtree: true });
    this.sub.add({ unsubscribe: () => mo.disconnect() });
  }

  private updateScrollStatusAndProgress() {
    const el = this.scrollElRef.nativeElement;
    this.isScrollable = el.scrollWidth > el.clientWidth + 1;
    this.updateProgress();
  }

  private updateProgress() {
    const el = this.scrollElRef.nativeElement;
    const maxScroll = el.scrollWidth - el.clientWidth;
    const percent = maxScroll > 0 ? (el.scrollLeft / maxScroll) * 100 : 0;

    this.ngZone.run(() => {
      this.progress = Math.min(100, Math.max(0, Number(percent.toFixed(2))));
      this.cdr.markForCheck();
    });
  }

  ngOnDestroy(): void {
    this.sub.unsubscribe();
    if (this.rafId) cancelAnimationFrame(this.rafId);
  }
}
