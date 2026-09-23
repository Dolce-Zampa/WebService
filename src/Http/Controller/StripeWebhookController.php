<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Customer;
use PS\Webservice\Domain\Models\PS\Products\Product;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Service\Promotions\PromotionService;
use PS\Webservice\Traits\Order as OrderTrait;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StripeWebhookController extends OrderController
{
    use UseCache, OrderTrait;
    private Order $orderService;
    private MailjetService $mailjetService;
    protected PaymentGatewayInterface $stripeService;
    private ?PromotionService $promotionService = null;

    private MailerInterface $mailer;

    public function __construct(
        Order $orderService,
        MailjetService $mailjetService,
        PaymentGatewayInterface $stripeService,
        MailerInterface $mailer,
        mixed $legacyDependency = null,
        ?PromotionService $promotionService = null
    )
    {
        $this->orderService = $orderService;
        $this->mailjetService = $mailjetService;
        $this->stripeService = $stripeService;
        $this->mailer = $mailer;
        if ($legacyDependency instanceof PromotionService && $promotionService === null) {
            $this->promotionService = $legacyDependency;
        } else {
            $this->promotionService = $promotionService;
        }
    }
    //https://hkdk.events/q2u3lxvs2zpfu7 
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
                if ($this->promotionService !== null && $this->promotionService->isPromotionCheckoutSession($event->data->object)) {
                    $this->promotionService->activatePromotionFromStripeSession($event->data->object);
                    return response(['received' => true], 200);
                }
                $this->handleCheckoutSessionCompleted($event->data->object);
            } catch (\Exception $e) {
                Log::critical('Stripe webhook: failed to process checkout.session.completed: ' . $e->getMessage());
                return response(['error' => 'Failed to process event'], 500);
            }
        }

        if( $event->type === 'checkout.session.expired') {
            try {
                $this->handleCheckoutSessionExpired($event->data->object);
            } catch (\Exception $e) {
                Log::critical('Stripe webhook: failed to process checkout.session.expired: ' . $e->getMessage());
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
        $carrierId = isset($metadata->id_carrier) ? (int) $metadata->id_carrier : 14; //FIXME: default carrier id should be configurable, not hardcoded
        $couponCode = isset($metadata->coupon_code) ? (string) $metadata->coupon_code : null;
        $customerEmail = isset($metadata->customer_email) ? (string) $metadata->customer_email : throw new \InvalidArgumentException('customer email is required in Stripe session metadata');
        $customerDetails = Customer::where('email', $customerEmail)->firstOrFail();

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

        try {
            $contactId = $this->mailjetService->createNewContact($email, $firstname, $lastname);
            $this->mailjetService->setContactListSubscription($contactId, env('MAILJET_CLIENTI_LIST_ID', 10663907));
            $this->mailjetService->setContactListSubscription($contactId);
        } catch (\Exception $e) {
            Log::critical('Stripe webhook: failed to create new contact in Mailjet for email ' . $email . ': ' . $e->getMessage());
        }

        Log::info('Stripe webhook: order confirmed for cart ' . $cartId);
    }

    /**
     * Process the recovery cart process for a given cart ID.
     * This method is currently a placeholder and should be implemented to handle cart recovery logic.
     */
    public function handleCheckoutSessionExpired(\Stripe\StripeObject $session): void
    {
        $metadata = $session->metadata;
        $cartId = isset($metadata->cart_id) ? (int) $metadata->cart_id : 0;
        $carrierId = isset($metadata->id_carrier) ? (int) $metadata->id_carrier : null;
        $recoveryAttempt = isset($metadata->recovery_attempt) ? filter_var($metadata->recovery_attempt, FILTER_VALIDATE_BOOLEAN) : false;
        /**
         * @var OrderSession $orderSession
         */
        $orderSession = $this->tags(['order-session'])->getFromCache((string)$cartId);

        if(!isset($orderSession->id_customer) && !isset($orderSession->id_guest)) {
            throw new InvalidArgumentException("No customer details retrived from cache");
        }

        $customerId = $orderSession->id_customer;
        $guestId = $orderSession->id_guest;

        if ($cartId <= 0) {
            Log::warning('Stripe webhook: missing or invalid cart_id in metadata for expired session ' . $session->id);
            return;
        }

        if($recoveryAttempt === true) {
            // Skip processing if this is a recovery attempt to avoid infinite loops
            Log::info("Session already attempt");
            return;
        }

        $orderToCreate = $metadata->toArray();
        $orderToCreate['customer'] = $orderSession->customer;
        $orderToCreate['id_cart'] = $cartId;
        $orderToCreate['id_carrier'] = $carrierId;
        $orderToCreate['current_state'] = 0;
        $orderToCreate['date_add'] = date('Y-m-d H:i:s');
        $orderToCreate['recovery_attempt'] = true;

        Log::info('Creating order for cart ' . $cartId . ' with customer ID ' . $customerId . ' and guest ID ' . $guestId);
        $cart = $this->orderService->getCartFromId($cartId, $customerId, $guestId);
        $newOrder = OrderEntity::create($orderToCreate, $this->orderService);

        $orderSession = $this->makeOrder($newOrder, $this->orderService);
        $paymentUrl = $this->stripeService->createPaymentSession($orderSession);

        // Server-side price validation: fetch each product price directly from the catalog.
        // Never use prices from the cart payload or any frontend-supplied value.
        foreach ($cart->toArray()['products'] ?? [] as $product) {
            $this->addProduct($product);
        }

        $lineItems = $this->lineItems($cart->toArray()['products']);
        // send email to customer with payment link and line items
        $this->mailer->sendRecoveryCartExpired($orderSession->getCustomer()->email, $paymentUrl, $lineItems, (string) $orderSession->total(), $orderSession->getCustomer()->firstname);

        Log::info('Stripe webhook: checkout session expired for cart ' . $cartId);
    }

    /**
     * @param array $cartProducts
     * @return array{name: mixed, photo: string, price: string[]}
     */
    private function lineItems(array $cartProducts): array
    {
        $items = [];
        foreach ($cartProducts as $item) {
            $product = Product::find((int) $item['id_product']);
            $idDefaultImage = ProductEntity::create(['id' => $item['id_product']], $this->orderService)->getImages()[0]->id ?? 0;

            $items[] = [
                'name' => $product->name,
                'photo' => build_product_image_url($idDefaultImage, $product->name, 'small_default'),
                'price' => number_format((float) $product->price, 2, '.', ''),
            ];
        }
        return $items;
    }
}
