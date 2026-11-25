<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

/** Interprets older Stripe Transfer facts without rewriting the ledger. */
final class FinancialMovementKind
{
    public const LEGACY_TRANSFER_EVENTS = ['stripe.transfer.created', 'stripe.transfer.reversal.created'];

    public static function effectiveType(object $movement): string
    {
        if ($movement->type === 'payout'
            && $movement->provider === 'stripe'
            && in_array($movement->source_event_type, self::LEGACY_TRANSFER_EVENTS, true)) {
            return 'transfer';
        }

        return (string) $movement->type;
    }

    public static function effectiveStatus(object $movement, string $status): string
    {
        return $movement->type === 'payout' && self::effectiveType($movement) === 'transfer' && $status === 'paid'
            ? 'available' : $status;
    }
}
