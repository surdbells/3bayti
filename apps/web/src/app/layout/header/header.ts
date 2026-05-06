import { Component, ChangeDetectionStrategy } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * Site-wide header. Persistent across all pages, sticky to the viewport top.
 * Phase 1: brand mark + minimal nav placeholder. Phase 2 will add the
 * full mega-menu, search bar, and account/cart icons.
 */
@Component({
  selector: 'app-header',
  standalone: true,
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './header.html',
  styleUrl: './header.scss',
})
export class HeaderComponent {}
