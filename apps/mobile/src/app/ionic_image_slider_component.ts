/* app-image-slider.component.ts
   Simple, robust drop-in. No IntersectionObserver.
   Usage:
     <app-image-slider [images]="test_images"></app-image-slider>
     <app-image-slider [images]="test_images" [direction]="'vertical'"></app-image-slider>
*/

import { Component, Input, AfterViewInit, ViewChild, ElementRef, ChangeDetectorRef, HostListener } from '@angular/core';
import {IonicModule} from "@ionic/angular";

@Component({
  selector: 'app-image-slider',
  standalone: true,
  template: `
    <div class="ais-wrapper">
      <div class="ais-controls">
        <ion-button size="small" fill="outline" (click)="toggleDirection()">
          Switch to {{ direction === 'vertical' ? 'horizontal' : 'vertical' }}
        </ion-button>
        <ion-button size="small" fill="clear" (click)="prev()">Prev</ion-button>
        <ion-button size="small" fill="clear" (click)="next()">Next</ion-button>
      </div>

      <div #slidesContainer
           class="ais-slides-container"
           [class.vertical]="direction === 'vertical'"
           role="region"
           aria-label="Product images"
           tabindex="0">
        <div class="ais-slide"
             *ngFor="let img of images; let i = index"
             [class.active]="i === activeIndex"
             (click)="goTo(i)">
          <ion-img [src]="img" [alt]="'image ' + (i+1)" class="ais-main-image"></ion-img>
        </div>
      </div>

      <div class="ais-thumbs" *ngIf="images?.length">
        <div class="ais-thumb"
             *ngFor="let img of images; let i = index"
             (click)="goTo(i)"
             [class.active]="i === activeIndex"
             role="button"
             tabindex="0">
          <ion-img [src]="img" [alt]="'thumb ' + (i+1)"></ion-img>
        </div>
      </div>
    </div>
  `,
  imports: [
    IonicModule
  ],
  styles: [`
    :host {
      display: block;
    }

    .ais-wrapper {
      --thumb-size: 72px;
      --main-height: 360px;
    }

    .ais-controls {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-bottom: 8px;
    }

    .ais-slides-container {
      display: flex;
      overflow: auto;
      scroll-behavior: smooth;
      -webkit-overflow-scrolling: touch;
      scroll-snap-type: x mandatory;
      gap: 8px;
      height: var(--main-height);
      border-radius: 8px;
      background: #fff;
      padding: 8px;
    }

    .ais-slides-container.vertical {
      flex-direction: column;
      scroll-snap-type: y mandatory;
      height: calc(var(--main-height) + 40px);
    }

    .ais-slide {
      flex: 0 0 100%;
      scroll-snap-align: center;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 100%;
      min-height: 100%;
      border-radius: 6px;
      overflow: hidden;
      background: #f6f6f6;
      position: relative;
      box-sizing: border-box;
    }

    .ais-slides-container.vertical .ais-slide {
      min-height: var(--main-height);
      min-width: 100%;
    }

    .ais-main-image {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .ais-thumbs {
      display: flex;
      gap: 8px;
      margin-top: 10px;
      overflow-x: auto;
      padding-bottom: 6px;
    }

    .ais-thumb {
      width: var(--thumb-size);
      height: var(--thumb-size);
      border-radius: 6px;
      overflow: hidden;
      flex: 0 0 auto;
      cursor: pointer;
      border: 2px solid transparent;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    }

    .ais-thumb ion-img, .ais-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .ais-thumb.active {
      border-color: var(--ion-color-primary, #3880ff);
      transform: scale(1.03);
    }

    .ais-slide.active {
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
    }
  `]
})
export class ImageSliderComponent implements AfterViewInit {
  @Input() images: string[] = [];
  @Input() direction: 'horizontal' | 'vertical' = 'horizontal';

  @ViewChild('slidesContainer', { static: true }) slidesContainer!: ElementRef<HTMLElement>;

  activeIndex = 0;

  constructor(private cd: ChangeDetectorRef) {}

  ngAfterViewInit(): void {
    // If images were already supplied, ensure first slide visible.
    setTimeout(() => this.ensureVisible(), 50);
  }

  private ensureVisible() {
    // If no images, nothing to do
    if (!this.images || !this.images.length) return;
    this.scrollTo(this.activeIndex, false);
  }

  toggleDirection() {
    this.direction = this.direction === 'vertical' ? 'horizontal' : 'vertical';
    setTimeout(() => this.scrollTo(this.activeIndex, false), 80);
  }

  goTo(index: number) {
    if (!this.images || !this.images.length) return;
    this.activeIndex = Math.max(0, Math.min(this.images.length - 1, index));
    this.scrollTo(this.activeIndex, true);
  }

  next() { this.goTo(this.activeIndex + 1); }
  prev() { this.goTo(this.activeIndex - 1); }

  private scrollTo(index: number, smooth = true) {
    const container = this.slidesContainer.nativeElement;
    const slides = container.querySelectorAll('.ais-slide');
    if (!slides || slides.length === 0) return;
    const slide = slides[index] as HTMLElement | undefined;
    if (!slide) return;
    slide.scrollIntoView({
      behavior: smooth ? 'smooth' : 'auto',
      block: this.direction === 'vertical' ? 'center' : 'nearest',
      inline: this.direction === 'vertical' ? 'nearest' : 'center'
    });
    // mark active for UI
    this.activeIndex = index;
    this.cd.detectChanges();
    // center thumbnail strip if present
    this.centerThumb(index);
  }

  private centerThumb(index: number) {
    try {
      const wrapper = this.slidesContainer.nativeElement.parentElement as HTMLElement;
      const thumbs = wrapper.querySelector('.ais-thumbs') as HTMLElement | null;
      if (!thumbs) return;
      const activeThumb = thumbs.children[index] as HTMLElement | null;
      if (!activeThumb) return;
      const margin = (thumbs.clientWidth - activeThumb.clientWidth) / 2;
      const left = activeThumb.offsetLeft - margin;
      thumbs.scrollTo({ left, behavior: 'smooth' });
    } catch (e) { /* ignore */ }
  }

  // keyboard navigation support
  @HostListener('window:keydown', ['$event'])
  handleKeyDown(ev: KeyboardEvent) {
    if (this.direction === 'vertical') {
      if (ev.key === 'ArrowDown') this.next();
      else if (ev.key === 'ArrowUp') this.prev();
    } else {
      if (ev.key === 'ArrowRight') this.next();
      else if (ev.key === 'ArrowLeft') this.prev();
    }
  }
}
