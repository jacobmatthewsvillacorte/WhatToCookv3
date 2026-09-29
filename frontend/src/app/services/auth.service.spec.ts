import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';
import { HouseholdContextService } from './household-context.service';

describe('AuthService', () => {
  let auth: AuthService;
  let http: HttpTestingController;
  const router = { navigateByUrl: jasmine.createSpy('navigateByUrl') };
  const householdContext = { clear: jasmine.createSpy('clear'), refresh: jasmine.createSpy('refresh') };

  beforeEach(() => {
    localStorage.clear();
    router.navigateByUrl.calls.reset();
    householdContext.clear.calls.reset();
    householdContext.refresh.calls.reset();
    householdContext.refresh.and.returnValue(of(undefined));
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
      providers: [
        AuthService,
        { provide: Router, useValue: router },
        { provide: HouseholdContextService, useValue: householdContext },
      ],
    });
    auth = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('clears the local session before the logout request completes', () => {
    localStorage.setItem('whattocook_token', 'token');
    localStorage.setItem('whattocook_user', JSON.stringify({ id: 1, name: 'Test', email: 'test@example.test' }));

    auth.logout();

    expect(auth.isAuthenticated).toBeFalse();
    expect(localStorage.getItem('whattocook_user')).toBeNull();
    expect(householdContext.clear).toHaveBeenCalled();
    expect(router.navigateByUrl).toHaveBeenCalledWith('/auth', { replaceUrl: true });
    const request = http.expectOne(request => request.url.endsWith('/logout'));
    expect(request.request.headers.get('Authorization')).toBe('Bearer token');
    request.flush({ message: 'Logged out successfully!' });
  });

  it('uses the local API base URL for login and registration', () => {
    const response = { user: { id: 1, name: 'Test', email: 'test@example.test' }, token: 'token', message: 'Success' };

    auth.login('test@example.test', 'password').subscribe();
    const loginRequest = http.expectOne(`${environment.apiBaseUrl}/login`);
    expect(loginRequest.request.method).toBe('POST');
    expect(loginRequest.request.body).toEqual({ email: 'test@example.test', password: 'password' });
    loginRequest.flush(response);

    auth.register('Test', 'test@example.test', 'password', 'password').subscribe();
    const registerRequest = http.expectOne(`${environment.apiBaseUrl}/register`);
    expect(registerRequest.request.method).toBe('POST');
    expect(registerRequest.request.body).toEqual({
      name: 'Test', email: 'test@example.test', password: 'password', password_confirmation: 'password',
    });
    registerRequest.flush(response);
  });

  it('explains unreachable and validation failures', () => {
    expect(auth.getAuthErrorMessage({ status: 0 })).toContain('Cannot reach the local backend');
    expect(auth.getAuthErrorMessage({ status: 422, error: { errors: { email: ['Invalid credentials.'] } } })).toBe('Invalid credentials.');
  });
});
