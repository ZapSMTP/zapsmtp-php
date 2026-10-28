<?php

declare(strict_types=1);

namespace ZapSMTP;

/**
 * A webhook request's body: {id, type, created_at, data}.
 *
 * The event types are constants of this class; data's fields depend on the
 * type (see the docs' Webhook events page) and are read with array access:
 * $event->data['email_id'] or $event['data']['email_id'].
 */
final class WebhookEvent extends ApiObject
{
    public const EMAIL_SENT = 'email.sent';
    public const EMAIL_DELIVERED = 'email.delivered';
    public const EMAIL_DELIVERY_DELAYED = 'email.delivery_delayed';
    public const EMAIL_BOUNCED = 'email.bounced';
    public const EMAIL_COMPLAINED = 'email.complained';
    public const EMAIL_FAILED = 'email.failed';
    public const EMAIL_OPENED = 'email.opened';
    public const EMAIL_CLICKED = 'email.clicked';
    public const EMAIL_REPLIED = 'email.replied';
    public const EMAIL_SUPPRESSED = 'email.suppressed';
    public const EMAIL_RECEIVED = 'email.received';
    public const THREAD_CREATED = 'thread.created';
    public const THREAD_REPLIED = 'thread.replied';

    protected const FIELDS = [
        'id' => 'string',
        'type' => 'string',
        'created_at' => 'string',
        'data' => ['map', 'mixed'],
    ];

    /** evt_...; absent on email.received, thread.created and thread.replied (see the Zap-Event-Id header). */
    public ?string $id = null;

    /** The event type, e.g. email.delivered. */
    public ?string $type = null;

    /** RFC 3339 time of the event. */
    public ?string $created_at = null;

    /** @var array<string, mixed>|null */
    public ?array $data = null;
}
