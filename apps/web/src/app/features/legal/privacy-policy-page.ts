import {
  Component,
  ChangeDetectionStrategy,
  inject,
  OnInit,
} from '@angular/core';
import { RouterLink } from '@angular/router';

import { SeoService } from '../../core/seo/seo.service';

/**
 * /privacy — the public Privacy Policy (Google Play compliance).
 *
 * A static, PUBLIC prose page (no guard). The route title is localized
 * via I18nTitleStrategy (routeTitles.privacy); the long legal BODY is
 * intentionally kept INLINE in English (not moved into i18n) so the
 * canonical wording lives in one place and reviews cleanly.
 *
 * Indexable — the SeoService call leaves robots at the default
 * 'index,follow' so the policy is discoverable by crawlers (a Play/App
 * Store review requirement).
 *
 * Tailored to 3bayti — a UAE modest-fashion e-commerce MARKETPLACE
 * (multiple independent vendors fulfil orders; Noon processes payments).
 */
@Component({
  selector: 'app-privacy-policy',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],
  template: `
    <main class="legal-doc" data-testid="privacy-policy-page">
      <article class="legal-doc__container">
        <header class="legal-doc__header">
          <h1 class="legal-doc__title">Privacy Policy</h1>
          <p class="legal-doc__updated">Last updated: 18 June 2026</p>
        </header>

        <section class="legal-doc__section">
          <h2>1. Introduction</h2>
          <p>
            3bayti (“3bayti”, “we”, “us”, or “our”) operates an online
            marketplace for modest fashion, based in the United Arab
            Emirates, where independent vendors offer their products to
            shoppers through our website (3bayti.ae) and mobile app
            (together, the “Service”). This Privacy Policy explains what
            personal information we collect when you use the Service, how
            we use and share it, and the choices and rights you have.
          </p>
          <p>
            By creating an account or using the Service, you acknowledge
            that you have read and understood this Privacy Policy. If you
            do not agree with it, please do not use the Service.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>2. Information we collect</h2>
          <p>We collect the following categories of information:</p>
          <ul>
            <li>
              <strong>Account information.</strong> Your name, email
              address and phone number, and either a password or a
              Google or Apple sign-in identity you use to access your
              account.
            </li>
            <li>
              <strong>Delivery and billing addresses.</strong> The
              addresses you save or enter to receive and pay for orders.
            </li>
            <li>
              <strong>Orders and payments.</strong> The products you
              order and your order history. Payments are processed by our
              payment provider, Noon; we do <strong>not</strong> store
              your full card numbers.
            </li>
            <li>
              <strong>Shopping activity.</strong> Your cart, wishlist,
              and any saved measurements and sizes.
            </li>
            <li>
              <strong>Gift cards.</strong> When you buy a gift card, the
              recipient’s name and contact details you provide so we can
              deliver it.
            </li>
            <li>
              <strong>Product reviews.</strong> The ratings and reviews
              you submit for products and stores.
            </li>
            <li>
              <strong>Push tokens.</strong> Device push notification
              tokens, so we can send you order and account notifications
              on your device.
            </li>
            <li>
              <strong>Device and usage data.</strong> Device information,
              IP address, and usage and analytics data generated as you
              interact with the Service.
            </li>
          </ul>
        </section>

        <section class="legal-doc__section">
          <h2>3. How we use your information</h2>
          <p>We use your information to:</p>
          <ul>
            <li>operate and maintain the marketplace;</li>
            <li>process, fulfil and deliver your orders;</li>
            <li>process payments through our payment provider;</li>
            <li>
              manage your account and send you notifications, including
              push notifications about your orders and account;
            </li>
            <li>provide customer support;</li>
            <li>detect, prevent and address fraud and abuse;</li>
            <li>comply with our legal and regulatory obligations; and</li>
            <li>measure, maintain and improve the Service.</li>
          </ul>
        </section>

        <section class="legal-doc__section">
          <h2>4. How we share your information</h2>
          <p>
            We share your information only as needed to run the
            marketplace. We do <strong>not</strong> sell your personal
            data. We share it with:
          </p>
          <ul>
            <li>
              <strong>Vendors.</strong> The independent vendors whose
              products you order, so they can fulfil and deliver your
              orders.
            </li>
            <li>
              <strong>Payment processor.</strong> Noon, to process your
              payments securely.
            </li>
            <li>
              <strong>Delivery and logistics partners,</strong> to
              deliver your orders.
            </li>
            <li>
              <strong>Email and SMS/OTP providers,</strong> to send
              transactional messages and one-time verification codes.
            </li>
            <li>
              <strong>Firebase</strong> (a Google service), for Google
              and Apple sign-in and for push notifications.
            </li>
            <li>
              <strong>Error-monitoring, hosting and CDN providers,</strong>
              who help us run the Service reliably and diagnose problems.
            </li>
          </ul>
          <p>
            We may also disclose information where required by law, or to
            protect the rights, safety and property of 3bayti, our users,
            or others.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>5. Data retention</h2>
          <p>
            We keep your personal information for as long as your account
            is active. When you delete your account, it is deactivated and
            soft-deleted, your profile is removed from active use, and all
            of your sessions are revoked. We retain order and transaction
            records after deletion to meet our legal, tax and accounting
            obligations for the applicable retention period, after which
            they are deleted or anonymised.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>6. Your rights and choices</h2>
          <p>Subject to applicable law, you may:</p>
          <ul>
            <li>access and review the personal information we hold about you;</li>
            <li>correct information that is inaccurate or incomplete;</li>
            <li>
              delete your account and data — see our
              <a routerLink="/delete-account">account deletion page</a>;
              and
            </li>
            <li>opt out of marketing communications at any time.</li>
          </ul>
        </section>

        <section class="legal-doc__section">
          <h2>7. Security</h2>
          <p>
            We protect your information with appropriate technical and
            organisational measures. Data is encrypted in transit using
            HTTPS, account passwords are stored only as salted hashes
            (never in plain text), and access to personal data is
            restricted through access controls. No method of transmission
            or storage is completely secure, but we work to protect your
            information and continually improve our safeguards.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>8. Children</h2>
          <p>
            The Service is not directed to anyone under the age of 18, and
            we do not knowingly collect personal information from children.
            If you believe a child has provided us with personal
            information, please contact us and we will take steps to delete
            it.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>9. International processing</h2>
          <p>
            We operate from the United Arab Emirates. Some of our service
            providers may process your information on servers located
            outside the UAE. Where information is transferred
            internationally, we take steps to ensure it remains protected
            in line with this Privacy Policy.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>10. Cookies and similar technologies</h2>
          <p>
            We use cookies and similar technologies for two purposes:
            authentication and session management (to keep you signed in
            and secure), and analytics (to understand how the Service is
            used so we can improve it). You can control cookies through
            your browser settings, though disabling them may affect how
            the Service works.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>11. Changes to this policy</h2>
          <p>
            We may update this Privacy Policy from time to time. When we
            do, we will post the updated version here with a new effective
            date. Your continued use of the Service after an update means
            you accept the revised policy.
          </p>
        </section>

        <section class="legal-doc__section">
          <h2>12. Contact us</h2>
          <p>
            If you have any questions about this Privacy Policy or how we
            handle your information, contact us at
            <a href="mailto:info@3bayti.com">info&#64;3bayti.com</a>.
          </p>
        </section>
      </article>
    </main>
  `,
  styleUrl: './privacy-policy-page.scss',
})
export class PrivacyPolicyPageComponent implements OnInit {
  private readonly seo = inject(SeoService);

  ngOnInit(): void {
    this.seo.set({
      title: 'Privacy Policy',
      description:
        'How 3bayti collects, uses, shares and protects your personal ' +
        'information when you shop the modest-fashion marketplace on our ' +
        'website and app. Includes your data rights and how to delete your ' +
        'account.',
      /* Legal page must be indexable for app-store / Play review — leave
         robots at the SeoService default ('index,follow'). */
    });
  }
}
