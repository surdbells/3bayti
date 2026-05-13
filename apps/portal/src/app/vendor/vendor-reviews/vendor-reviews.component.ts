import { Component, OnInit } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { Reviews } from '../../class/reviews';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
@Component({
  selector: 'app-vendor-reviews',
  imports: [VendorShellComponent, CommonModule, RouterLink],
  standalone: true,
  templateUrl: './vendor-reviews.component.html',
  styleUrl: './vendor-reviews.component.css',
})
export class VendorReviewsComponent implements OnInit {
  reviews?: Reviews[];

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    no_reviews: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  review = {
    token: '',
    id: 0,
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.review.token = this.user_session.token;
    this.review.id = this.user_session.id;
    this.product_review();
  }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  product_review() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.review, GlobalComponent.getProductReviews).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.reviews = response.data ?? [];
          this.ui_controls.no_reviews = this.reviews!.length === 0;
        } else {
          this.ui_controls.no_reviews = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.no_reviews = true;
      },
    });
  }

  /** Render rating as filled/empty star chars for a11y-preserved display. */
  ratingStars(rating: number): { filled: number; empty: number } {
    const r = Math.max(0, Math.min(5, Math.round(rating)));
    return { filled: r, empty: 5 - r };
  }

  range(n: number): number[] {
    return Array.from({ length: n }, (_, i) => i);
  }
}
