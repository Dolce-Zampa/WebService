<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

use InvalidArgumentException;

/**
 * Guardrail for metadata written to the financial ledger.
 *
 * Ledger metadata is reconciliation context, not a copy of a provider event.
 * Keeping this check at the domain boundary means a future controller, import
 * or queue consumer cannot accidentally persist cardholder data or secrets.
 */
final class FinancialMetadata
{
    private const MAX_ENTRIES = 32;
    private const MAX_KEY_LENGTH = 64;
    private const MAX_VALUE_LENGTH = 255;

    /**
     * Deliberately broad: a false positive is preferable to retaining a
     * payment credential or a complete provider payload in an audit table.
     */
    private const SENSITIVE_KEY = '/(?:card|pan|cvc|cvv|iban|account(?:_|-)?number|routing|secret|api(?:_|-)?key|token|authorization|password|email|phone|address|customer|payment(?:_|-)?method|billing|shipping|payload|raw|receipt|ip(?:_|-)?address)/i';

    /** @param array<string, mixed> $metadata */
    public static function assertSafe(array $metadata): void
    {
        if (count($metadata) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Financial metadata contains too many fields.');
        }

        foreach ($metadata as $key => $value) {
            if (!is_string($key)
                || $key === ''
                || strlen($key) > self::MAX_KEY_LENGTH
                || preg_match('/^[A-Za-z0-9_.:-]+$/', $key) !== 1
                || preg_match(self::SENSITIVE_KEY, $key) === 1
            ) {
                throw new InvalidArgumentException('Financial metadata contains a restricted field.');
            }

            // Arrays and objects are usually a serialized provider payload.
            // The ledger intentionally accepts only small scalar correlation
            // values such as a Stripe session id or a payment status.
            if (!is_null($value) && !is_scalar($value)) {
                throw new InvalidArgumentException('Financial metadata must contain scalar reconciliation values only.');
            }

            if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new InvalidArgumentException('Financial metadata values are too long.');
            }
        }
    }
}
