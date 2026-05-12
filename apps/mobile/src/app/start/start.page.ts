import {
  AfterViewInit,
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  ViewChild,
  signal
} from '@angular/core';

import { IonContent, IonButton } from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { register } from 'swiper/element/bundle';
import { AxIconComponent } from '../shared/ax-mobile/icon';
register();

@Component({
  standalone: true,
  selector: 'app-start',
  imports: [IonContent, IonButton, AxIconComponent],
  templateUrl: './start.page.html',
  styleUrls: ['./start.page.scss'],
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
})
export class StartPage implements AfterViewInit {
  @ViewChild('swiper', { static: true }) swiperEl!: ElementRef<HTMLElement>;
  index = signal(0);
  constructor(private router: Router) {}
  ngAfterViewInit(): void {
    // Wait until the web component attaches the Swiper instance
    const el = this.swiperEl.nativeElement as any;
    const attach = () => {
      const sw: any = el.swiper;
      if (!sw) {
        // instance not ready yet; try again shortly
        setTimeout(attach, 30);
        return;
      }
      // Set the initial index
      this.index.set(sw.activeIndex ?? 0);

      // Subscribe to slide change via Swiper API (most reliable)
      sw.on('slideChange', () => {
        this.index.set(sw.activeIndex ?? 0);
      });
    };

    attach();
  }
  next(swiperElRef: HTMLElement) {
    const sw: any = (swiperElRef as any).swiper;
    if (!sw) return;

    if (this.index() === 2) {
      this.router.navigate(['/', 'welcome']);
      return;
    }

    sw.slideNext();

    // After motion, snapshot the new index (in case event timing differs)
    setTimeout(() => {
      this.index.set(sw.activeIndex ?? 0);
    }, 0);
  }
}
