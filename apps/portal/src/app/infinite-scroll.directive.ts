import { Directive, ElementRef, EventEmitter, Input, OnChanges, OnDestroy, Output, SimpleChanges } from '@angular/core';

@Directive({standalone: true, selector: '[appInfiniteScroll]'})
export class InfiniteScrollDirective implements OnChanges, OnDestroy {
  @Input() canLoadMore = true;                 // set false when done
  @Input() root: Element | null = null;    // null = window; or pass a container
  @Input() rootMargin = '300px';               // prefetch before bottom
  @Output() loadMore = new EventEmitter<void>();

  private observer?: IntersectionObserver;

  constructor(private host: ElementRef<HTMLElement>) {}

  ngOnChanges(_: SimpleChanges) {
    this.disconnect();
    this.observer = new IntersectionObserver(
      (entries) => {
        if (!this.canLoadMore) return;
        for (const e of entries) if (e.isIntersecting) { this.loadMore.emit(); break; }
      },
      { root: this.root ?? null, rootMargin: this.rootMargin, threshold: 0 }
    );
    this.observer.observe(this.host.nativeElement);
  }

  ngOnDestroy() { this.disconnect(); }
  private disconnect() { this.observer?.disconnect(); this.observer = undefined; }
}
