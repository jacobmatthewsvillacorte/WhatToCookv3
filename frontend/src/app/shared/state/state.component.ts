import { CommonModule } from '@angular/common';
import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { IonicModule } from '@ionic/angular';

/**
 * The one loading / error / empty block used by every page.
 *
 * It renders the same markup as the global `.wtc-state` styles in global.scss,
 * so a page replaces about five lines of template with one tag:
 *
 *   <app-state *ngIf="loading" kind="loading" heading="Loading your pantry"></app-state>
 *   <app-state *ngIf="error" kind="error" heading="Pantry is unavailable"
 *              [message]="error" actionLabel="Try again" (action)="load()"></app-state>
 *
 * Project extra buttons (for example a "Browse recipes" link) as content.
 * See docs/21-ui-design-system.md.
 */
@Component({
  selector: 'app-state',
  standalone: true,
  imports: [CommonModule, IonicModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section
      class="wtc-state"
      [class.wtc-state--loading]="kind === 'loading'"
      [class.wtc-state--error]="kind === 'error'"
      [class.wtc-state--empty]="kind === 'empty'"
      [class.wtc-state--compact]="compact"
      [attr.role]="kind === 'error' ? 'alert' : 'status'"
      [attr.aria-live]="kind === 'loading' ? 'polite' : null"
    >
      <ion-spinner *ngIf="kind === 'loading'; else icon" [attr.aria-label]="spinnerLabel || heading"></ion-spinner>
      <ng-template #icon>
        <ion-icon *ngIf="iconName || kind === 'error'" class="wtc-state__icon" [name]="iconName || 'alert-circle-outline'" aria-hidden="true"></ion-icon>
      </ng-template>
      <h2>{{ heading }}</h2>
      <p *ngIf="message">{{ message }}</p>
      <ion-button *ngIf="actionLabel" fill="outline" (click)="action.emit()">{{ actionLabel }}</ion-button>
      <ng-content></ng-content>
    </section>
  `,
})
export class StateComponent {
  @Input() kind: 'loading' | 'error' | 'empty' = 'empty';
  @Input() heading = '';
  @Input() message?: string | null;
  /** Ionicon name; defaults to an alert icon for errors and none otherwise. */
  @Input() iconName?: string;
  @Input() spinnerLabel?: string;
  @Input() actionLabel?: string;
  @Input() compact = false;
  @Output() action = new EventEmitter<void>();
}
