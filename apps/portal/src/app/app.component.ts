import { Component } from '@angular/core';
import {NavigationEnd, Router, RouterOutlet} from '@angular/router';
import {filter} from 'rxjs';
import { ConnectionStatusComponent } from './partials/connection-status/connection-status.component';
import { ImpersonationBannerComponent } from './partials/impersonation-banner/impersonation-banner.component';
declare let gtag: Function;
@Component({
  selector: 'app-root',
  imports: [RouterOutlet, ConnectionStatusComponent, ImpersonationBannerComponent],
  templateUrl: './app.component.html',
  standalone: true,
  styleUrl: './app.component.css'
})
export class AppComponent {
  title = '3bayti.ae';

  constructor(private router: Router) {
    this.router.events.pipe(
      filter(event => event instanceof NavigationEnd)
    ).subscribe((event: NavigationEnd) => {
      gtag('event', 'page_view', {
        page_path: event.urlAfterRedirects
      });
    });
  }
}
