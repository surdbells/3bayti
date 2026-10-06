import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { CategoryPage } from './category.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';

/** Benign adapter: every read succeeds empty, no real network. */
class AdapterStub {
  get_v3() { return of({ response_code: 200, status: 'success', data: [] }); }
}

describe('CategoryPage', () => {
  let component: CategoryPage;
  let fixture: ComponentFixture<CategoryPage>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [CategoryPage],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        { provide: MobileNetworkAdapter, useValue: new AdapterStub() },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { queryParamMap: { get: (k: string) => (k === 'id' ? '1' : 'Abayas') } } },
        },
      ],
    });
    fixture = TestBed.createComponent(CategoryPage);
    component = fixture.componentInstance;
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
