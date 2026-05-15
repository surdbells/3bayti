<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

/**
 * Test-only MailerInterface implementation. Collects every send()
 * call into an array so tests can assert "we sent X to Y with
 * template Z".
 *
 * Lives in src/Notification (not tests/) because:
 *   - Mobile + admin integration tests in the same suite both use it
 *   - Bundling it with the implementation makes it discoverable
 *   - It contains no test-framework dependencies; just data collection
 */
final class InMemoryMailer implements MailerInterface
{
    /**
     * @var list<array{
     *   to: string,
     *   subject: string,
     *   text_body: string,
     *   html_body: string,
     *   context: array<string, mixed>
     * }>
     */
    private array $sent = [];

    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void {
        $this->sent[] = [
            'to' => $to,
            'subject' => $subject,
            'text_body' => $textBody,
            'html_body' => $htmlBody,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{
     *   to: string,
     *   subject: string,
     *   text_body: string,
     *   html_body: string,
     *   context: array<string, mixed>
     * }>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }

    /**
     * Helper: how many emails went to a specific recipient.
     */
    public function countFor(string $to): int
    {
        return count(array_filter($this->sent, fn (array $s) => $s['to'] === $to));
    }

    /**
     * Helper: count by template name (read from context['template']).
     */
    public function countByTemplate(string $template): int
    {
        return count(array_filter(
            $this->sent,
            fn (array $s) => ($s['context']['template'] ?? null) === $template,
        ));
    }
}
