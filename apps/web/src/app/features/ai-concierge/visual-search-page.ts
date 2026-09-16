import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

import { ProductCardComponent } from '../catalog/product-card';
import { ConciergeService, type ConciergeCard } from './concierge.service';

/**
 * "Search by photo": upload or snap a photo and Ain finds visually similar real,
 * in-stock 3bayti pieces (POST /ai/visual-search). The image is resized
 * client-side before upload and never stored. When visual search isn't enabled
 * yet the endpoint returns nothing and the empty state explains it.
 */
@Component({
  selector: 'app-visual-search',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, ProductCardComponent],
  templateUrl: './visual-search-page.html',
  styleUrl: './visual-search-page.scss',
})
export class VisualSearchPageComponent implements OnInit {
  private readonly concierge = inject(ConciergeService);

  readonly preview = signal<string | null>(null);
  readonly cards = signal<ConciergeCard[]>([]);
  readonly description = signal<string | null>(null);
  readonly loading = signal(false);
  readonly hasSearched = signal(false);
  readonly error = signal(false);

  private interactionId: number | null = null;

  readonly isEmpty = computed(() => this.hasSearched() && !this.loading() && this.cards().length === 0);

  ngOnInit(): void {
    this.concierge.recordEvent('ai_opened', { feature: 'visual_search' });
  }

  async onFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
      return;
    }
    await this.searchFrom(file);
    // Allow re-selecting the same file.
    input.value = '';
  }

  async onDrop(event: DragEvent): Promise<void> {
    event.preventDefault();
    const file = event.dataTransfer?.files?.[0];
    if (file && file.type.startsWith('image/')) {
      await this.searchFrom(file);
    }
  }

  onDragOver(event: DragEvent): void {
    event.preventDefault();
  }

  private async searchFrom(file: File): Promise<void> {
    this.loading.set(true);
    this.hasSearched.set(true);
    this.error.set(false);
    this.cards.set([]);
    this.description.set(null);

    let dataUrl: string;
    try {
      dataUrl = await this.toResizedDataUrl(file);
    } catch {
      this.loading.set(false);
      this.error.set(true);
      return;
    }
    this.preview.set(dataUrl);

    try {
      const res = await this.concierge.visualSearch(dataUrl);
      this.interactionId = res.interactionId;
      this.cards.set(res.cards);
      this.description.set(res.description);
    } catch {
      this.error.set(true);
      this.cards.set([]);
    } finally {
      this.loading.set(false);
    }
  }

  onCardClick(card: ConciergeCard): void {
    this.concierge.recordEvent('ai_visual_search_product_clicked', {
      ...(this.interactionId ? { interaction_id: this.interactionId } : {}),
      product_id: card.id,
    });
  }

  clear(): void {
    this.preview.set(null);
    this.cards.set([]);
    this.description.set(null);
    this.hasSearched.set(false);
    this.error.set(false);
  }

  /** Downscale to <= 1024px and re-encode as JPEG to keep the upload small. */
  private async toResizedDataUrl(file: File, max = 1024): Promise<string> {
    try {
      const bitmap = await createImageBitmap(file);
      const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
      const w = Math.max(1, Math.round(bitmap.width * scale));
      const h = Math.max(1, Math.round(bitmap.height * scale));
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      if (!ctx) {
        throw new Error('no 2d context');
      }
      ctx.drawImage(bitmap, 0, 0, w, h);
      bitmap.close();
      return canvas.toDataURL('image/jpeg', 0.85);
    } catch {
      // Fallback: send the original bytes as a data URL.
      return await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(new Error('read failed'));
        reader.readAsDataURL(file);
      });
    }
  }
}
