import { Component } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

@Component({ selector: 'app-auth', templateUrl: './auth.page.html', styleUrls: ['./auth.page.scss'], standalone: false })
export class AuthPage {
  mode: 'login' | 'register' = 'login';
  name = ''; email = ''; password = ''; passwordConfirmation = '';
  submitting = false; error = '';

  constructor(private auth: AuthService, private router: Router) {}
  submit(): void {
    this.error = '';
    if (this.mode === 'register' && this.password !== this.passwordConfirmation) { this.error = 'Your passwords do not match.'; return; }
    this.submitting = true;
    const request = this.mode === 'login'
      ? this.auth.login(this.email, this.password)
      : this.auth.register(this.name, this.email, this.password, this.passwordConfirmation);
    request.subscribe({
      next: () => this.router.navigateByUrl(this.mode === 'register' ? '/onboarding' : '/tabs/dashboard', { replaceUrl: true }),
      error: error => { this.error = this.auth.getAuthErrorMessage(error); this.submitting = false; },
    });
  }
}
