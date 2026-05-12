import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-coupon-widget',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './coupon-widget.component.html',
  styleUrl: './coupon-widget.component.css',
})
export class CouponWidgetComponent implements OnInit {

  session_data: any = '';
  user_session = {
    id: 0, token: '', is_admin: false, is_vendor: false,
  };

  stats = {
    active_coupons: 0,
    total_redemptions: 0,
    total_discount_given: 0,
    total_revenue_with_coupons: 0,
  };

  top_coupons: { code: string; total_uses: number; total_revenue: number }[] = [];
  loaded = false;

  constructor(
    private router: Router,
    private crudService: CrudService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.fetchData();
  }

  fetchData(): void {
    const base = { token: this.user_session.token, id: this.user_session.id };

    // Overview
    this.crudService.post_request({ ...base, action: 'overview' }, GlobalComponent.couponAnalytics).subscribe({
      next: (r: any) => {
        if (r.response_code === 200) {
          this.stats = r.data;
        }
        this.loaded = true;
      },
    });

    // Top 3
    this.crudService.post_request({ ...base, action: 'top_coupons', sort_by: 'uses', limit: 3 }, GlobalComponent.couponAnalytics).subscribe({
      next: (r: any) => {
        if (r.response_code === 200) this.top_coupons = r.data;
      },
    });
  }

  goToCoupons(): void {
    this.router.navigate(['/coupons']);
  }

  goToAnalytics(): void {
    this.router.navigate(['/coupon-analytics']);
  }
}
