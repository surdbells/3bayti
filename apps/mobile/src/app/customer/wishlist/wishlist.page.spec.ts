import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { WishlistPage } from './wishlist.page';
import { WishlistService, WishlistLabel, WishlistPage as WLPage } from '../../core/services/wishlist.service';

class WishlistServiceStub {
  labels: WishlistLabel[] = [];
  productsResult: WLPage = { products: [], total: 0, hasMore: false };
  createResult: WishlistLabel | null = { id: 99, name: 'New', count: 0, created_at: 'x' };
  removeOk = true;
  lastListLabelArg: any = 'UNSET';
  async listLabels() { return this.labels; }
  async listProducts(_token: string, opts: any) { this.lastListLabelArg = opts.labelId; return this.productsResult; }
  async createLabel() { return this.createResult; }
  async remove() { return this.removeOk; }
  async add() { return true; }
  async move() { return true; }
  async renameLabel() { return true; }
  async deleteLabel() { return true; }
}

function setup(): { component: WishlistPage; svc: WishlistServiceStub } {
  const svc = new WishlistServiceStub();
  TestBed.configureTestingModule({
    imports: [WishlistPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: WishlistService, useValue: svc },
    ],
  });
  const fixture = TestBed.createComponent(WishlistPage);
  const component = fixture.componentInstance;
  component.single_user.token = 'tok';
  return { component, svc };
}

describe('WishlistPage (Z.3-Mobile)', () => {
  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  it('loads labels into categories', async () => {
    const { component, svc } = setup();
    svc.labels = [
      { id: 1, name: 'Eid', count: 3, created_at: 'x' },
      { id: 2, name: 'Work', count: 0, created_at: 'x' },
    ];
    await component.loadLabels();
    expect(component.categories.length).toBe(2);
    expect(component.categories[0].name).toBe('Eid');
    expect(component.categories[0].count).toBe(3);
  });

  it('selectLabel(0) loads the All-saved view with no label filter', async () => {
    const { component, svc } = setup();
    svc.productsResult = {
      products: [{ id: 10, name: 'P', primary_image: { url: 'u' } } as any],
      total: 1, hasMore: false,
    };
    await component.selectLabel(0, 'All saved');
    expect(svc.lastListLabelArg).toBeUndefined(); // 0 → no filter
    expect(component.selected_label_id).toBe(0);
    expect(component.wishlists.length).toBe(1);
    expect(component.wishlists[0].product).toBe(10);
    expect(component.wishlists[0].image).toBe('u');
    expect(component.ui_controls.is_empty).toBeFalse();
  });

  it('selectLabel(id) filters by that label', async () => {
    const { component, svc } = setup();
    await component.selectLabel(7, 'Eid');
    expect(svc.lastListLabelArg).toBe(7);
  });

  it('marks empty when a label has no products', async () => {
    const { component, svc } = setup();
    svc.productsResult = { products: [], total: 0, hasMore: false };
    await component.selectLabel(7, 'Eid');
    expect(component.ui_controls.is_empty).toBeTrue();
  });

  it('add_wishlist_label requires a name', async () => {
    const { component } = setup();
    component.add_label_name = '   ';
    await component.add_wishlist_label();
    expect(component.ui_controls.is_creating).toBeFalse();
  });

  it('add_wishlist_label creates a label and refreshes', async () => {
    const { component, svc } = setup();
    svc.labels = [{ id: 99, name: 'New', count: 0, created_at: 'x' }];
    component.add_label_name = 'New';
    await component.add_wishlist_label();
    expect(component.add_label_name).toBe('');
    expect(component.isAddLabelOpen).toBeFalse();
    expect(component.categories.length).toBe(1);
  });

  it('remove_item removes the product from the current grid', async () => {
    const { component } = setup();
    component.wishlists = [
      { id: 1, product: 10, name: 'A', image: '' },
      { id: 2, product: 11, name: 'B', image: '' },
    ] as any;
    await component.remove_item(10);
    expect(component.wishlists.length).toBe(1);
    expect(component.wishlists[0].product).toBe(11);
  });
});
