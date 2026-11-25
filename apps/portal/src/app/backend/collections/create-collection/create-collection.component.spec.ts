import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CreateCollectionComponent } from './create-collection.component';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';

describe('CreateCollectionComponent', () => {
  let component: CreateCollectionComponent;
  let fixture: ComponentFixture<CreateCollectionComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CreateCollectionComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(CreateCollectionComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});

describe('CreateCollectionComponent (logic)', () => {
  const make = () => {
    const adapter = { post_v3: jasmine.createSpy('post_v3').and.returnValue(of({ data: { id: 12 } })) };
    const component = new CreateCollectionComponent(
      jasmine.createSpyObj<Router>('Router', ['navigate']),
      jasmine.createSpyObj<NavigationHistoryService>('NavigationHistoryService', ['back']),
      adapter as unknown as PortalCrudAdapter,
      jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']),
    );
    return { component, adapter };
  };

  it('creates collections open to vendors by default', () => {
    const { component, adapter } = make();
    component.create.collection = 'Ramadan';
    component.createCollection();
    expect(adapter.post_v3).toHaveBeenCalledWith('POST /admin/collections', { name: 'Ramadan', vendor_contributions_enabled: true });
  });

  it('sends vendor_contributions_enabled: false when the switch is off', () => {
    const { component, adapter } = make();
    component.create.collection = 'Editors picks';
    component.create.vendor_contributions_enabled = false;
    component.createCollection();
    expect(adapter.post_v3).toHaveBeenCalledWith('POST /admin/collections', { name: 'Editors picks', vendor_contributions_enabled: false });
  });
});
