<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Notification\EmailTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailTemplate::isMarketing() classification (M3.2.X.11-B).
 *
 * Marketing templates are gated by User::isMarketingEmailsOptedOut()
 * at notification dispatch time. Transactional templates ignore that
 * flag. Misclassifying a transactional template as marketing would
 * cause it to be suppressed for opted-out users — a serious bug,
 * since transactional emails are required for the service to
 * function under PDPL. Misclassifying marketing as transactional
 * would send unwanted emails to opted-out users — a compliance
 * violation. Both directions matter.
 */
#[CoversClass(EmailTemplate::class)]
final class EmailTemplateMarketingFlagTest extends TestCase
{
    #[Test]
    public function cartAbandonedIsMarketing(): void
    {
        self::assertTrue(EmailTemplate::CART_ABANDONED_CUSTOMER->isMarketing());
    }

    #[Test]
    public function allOrderTemplatesAreTransactional(): void
    {
        $orderTemplates = [
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            EmailTemplate::ORDER_PAID_CUSTOMER,
            EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER,
            EmailTemplate::ORDER_SHIPPED_CUSTOMER,
            EmailTemplate::ORDER_DELIVERED_CUSTOMER,
            EmailTemplate::ORDER_CANCELLED_CUSTOMER,
            EmailTemplate::ORDER_REFUNDED_CUSTOMER,
            EmailTemplate::ORDER_PLACED_VENDOR,
            EmailTemplate::ORDER_CANCELLED_VENDOR,
        ];
        foreach ($orderTemplates as $tpl) {
            self::assertFalse(
                $tpl->isMarketing(),
                "Order template {$tpl->value} must be transactional, not marketing",
            );
        }
    }

    #[Test]
    public function allReturnTemplatesAreTransactional(): void
    {
        $returnTemplates = [
            EmailTemplate::RETURN_SUBMITTED_CUSTOMER,
            EmailTemplate::RETURN_APPROVED_CUSTOMER,
            EmailTemplate::RETURN_DENIED_CUSTOMER,
            EmailTemplate::RETURN_PICKED_UP_CUSTOMER,
            EmailTemplate::RETURN_RECEIVED_BY_VENDOR_CUSTOMER,
            EmailTemplate::RETURN_REFUNDED_CUSTOMER,
            EmailTemplate::RETURN_SUBMITTED_VENDOR,
            EmailTemplate::RETURN_SUBMITTED_ADMIN,
        ];
        foreach ($returnTemplates as $tpl) {
            self::assertFalse(
                $tpl->isMarketing(),
                "Return template {$tpl->value} must be transactional",
            );
        }
    }

    #[Test]
    public function adminTemplatesAreTransactional(): void
    {
        self::assertFalse(EmailTemplate::DISPUTE_OPENED_ADMIN->isMarketing());
    }
}
