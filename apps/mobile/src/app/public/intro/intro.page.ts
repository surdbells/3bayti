import {
  AfterViewInit,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  CUSTOM_ELEMENTS_SCHEMA,
  ElementRef,
  OnDestroy,
  OnInit,
  ViewChild
} from '@angular/core';
import { Router } from "@angular/router";
import { Preferences } from "@capacitor/preferences";
import {
  IonButton,
  IonContent,
  IonFabButton,
  Platform
} from "@ionic/angular/standalone";
import { BlockerService } from "../../blocker.service";
import { CommonModule } from "@angular/common";
import { TranslatePipe } from "../../translate.pipe";
import { Haptics, ImpactStyle } from "@capacitor/haptics";
import { AxIconComponent } from '../../shared/ax-mobile/icon';
export interface IntroSlide {
  id: number;
  image: string;
  title: string;
  description: string;
  imageLoaded?: boolean;
}

@Component({
  selector: 'app-intro',
  templateUrl: './intro.page.html',
  styleUrls: ['./intro.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  schemas: [CUSTOM_ELEMENTS_SCHEMA],
  imports: [
    IonContent,
    IonButton,
    IonFabButton,
    CommonModule,
    TranslatePipe, AxIconComponent]
})
export class IntroPage implements OnInit, OnDestroy, AfterViewInit {
  @ViewChild('swiper') swiperRef!: ElementRef;

  slides: IntroSlide[] = [
    {
      id: 1,
      image: 'assets/img/intro/slide-1.jpg',
      title: 'slide1_title',
      description: 'slide1_desc',
      imageLoaded: false
    },
    {
      id: 2,
      image: 'assets/img/intro/slide-2.jpg',
      title: 'slide2_title',
      description: 'slide2_desc',
      imageLoaded: false
    },
    {
      id: 3,
      image: 'assets/img/intro/slide-3.jpg',
      title: 'slide3_title',
      description: 'slide3_desc',
      imageLoaded: false
    }
  ];

  currentIndex = 0;
  private swiperInstance: any;

  constructor(
    private platform: Platform,
    private blocker: BlockerService,
    private cdr: ChangeDetectorRef,
    private router: Router
  ) {}

  ngOnInit(): void {
    // Onboarding redirect is now handled by introGuard on the route
    // (src/app/intro.guard.ts wired in app.routes.ts). No async check
    // here; the guard runs before the route resolves so already-seen
    // users never reach this component.
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  ngAfterViewInit(): void {
    setTimeout(() => {
      if (this.swiperRef?.nativeElement) {
        this.swiperInstance = this.swiperRef.nativeElement.swiper;
      }
    }, 200);
  }

  ngOnDestroy(): void {
    this.blocker.unblock();
  }

  onSlideChange(event: any): void {
    const swiper = event.target?.swiper || this.swiperInstance;
    if (swiper) {
      this.currentIndex = swiper.activeIndex;
      this.triggerHaptic('light');
      this.cdr.markForCheck();
    }
  }

  goToSlide(index: number): void {
    if (this.swiperInstance) {
      this.swiperInstance.slideTo(index);
      this.triggerHaptic('light');
    }
  }

  onNextClick(): void {

    this.triggerHaptic('medium');

    if (this.currentIndex === this.slides.length - 1) {
      this.completeIntro();
    } else {
      this.nextSlide();
    }
  }

  nextSlide(): void {
    if (this.swiperInstance) {
      this.swiperInstance.slideNext();
    } else {
      // Fallback: manually update index
      this.currentIndex++;
      this.cdr.markForCheck();
    }
  }

  async completeIntro(): Promise<void> {
    await Preferences.set({ key: 'intro_seen', value: 'true' });
    await this.router.navigate(['/', 'home']);

  }

  onImageLoad(index: number): void {
    this.slides[index].imageLoaded = true;
    this.cdr.markForCheck();
  }

  async triggerHaptic(style: 'light' | 'medium' | 'heavy' = 'medium'): Promise<void> {
    try {
      if (this.platform.is('capacitor')) {
        const impactStyle = style === 'light' ? ImpactStyle.Light :
          style === 'heavy' ? ImpactStyle.Heavy :
            ImpactStyle.Medium;
        await Haptics.impact({ style: impactStyle });
      }
    } catch (e) {
      // Haptics not available
    }
  }
}
