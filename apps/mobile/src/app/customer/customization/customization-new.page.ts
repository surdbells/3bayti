import { Component, ChangeDetectionStrategy, ChangeDetectorRef, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCheckbox,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';

/**
 * /customization-new, the "request a customization" form (P5).
 *
 * Reached from the product page with { slug, name } query params. Collects a
 * free-text description + an optional "share my saved measurements" toggle,
 * then POSTs /me/customization-requests. Mirrors the web PDP request form.
 */
@Component({
  selector: 'app-customization-new',
  templateUrl: './customization-new.page.html',
  styleUrls: ['./customization-new.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    FormsModule,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    IonCheckbox,
    TranslatePipe,
    AxIconComponent,
  ],
})
export class CustomizationNewPage implements OnInit {
  productSlug = '';
  productName = '';
  description = '';
  includeMeasurements = true;
  submitting = false;

  private token = '';
  private measurementSnapshot: Record<string, number> | null = null;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private nav: NavController,
    private networkAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef,
  ) {}

  async ngOnInit(): Promise<void> {
    const ret: any = await Preferences.get({ key: 'user' });
    if (!ret.value) {
      this.router.navigate(['/', 'login']);
      return;
    }
    this.token = JSON.parse(ret.value).token ?? '';

    const qp = this.route.snapshot.queryParamMap;
    this.productSlug = qp.get('slug') ?? '';
    this.productName = qp.get('name') ?? '';

    if (!this.productSlug) {
      this.toast.error(this.i18n.t('cust_error_no_product'), { position: 'top-center' });
      this.nav.back();
      return;
    }

    this.loadMeasurements();
  }

  /** Preload the saved default measurements so we can attach a snapshot. */
  private loadMeasurements(): void {
    this.networkAdapter.get_v3('GET /me/measurements', { authToken: this.token }).subscribe({
      next: (res: any) => {
        const m = Array.isArray(res?.data) ? res.data[0] : null;
        if (!m) return;
        const fields = ['bust', 'waist', 'hip', 'shoulder', 'armhole', 'length', 'arm'];
        const snapshot: Record<string, number> = {};
        for (const f of fields) {
          const v = Number(m[f]);
          if (!Number.isNaN(v) && v > 0) snapshot[f] = v;
        }
        this.measurementSnapshot = Object.keys(snapshot).length > 0 ? snapshot : null;
        this.cdr.markForCheck();
      },
      error: () => { /* non-fatal; submit without measurements */ },
    });
  }

  onDescriptionInput(event: Event): void {
    this.description = (event.target as HTMLTextAreaElement).value;
  }

  async submit(): Promise<void> {
    if (this.submitting) return;
    const description = this.description.trim();
    if (description.length < 3) {
      this.toast.error(this.i18n.t('cust_error_description'), { position: 'top-center' });
      return;
    }

    const body: { product_slug: string; description: string; measurement_snapshot?: Record<string, number> } = {
      product_slug: this.productSlug,
      description,
    };
    if (this.includeMeasurements && this.measurementSnapshot !== null) {
      body.measurement_snapshot = this.measurementSnapshot;
    }

    this.submitting = true;
    this.cdr.markForCheck();
    try {
      const res: any = await firstValueFrom(
        this.networkAdapter.post_v3('POST /me/customization-requests', body, { authToken: this.token }),
      );
      if (res?.status === 'error' || !res?.data) {
        this.toast.error(res?.message || this.i18n.t('cust_error_submit'), { position: 'top-center' });
        this.submitting = false;
        this.cdr.markForCheck();
        return;
      }
      this.toast.success(this.i18n.t('cust_sent'), { position: 'top-center' });
      this.router.navigate(['/', 'customization-requests']);
    } catch {
      this.toast.error(this.i18n.t('cust_error_submit'), { position: 'top-center' });
      this.submitting = false;
      this.cdr.markForCheck();
    }
  }

  triggerBack(): void {
    this.nav.back();
  }
}
