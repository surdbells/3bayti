import { Component, Input, Output, EventEmitter, OnChanges, SimpleChanges } from '@angular/core';
import { IonicModule } from '@ionic/angular';


type SizesObject = Record<string, boolean>;

@Component({
  selector: 'app-size-chips',
  standalone: true,
  imports: [IonicModule],
  templateUrl: './size-chips.component.html',
  styleUrls: ['./size-chips.component.scss'],
})
export class SizeChipsComponent implements OnChanges {
  /** Accepts object from API: { xl: true, lg: true, m: false } */
  @Input() sizes: SizesObject = {};

  /** Emits the selected size key (e.g. 'xl') or null when none */
  @Output() selected = new EventEmitter<string | null>();

  /** internal list for template */
  sizesList: { key: string; label: string; available: boolean; selected: boolean }[] = [];

  ngOnChanges(changes: SimpleChanges) {
    if (changes['sizes']) this.buildList();
  }

  private buildList() {
    this.sizesList = Object.keys(this.sizes || {})
      .filter((k) => this.sizes[k]) // ✅ only keep sizes that are true
      .map((k) => ({
        key: k,
        label: k.toUpperCase(),
        available: true,
        selected: false,
      }));
  }

  /** Select a size — ensures only one selected at a time. Clicking an already-selected size deselects it. */
  selectSize(item: { key: string; available: boolean; selected: boolean }) {
    if (!item.available) return;

    // toggle: if clicking already selected -> deselect
    if (item.selected) {
      this.sizesList.forEach((s) => (s.selected = false));
      this.selected.emit(null);
      return;
    }

    // set selected exactly to this item
    this.sizesList.forEach((s) => (s.selected = s.key === item.key));
    this.selected.emit(item.key);
  }

  /** optional helper: query currently selected key */
  getSelectedKey(): string | null {
    const found = this.sizesList.find((s) => s.selected);
    return found ? found.key : null;
  }
}
