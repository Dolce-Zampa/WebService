<?php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

/** Internal endpoint: the API must retire existing payment sessions before calling it. */
class webserviceapipurchaseprotectionModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    protected function handleRequest()
    {
        $this->assertRequestMethod(array('POST'));
        if (!filter_var(getenv('PURCHASE_PROTECTION_MODULE_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            throw new MlabFactoryApiException('Purchase protection is unavailable.', 503);
        }

        $data = $this->getJsonPayload();
        $cartId = filter_var($data['id_cart'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $productId = filter_var($data['id_product'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $configuredProduct = getenv('PURCHASE_PROTECTION_PRODUCT_ID');
        if ($configuredProduct === false || $configuredProduct === '') {
            $configuredProduct = Configuration::get('PURCHASE_PROTECTION_PRODUCT_ID');
        }
        $configuredId = filter_var($configuredProduct, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $customerId = (int) ($data['id_customer'] ?? 0);
        $guestId = (int) ($data['id_guest'] ?? 0);
        if ($cartId === false || $productId === false || $configuredId === false || $productId !== $configuredId
            || ($customerId > 0) === ($guestId > 0) || !array_key_exists('price_cents', $data)) {
            throw new MlabFactoryApiException('Invalid protection request.', 422);
        }
        $price = $data['price_cents'];
        if ($price !== null && (!is_int($price) || $price < 1)) {
            throw new MlabFactoryApiException('Invalid protection price.', 422);
        }
        $cart = new Cart($cartId);
        if (!Validate::isLoadedObject($cart)
            || ($customerId > 0 && (int) $cart->id_customer !== $customerId)
            || ($guestId > 0 && (int) $cart->id_guest !== $guestId)) {
            throw new MlabFactoryApiException('Cart not found.', 404);
        }
        MlabFactoryCoupon::setContext($cart, $this->context);
        $product = new Product($productId, false, (int) $cart->id_lang, (int) $cart->id_shop);
        if (!Validate::isLoadedObject($product) || !$product->is_virtual || !$product->active
            || !$product->available_for_order || $product->visibility !== 'none' || $product->hasCombinations()) {
            throw new MlabFactoryApiException('Protection product is not configured.', 503);
        }

        $serviceLines = array();
        foreach ($cart->getProducts(true) as $line) {
            if ((int) $line['id_product'] === $productId) $serviceLines[] = $line;
        }
        foreach ($serviceLines as $line) {
            if (count($serviceLines) !== 1 || (int) $line['cart_quantity'] !== 1
                || !empty($line['id_product_attribute']) || !empty($line['id_customization'])) {
                throw new MlabFactoryApiException('Invalid protection cart line.', 409);
            }
        }

        if ($price === null) {
            if ($serviceLines && !$cart->deleteProduct($productId)) {
                throw new MlabFactoryApiException('Unable to remove protection.', 500);
            }
            if (!SpecificPrice::deleteByIdCart($cartId, $productId, 0)) {
                throw new MlabFactoryApiException('Unable to remove protection price.', 500);
            }
            SpecificPrice::flushCache();
            $cart->getProducts(true);
            return array('cart' => MlabFactoryApiHelper::serializeCart($cart));
        }
        $existingPrice = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_cart = ' . $cartId
            . ' AND id_product = ' . $productId
        );
        if ($existingPrice === false || $serviceLines || (int) $existingPrice > 0) {
            throw new MlabFactoryApiException('Remove the existing protection before repricing.', 409);
        }

        $before = MlabFactoryApiHelper::serializeCart($cart);
        $addressType = Configuration::get('PS_TAX_ADDRESS_TYPE') === 'id_address_invoice'
            ? 'id_address_invoice' : 'id_address_delivery';
        $address = new Address((int) $cart->{$addressType});
        if (!Validate::isLoadedObject($address)) {
            throw new MlabFactoryApiException('A checkout address is required.', 422);
        }
        $taxGroup = Product::getIdTaxRulesGroupByIdProduct($productId, $this->context);
        // specific_price.price is DECIMAL(20,6); PrestaShop rejects an
        // unrounded tax-exclusive float with more than nine decimal digits.
        $calculator = TaxManagerFactory::getManager($address, $taxGroup)->getTaxCalculator();
        $net = round($calculator->removeTaxes($price / 100), 6);
        $specific = new SpecificPrice();
        $specific->id_shop_group = 0;
        $specific->id_shop = (int) $cart->id_shop;
        $specific->id_cart = $cartId;
        $specific->id_product = $productId;
        $specific->id_product_attribute = 0;
        $specific->id_currency = (int) $cart->id_currency;
        $specific->id_country = 0;
        $specific->id_group = 0;
        $specific->id_customer = (int) $cart->id_customer;
        $specific->price = $net;
        $specific->from_quantity = 1;
        $specific->reduction = 0;
        $specific->reduction_tax = 1;
        $specific->reduction_type = 'amount';
        $specific->from = '0000-00-00 00:00:00';
        $specific->to = '0000-00-00 00:00:00';
        if (!$specific->add()) throw new MlabFactoryApiException('Unable to price protection.', 500);

        try {
            if ($cart->updateQty(1, $productId, null, false, 'up', (int) $cart->id_address_delivery, null, false, true) <= 0) {
                throw new MlabFactoryApiException('Unable to add protection.', 422);
            }
            $cart->getProducts(true);
            $after = MlabFactoryApiHelper::serializeCart($cart);
            $oldTotal = (int) round($before['totals']['grand_total_tax_incl'] * 100);
            $oldShipping = (int) round($before['totals']['shipping_tax_incl'] * 100);
            $newTotal = (int) round($after['totals']['grand_total_tax_incl'] * 100);

            // A cart-wide coupon can discount the service as well. Find a
            // cart-specific list price whose final contribution is exactly the
            // agreed fee; never change the coupon or the goods to compensate.
            if ($newTotal !== $oldTotal + $price) {
                $low = 1;
                $high = $price;
                $maxPrice = $price <= intdiv(PHP_INT_MAX, 1000) ? min(999999999999, $price * 1000) : 999999999999;
                while ($newTotal < $oldTotal + $price && $high < $maxPrice) {
                    $low = $high + 1;
                    $high = min($maxPrice, $high * 2);
                    $after = $this->priceService($cart, $specific, $calculator, $high);
                    $newTotal = (int) round($after['totals']['grand_total_tax_incl'] * 100);
                }
                while ($newTotal !== $oldTotal + $price && $low <= $high) {
                    $candidate = $low + intdiv($high - $low, 2);
                    $after = $this->priceService($cart, $specific, $calculator, $candidate);
                    $newTotal = (int) round($after['totals']['grand_total_tax_incl'] * 100);
                    if ($newTotal < $oldTotal + $price) $low = $candidate + 1;
                    elseif ($newTotal > $oldTotal + $price) $high = $candidate - 1;
                }
            }
            $newShipping = (int) round($after['totals']['shipping_tax_incl'] * 100);
            if ($newTotal !== $oldTotal + $price || $newShipping !== $oldShipping) {
                throw new MlabFactoryApiException('Protection price is incompatible with the cart.', 409);
            }
            return array('cart' => $after);
        } catch (Throwable $error) {
            // No payment may be created after this failed operation. Clean up the
            // service line and its cart-specific price before returning an error.
            $cart->deleteProduct($productId);
            $specific->delete();
            throw $error;
        }
    }

    private function priceService(Cart $cart, SpecificPrice $specific, $calculator, $grossCents)
    {
        $specific->price = round($calculator->removeTaxes($grossCents / 100), 6);
        if (!$specific->update()) throw new MlabFactoryApiException('Unable to reprice protection.', 500);
        if (method_exists($cart, 'resetProductRelatedStaticCache')) $cart->resetProductRelatedStaticCache();
        $cart->getProducts(true);
        return MlabFactoryApiHelper::serializeCart($cart);
    }
}
