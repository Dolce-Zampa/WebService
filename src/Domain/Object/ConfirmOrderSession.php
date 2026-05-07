<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

class ConfirmOrderSession implements ObjectInterface
{
    use UuidGenerator;

    protected array $data;

    const PAYMENT_METHOD_STRIPE = 'stripe';
    const ORDER_STATE = [
        'failed' => 1, // This should ideally be determined based on the order/cart details
        'confirm' => 2, // Prestashop: Pagamento Accettato
        'payment_success' => 3, // This should ideally be determined based on the order/cart details
    ];

    private CustomerEntity $customer;

    private function __construct(array $data)
    {
        $this->data = $data;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
    {
        return new self($data);
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function shouldCreateAccount(): bool
    {
        return (bool)($this->data['create_account'] ?? false);
    }
    
    public function getDeliveryAddress(): ?array
    {
        return $this->getCustomer()->delivery_address ?? null;
    }

    public function getInvoiceAddress(): ?array
    {
        return $this->getCustomer()->invoice_address ?? $this->getDeliveryAddress();
    }

    public function setCustomer(CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getCustomer(): CustomerEntity
    {
        return $this->customer;
    }

    public function normalizeData(): void
    {
        $data = $this->data;
        $this->data = [
            'payment_module' => 'ps_checkout', // Default payment module, should be determined dynamically based on the cart details
            'id_order_state' => (int) $data['order_state'],
            'payment_label' => $data['payment_label'] ?? 'Headless',
            'amount_paid' => (float) $data['amount_paid'],
            'id_cart' => $data['id_cart'],
            'id_customer' => $data['id_customer'],
            'id_guest' => $data['id_guest'],
            'create_account' => (bool)($data['create_account'] ?? false),
            'id_carrier' => isset($data['id_carrier']) ? (int) $data['id_carrier'] : 14,
        ];
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->data['id_cart'] <= 0) {
            $errors[] = 'id_cart is required and must be greater than 0';
        }

        if ($this->data['amount_paid'] <= 0) {
            $errors[] = 'amount_paid is required and must be greater than 0';
        }

        if ($this->data['id_order_state'] <= 0) {
            $errors[] = 'id_order_state is required and must be greater than 0';
        }

        if ($this->data['id_customer'] === null && $this->data['id_guest'] === null) {
            $errors[] = 'Either id_customer or id_guest must be provided';
        }

        return $errors;
    }

    public function generatePayload(): PayloadServiceData
    {
        return new PayloadServiceData($this->data, ['id_cart' => 'cart', 'id_customer' => 'customer', 'id_guest' => 'guest']);
    }
}
