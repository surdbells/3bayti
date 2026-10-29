import { Component, ChangeDetectionStrategy } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { HeaderComponent } from './layout/header/header';
import { FooterComponent } from './layout/footer/footer';
import { CartDrawerComponent } from './layout/cart-drawer/cart-drawer';
import { SessionBannerComponent } from './layout/session-banner/session-banner';
import { ToastContainerComponent } from './shared/forms';

/**
 * Application root shell. Renders the persistent header + footer around
 * the active route's content. Single instance, server-rendered on every
 * request, hydrated on the client.
 *
 * Y.1-D added the toast container so any descendant can call
 * ToastService.show() and have it appear globally.
 *
 * Y.2-A added the cart drawer so any descendant can call
 * CartDrawerService.open() / openWithHighlight() and have it appear.
 *
 * The session banner explains a session that is still unresolved after a
 * transient failure (see SessionBannerComponent).
 */
@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    RouterOutlet,
    HeaderComponent,
    FooterComponent,
    CartDrawerComponent,
    SessionBannerComponent,
    ToastContainerComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {}
