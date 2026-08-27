import { Directive, Input, TemplateRef, ViewContainerRef, effect, signal } from '@angular/core';
import { PermissionService } from '../../services/permission.service';

/**
 * Structural directive that renders its element only if the current user holds
 * the required permission(s). Reactive, elements appear once permissions load.
 *
 *   <button *axCan="'orders.refund'">Refund</button>
 *   <a *axCan="['payouts.process','payouts.view']; mode 'any'">Payouts</a>
 */
@Directive({ selector: '[axCan]', standalone: true })
export class AxCanDirective {
  private readonly required = signal<string | string[]>('');
  private readonly mode = signal<'any' | 'all'>('any');
  private hasView = false;

  @Input() set axCan(permission: string | string[]) {
    this.required.set(permission);
  }
  @Input() set axCanMode(mode: 'any' | 'all') {
    this.mode.set(mode);
  }

  constructor(
    private tpl: TemplateRef<unknown>,
    private vcr: ViewContainerRef,
    private perms: PermissionService,
  ) {
    effect(() => {
      const allowed = this.evaluate(this.required(), this.mode());
      if (allowed && !this.hasView) {
        this.vcr.createEmbeddedView(this.tpl);
        this.hasView = true;
      } else if (!allowed && this.hasView) {
        this.vcr.clear();
        this.hasView = false;
      }
    });
  }

  private evaluate(req: string | string[], mode: 'any' | 'all'): boolean {
    if (req === '' || (Array.isArray(req) && req.length === 0)) {
      return true;
    }
    if (Array.isArray(req)) {
      return mode === 'all' ? this.perms.canAll(req) : this.perms.canAny(req);
    }
    return this.perms.can(req);
  }
}
