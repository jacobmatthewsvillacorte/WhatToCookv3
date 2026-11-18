import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable, of } from 'rxjs';
import { finalize, map, shareReplay } from 'rxjs/operators';
import { ApiService, Family } from './api.service';

export interface HouseholdContextState {
  userId: number | null;
  families: Family[];
  activeFamily: Family | null;
}

/**
 * Keeps one consistent household selection across the app.
 *
 * A selection belongs to the signed-in account, not to the browser as a
 * whole. That prevents one household member's selection from affecting the
 * next account that signs in on the same device.
 */
@Injectable({ providedIn: 'root' })
export class HouseholdContextService {
  private refreshVersion = 0;
  private inFlightRefresh?: {
    userId: number;
    observable: Observable<HouseholdContextState>;
  };
  private readonly stateSubject = new BehaviorSubject<HouseholdContextState>({
    userId: null,
    families: [],
    activeFamily: null,
  });

  readonly state$ = this.stateSubject.asObservable();

  constructor(private api: ApiService) {}

  get snapshot(): HouseholdContextState {
    return this.stateSubject.value;
  }

  /** Removes all account-specific context, such as when signing out. */
  clear(): void {
    this.refreshVersion++;
    this.inFlightRefresh = undefined;
    this.publishState(null, [], null);
  }

  /** Loads accepted households and resolves the user's chosen plan context. */
  refresh(userId: number | undefined): Observable<HouseholdContextState> {
    if (!userId || !this.api.hasToken) {
      this.clear();
      return of(this.snapshot);
    }

    if (this.inFlightRefresh?.userId === userId) {
      return this.inFlightRefresh.observable;
    }

    const version = ++this.refreshVersion;
    let observable: Observable<HouseholdContextState>;
    observable = this.api.families().pipe(
      map(families => {
        if (version !== this.refreshVersion) {
          return this.snapshot;
        }
        const savedContext = this.readSavedContext(userId);
        const activeFamily = savedContext === 'personal'
          ? null
          : families.find(family => family.id === savedContext) || null;
        return this.publishState(userId, families, activeFamily);
      }),
      finalize(() => {
        if (this.inFlightRefresh?.observable === observable) {
          this.inFlightRefresh = undefined;
        }
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    this.inFlightRefresh = { userId, observable };
    return observable;
  }

  /** Selects a household already returned for the current user. */
  select(userId: number | undefined, family: Family | null): void {
    if (!userId) {
      this.publishState(null, [], null);
      return;
    }

    const currentFamilies = this.stateSubject.value.userId === userId
      ? this.stateSubject.value.families
      : [];
    const families = family
      ? [...currentFamilies.filter(existing => existing.id !== family.id), family]
      : currentFamilies;
    this.publishState(userId, families, family);
  }

  private publishState(userId: number | null, families: Family[], activeFamily: Family | null): HouseholdContextState {
    if (userId) {
      const key = this.storageKey(userId);
      localStorage.setItem(key, activeFamily ? String(activeFamily.id) : 'personal');
    }

    const state = { userId, families, activeFamily };
    this.stateSubject.next(state);
    return state;
  }

  private readSavedContext(userId: number): number | 'personal' {
    const saved = localStorage.getItem(this.storageKey(userId));
    if (saved === 'personal') return 'personal';
    const savedId = Number(saved);
    if (savedId) return savedId;

    // Migrate the selection used by earlier versions, if it belongs to one of
    // this account's accepted households. The family list validates it later.
    const legacyId = Number(localStorage.getItem('whattocook_active_family'));
    return legacyId || 'personal';
  }

  private storageKey(userId: number): string {
    return `whattocook_active_family_${userId}`;
  }
}
