import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { RouterTestingModule } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular';

import { ExploreContainerComponentModule } from '../explore-container/explore-container.module';

import { PantryPage } from './pantry.page';
import { receiptCandidates } from './pantry.page';
import { UseAmountModalComponent } from './use-amount-modal.component';
import { PantryChangeService } from '../services/pantry-change.service';

describe('PantryPage', () => {
  let component: PantryPage;
  let fixture: ComponentFixture<PantryPage>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [PantryPage],
      imports: [IonicModule.forRoot(), ExploreContainerComponentModule, FormsModule, HttpClientTestingModule, RouterTestingModule]
    }).compileComponents();

    fixture = TestBed.createComponent(PantryPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('requires a final review before adding a complete pantry item', () => {
    component.form.name = 'Eggs';
    component.form.quantity = '12';
    component.form.unit = 'pieces';

    component.requestSavePantryItem();

    expect(component.confirmingPantryAdd).toBeTrue();
  });

  it('filters pantry items by search and category while retaining freshness state', () => {
    const eggs: any = { id: 1, name: 'Eggs', quantity: '1', unit: 'pieces', freshness_status: 'fresh' };
    const rice: any = { id: 2, name: 'Rice', quantity: '2', unit: 'kg', freshness_status: 'fresh' };
    const spinach: any = { id: 3, name: 'Spinach', quantity: '1', unit: 'pack', freshness_status: 'review' };
    component.personalItems = [eggs, rice, spinach];
    component.selectedCategory = 'Protein';

    expect(component.filteredItems(component.personalItems)).toEqual([eggs]);
    expect(component.stockLabel(eggs)).toBe('Low stock');
    expect(component.stockLabel(spinach)).toBe('Expiring soon');

    component.selectedCategory = 'All';
    component.pantrySearch = 'rice';
    expect(component.filteredItems(component.personalItems)).toEqual([rice]);
  });

  it('labels a past expiry as expired and makes it available for disposal', () => {
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    const expiry = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`;
    const item: any = { id: 1, name: 'Milk', freshness_status: 'fresh', expiry_date: expiry };

    expect(component.isExpired(item)).toBeTrue();
    expect(component.stockLabel(item)).toBe('Expired');
  });

  it('offers still fresh only for unexpired active ingredients, including undated stock', () => {
    const today = new Date();
    const date = (offset: number) => { const value = new Date(today); value.setDate(value.getDate() + offset); return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`; };

    expect(component.canMarkStillFresh({ id: 1, name: 'Eggs', freshness_status: 'fresh', freshness_review_date: date(0) })).toBeTrue();
    expect(component.canMarkStillFresh({ id: 7, name: 'Rice', freshness_status: 'fresh' })).toBeFalse();
    expect(component.canMarkStillFresh({ id: 2, name: 'Milk', freshness_status: 'fresh', expiry_date: date(0) })).toBeTrue();
    expect(component.canMarkStillFresh({ id: 3, name: 'Milk', freshness_status: 'fresh', expiry_date: date(1) })).toBeFalse();
    expect(component.canMarkStillFresh({ id: 4, name: 'Milk', freshness_status: 'fresh', expiry_date: date(2) })).toBeFalse();
    expect(component.canMarkStillFresh({ id: 5, name: 'Milk', freshness_status: 'fresh', expiry_date: date(-1) })).toBeFalse();
    expect(component.canMarkStillFresh({ id: 6, name: 'Milk', freshness_status: 'spoiled', expiry_date: date(1) })).toBeFalse();
  });

  it('shows a confirmed purchase in the correct pantry scope immediately', () => {
    const changes = TestBed.inject(PantryChangeService);
    component.personalItems = [{ id: 1, name: 'Rice', family_id: null }];

    changes.publishAddedItems([{ id: 2, name: 'Chicken' }], 7);
    changes.publishAddedItems([{ id: 3, name: 'Eggs', family_id: null }], 7);

    expect(component.householdItems).toEqual([jasmine.objectContaining({ id: 2, family_id: 7 })]);
    expect(component.personalItems).toEqual([
      jasmine.objectContaining({ id: 1, name: 'Rice' }),
      jasmine.objectContaining({ id: 3, name: 'Eggs', family_id: null }),
    ]);
  });
});

describe('receiptCandidates', () => {
  it('creates editable candidates and excludes totals', () => {
    expect(receiptCandidates('2 Tuna 120.00\nCoconut Milk 45.00\nTOTAL 285.00')).toEqual([
      jasmine.objectContaining({ name: 'Tuna', quantity: '2' }),
      jasmine.objectContaining({ name: 'Coconut Milk', quantity: '1' }),
    ]);
  });
});

describe('UseAmountModalComponent', () => {
  it('locks the pantry unit and previews the remainder', () => {
    const modal = new UseAmountModalComponent(jasmine.createSpyObj('ModalController', ['dismiss']));
    modal.item = { id: 1, name: 'Rice', quantity_value: 3, unit: 'kg' };
    modal.ionViewWillEnter();
    modal.amount = 1.25;

    expect(modal.available).toBe(3);
    expect(modal.remainder).toBe(1.75);
    expect(modal.item.unit).toBe('kg');
  });
});
