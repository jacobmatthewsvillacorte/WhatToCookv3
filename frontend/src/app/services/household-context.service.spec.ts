import { Subject } from 'rxjs';

import { ApiService, Family } from './api.service';
import { HouseholdContextService, HouseholdContextState } from './household-context.service';

describe('HouseholdContextService', () => {
  let api: jasmine.SpyObj<ApiService>;
  let service: HouseholdContextService;

  beforeEach(() => {
    api = jasmine.createSpyObj<ApiService>('ApiService', ['families']);
    Object.defineProperty(api, 'hasToken', { get: () => true });
    service = new HouseholdContextService(api);
  });

  it('shares an in-flight family request for the same account', () => {
    const response = new Subject<Family[]>();
    api.families.and.returnValue(response);
    const first = service.refresh(7);
    const second = service.refresh(7);
    const familyList: Family[] = [{ id: 12, name: 'Home', owner_id: 7 }];
    const results: HouseholdContextState[] = [];

    first.subscribe(value => results.push(value));
    second.subscribe(value => results.push(value));
    response.next(familyList);
    response.complete();

    expect(api.families).toHaveBeenCalledTimes(1);
    expect(results.length).toBe(2);
    expect(results[0].families).toEqual(familyList);
    expect(results[1].families).toEqual(familyList);
  });

  it('does not restore household state from a request completed after clear', () => {
    const response = new Subject<Family[]>();
    api.families.and.returnValue(response);

    service.refresh(7).subscribe();
    service.clear();
    response.next([{ id: 12, name: 'Home', owner_id: 7 }]);

    expect(service.snapshot).toEqual({ userId: null, families: [], activeFamily: null });
  });
});
