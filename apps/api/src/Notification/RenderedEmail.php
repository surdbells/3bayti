<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

/**
 * Result of OrderEmailTemplateRenderer::render, the three strings
 * a MailerInterface::send call needs (subject + text body + html body).
 */
final class RenderedEmail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $textBody,
        public readonly string $htmlBody,
    ) {
    }
}
