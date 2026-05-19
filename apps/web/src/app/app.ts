import { Component, ChangeDetectionStrategy } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { HeaderComponent } from './layout/header/header';
import { FooterComponent } from './layout/footer/footer';
import { ToastContainerComponent } from './shared/forms';

/**
 * Application root shell. Renders the persistent header + footer around
 * the active route's content. Single instance, server-rendered on every
 * request, hydrated on the client. The toast container is mounted here
 * (Y.1-D) so any descendant can call ToastService.show() and have it
 * appear globally regardless of route.
 */
@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, HeaderComponent, FooterComponent, ToastContainerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {}
