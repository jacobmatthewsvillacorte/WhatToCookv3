import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { environment } from '../../environments/environment';
import { Capacitor } from '@capacitor/core';
import { HouseholdContextService } from './household-context.service';

export interface AppUser { id: number; name: string; email: string; }
interface AuthResponse { user: AppUser; token: string; message: string; }

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly tokenKey = 'whattocook_token';
  private readonly userKey = 'whattocook_user';

  constructor(private http: HttpClient, private router: Router, private householdContext: HouseholdContextService) {}
  private get baseUrl(): string {
    return Capacitor.getPlatform() === 'android'
      ? environment.androidApiBaseUrl
      : environment.apiBaseUrl;
  }

  get isAuthenticated(): boolean { return !!localStorage.getItem(this.tokenKey); }
  get token(): string | null { return localStorage.getItem(this.tokenKey); }
  getAuthErrorMessage(error: { status?: number; error?: { message?: string; errors?: Record<string, string[]> } }): string {
    if (error.status === 0) {
      return `Cannot reach the local backend at ${this.baseUrl}. Keep Laravel running and ensure the phone and computer use the same Wi-Fi.`;
    }
    const validationErrors = Object.values(error.error?.errors ?? {}).reduce(
      (messages: string[], fieldMessages) => messages.concat(fieldMessages),
      [],
    );
    return validationErrors[0] || error.error?.message || 'We could not complete the request. Please check your details and try again.';
  }
  get user(): AppUser | null {
    const stored = localStorage.getItem(this.userKey);
    return stored ? JSON.parse(stored) as AppUser : null;
  }

  login(email: string, password: string): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.baseUrl}/login`, { email, password }).pipe(tap(response => this.save(response)));
  }

  register(name: string, email: string, password: string, passwordConfirmation: string): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.baseUrl}/register`, {
      name, email, password, password_confirmation: passwordConfirmation,
    }).pipe(tap(response => this.save(response)));
  }

  logout(): void {
    const token = this.token;
    // Clear local state before waiting for the network so sign-out is immediate
    // and still succeeds from an offline device or an already-expired token.
    this.clearSession();
    if (token) {
      this.http.post(`${this.baseUrl}/logout`, {}, { headers: new HttpHeaders({ Authorization: `Bearer ${token}` }) })
        .subscribe({ error: () => undefined });
    }
  }

  /** Ends a stale session after a protected API request returns 401. */
  handleUnauthorized(): void {
    if (this.isAuthenticated) this.clearSession();
  }

  private clearSession(): void {
    localStorage.removeItem(this.tokenKey);
    localStorage.removeItem(this.userKey);
    localStorage.removeItem('whattocook_active_family');
    this.householdContext.clear();
    this.router.navigateByUrl('/auth', { replaceUrl: true });
  }

  /** Restores the active accepted household when the app starts with a saved session. */
  restoreHouseholdContext(): void {
    if (!this.isAuthenticated || !this.user) return;
    this.householdContext.refresh(this.user.id).subscribe({ error: () => this.householdContext.clear() });
  }

  private save(response: AuthResponse): void {
    const previous = this.user;
    if (previous?.id !== response.user.id) localStorage.removeItem('whattocook_active_family');
    localStorage.setItem(this.tokenKey, response.token);
    localStorage.setItem(this.userKey, JSON.stringify(response.user));
    this.householdContext.refresh(response.user.id).subscribe({ error: () => this.householdContext.clear() });
  }
}
