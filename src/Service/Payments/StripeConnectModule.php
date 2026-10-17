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
        return strtolower((string) env('STRIPE_PAYMENT_FLOW', 'standard')) === 'connect'
            && filter_var(env('STRIPE_CONNECT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }
}
