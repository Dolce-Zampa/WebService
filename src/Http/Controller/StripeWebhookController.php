<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PS\Order;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StripeWebhookController extends OrderController
{
    private Order $orderService;

    public function __construct(Order $orderService)
    {
        $this->orderService = $orderService;
    }

    public function handleWebhook(Request $request, Response $response, array $argv): Response
    {
        $payload = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('Stripe-Signature');
        $endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;

        if (empty($endpointSecret)) {
            Log::error('Stripe webhook: STRIPE_WEBHOOK_SECRET is not configured');
            return response(['error' => 'Webhook secret not configured'], 500);
        }

        try {
            $event = $this->constructStripeEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook: invalid payload received');
            return response(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook: invalid signature');
            return response(['error' => 'Invalid signature'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            try {
                $this->handleCheckoutSessionCompleted($event->data->object);
            } catch (\Exception $e) {
                Log::error('Stripe webhook: failed to process checkout.session.completed: ' . $e->getMessage());
                return response(['error' => 'Failed to process event'], 500);
            }
        }

        // if session expired or payment failed, we can handle other event types here (e.g. "checkout.session.expired", "payment_intent.payment_failed") to update the order status in PrestaShop accordingly.
        if ($event->type === 'checkout.session.expired' || $event->type === 'payment_intent.payment_failed') {
            Log::info('Stripe webhook: checkout session expired or failed for session ' . $event->data->object->id);
        }

        return response(['received' => true], 200);
    }

    /**
     * Constructs and verifies a Stripe event from the raw request payload and signature.
     * Extracted to allow overriding in tests.
     *
     * @throws \UnexpectedValueException if the payload is invalid
     * @throws \Stripe\Exception\SignatureVerificationException if the signature is invalid
     */
    protected function constructStripeEvent(string $payload, string $sigHeader, string $secret): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
    }

    /**
     * Processes a Stripe checkout.session.completed event and confirms the corresponding order.
     */
    public function handleCheckoutSessionCompleted(\Stripe\StripeObject $session): void
    {
        $metadata = $session->metadata;
        $cartId = isset($metadata->cart_id) ? (int) $metadata->cart_id : 0;
        $customerId = (int) isset($metadata->id_customer) ? (int) $metadata->id_customer : null;
        $guestId = (int) isset($metadata->id_guest) ? (int) $metadata->id_guest : null;
        $carrierId = isset($metadata->id_carrier) ? (int) $metadata->id_carrier : throw new \RuntimeException('Missing id_carrier in Stripe session metadata for cart ' . $cartId);
        $couponCode = isset($metadata->coupon_code) ? (string) $metadata->coupon_code : null;
        $customerDetails = json_decode($metadata->customer);

        if ($cartId <= 0) {
            Log::warning('Stripe webhook: missing or invalid cart_id in metadata for session ' . $session->id);
            return;
        }

        // Convert from Stripe's smallest currency unit to the major unit.
        // This implementation supports only two-decimal currencies (e.g. EUR).
        // Zero-decimal currencies (e.g. JPY) must not be divided; validate the currency first.
        $currency = strtolower($session->currency ?? '');
        if ($currency !== 'eur') {
            Log::error('Stripe webhook: unsupported currency "' . $currency . '" for session ' . $session->id);
            throw new \RuntimeException('Unsupported currency "' . $currency . '" in Stripe session ' . $session->id);
        }

        $amountPaid = ($session->amount_total ?? 0) / 100;
        if ($amountPaid <= 0) {
            Log::error('Stripe webhook: missing or zero amount_total for session ' . $session->id);
            throw new \RuntimeException('Invalid amount_total in Stripe session ' . $session->id);
        }

        if ($carrierId === null) {
            Log::error('Stripe webhook: missing id_carrier in metadata for session ' . $session->id . ', cart ' . $cartId);
            throw new \RuntimeException('Missing id_carrier in Stripe session metadata for cart ' . $cartId);
        }

        $email = $customerDetails->email;
        $firstname = $customerDetails->firstname;
        $lastname = $customerDetails->lastname;
        $amountPaid = ($session->amount_total) / 100;
        
        $this->orderService->confirmSessionOrder(
            $cartId,
            $customerId,
            $guestId,
            $carrierId,
            $couponCode,
            $email,
            $firstname,
            $lastname,
            $customerDetails,
            $amountPaid
        );

        Log::info('Stripe webhook: order confirmed for cart ' . $cartId);
    }
}
