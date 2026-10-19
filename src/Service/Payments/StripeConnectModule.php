<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

/**
 * Explicit rollout boundary for the optional Connect payment module.
 *
 * Both switches are deliberately required. Deployments therefore keep the
 * legacy platform Checkout flow until an operator explicitly selects Connect
 * and enables it after the legal and operational go-live checks.
 */
final class StripeConnectModule
{
    public static function enabled(): bool
    {
        // Prefer the process environment array so the value injected by the
        // deployment (and the isolated test process) is authoritative even
        // after Dotenv has initialized its immutable repository.
        $flow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? env('STRIPE_PAYMENT_FLOW', 'standard');
        $enabled = $_ENV['STRIPE_CONNECT_ENABLED'] ?? env('STRIPE_CONNECT_ENABLED', false);

        return strtolower((string) $flow) === 'connect'
            && filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }
}
