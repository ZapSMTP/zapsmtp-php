<?php

declare(strict_types=1);

namespace ZapSMTP\Exception;

/** A webhook request's signature is missing, malformed, too old or wrong. */
class WebhookVerificationException extends ZapSMTPException
{
}
