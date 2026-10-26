<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use Illuminate\Database\Capsule\Manager;
use PS\Webservice\Domain\Object\OrderSession;

/**
 * Resolves the one Stripe Customer that belongs to a PrestaShop checkout
 * subject.  The database row is reserved before the API call, so concurrent
 * checkout retries serialize on its unique customer key.  The Stripe
 * idempotency key additionally makes recovery from an interrupted request
 * safe at the provider boundary.
 */
final class StripeCheckoutCustomerService
{
    private const TABLE = 'stripe_checkout_customers';

    public function __construct(private readonly Manager $db)
    {
    }

    public function getOrCreate(OrderSession $session, ?\Stripe\StripeClient $stripeClient = null): string
    {
        [$customerKey, $metadata] = $this->customerIdentity($session);

        return $this->db->getConnection()->transaction(function () use ($session, $stripeClient, $customerKey, $metadata): string {
            $now = gmdate('Y-m-d H:i:s');
            $reserved = $this->db->table(self::TABLE)->insertOrIgnore([
                'customer_key' => $customerKey,
                'stripe_customer_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->db->table(self::TABLE)
                ->where('customer_key', $customerKey)
                ->lockForUpdate()
                ->first();
            $existing = $record === null ? null : $this->stripeCustomerId($record->stripe_customer_id ?? null);
            if ($existing !== null) {
                return $existing;
            }

            // A previous worker can have created the Customer and then died
            // before storing its ID. Search by the immutable metadata marker
            // before issuing another create request.
            $stripeCustomerId = $reserved === 1
                ? $this->createStripeCustomer($session, $customerKey, $metadata, $stripeClient)
                : ($this->findExistingStripeCustomer($customerKey, $stripeClient)
                    ?? $this->createStripeCustomer($session, $customerKey, $metadata, $stripeClient));

            $this->db->table(self::TABLE)->where('customer_key', $customerKey)->update([
                'stripe_customer_id' => $stripeCustomerId,
                'updated_at' => $now,
            ]);

            return $stripeCustomerId;
        });
    }

    /** @return array{0:string,1:array<string,string>} */
    private function customerIdentity(OrderSession $session): array
    {
        $metadata = $session->metadata;
        $customerId = $this->positiveInteger($metadata['id_customer'] ?? null);
        if ($customerId !== null) {
            return ['prestashop_customer:' . $customerId, ['prestashop_customer_id' => (string) $customerId]];
        }

        $guestId = $this->positiveInteger($metadata['id_guest'] ?? null);
        if ($guestId !== null) {
            return ['prestashop_guest:' . $guestId, ['prestashop_guest_id' => (string) $guestId]];
        }

        throw new \RuntimeException('A PrestaShop customer or guest ID is required for Stripe Checkout.');
    }

    /** @param array<string,string> $metadata */
    private function createStripeCustomer(OrderSession $session, string $customerKey, array $metadata, ?\Stripe\StripeClient $stripeClient): string
    {
        $customer = $session->getCustomer()->toArray();
        $name = trim((string) ($customer['firstname'] ?? '') . ' ' . (string) ($customer['lastname'] ?? ''));
        $params = [
            'email' => (string) $customer['email'],
            'metadata' => ['prestashop_customer_key' => $customerKey] + $metadata,
        ];
        if ($name !== '') {
            $params['name'] = $name;
        }

        $options = ['idempotency_key' => 'dz_stripe_customer_v1_' . hash('sha256', $customerKey)];
        $stripeCustomer = $stripeClient === null
            ? \Stripe\Customer::create($params, $options)
            : $stripeClient->customers->create($params, $options);

        return $this->requiredStripeCustomerId($stripeCustomer->id ?? null);
    }

    private function findExistingStripeCustomer(string $customerKey, ?\Stripe\StripeClient $stripeClient): ?string
    {
        $query = "metadata['prestashop_customer_key']:'" . $customerKey . "'";
        $result = $stripeClient === null
            ? \Stripe\Customer::search(['query' => $query, 'limit' => 1])
            : $stripeClient->customers->search(['query' => $query, 'limit' => 1]);
        $first = $result->data[0] ?? null;

        return $first === null ? null : $this->stripeCustomerId($first->id ?? null);
    }

    private function requiredStripeCustomerId(mixed $value): string
    {
        return $this->stripeCustomerId($value) ?? throw new \RuntimeException('Stripe did not return a valid Customer ID.');
    }

    private function stripeCustomerId(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^cus_[A-Za-z0-9]+$/D', $value)) {
            return null;
        }

        return $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ((is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
