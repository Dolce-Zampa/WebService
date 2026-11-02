/**
 * 2025 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache License 2.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0.txt
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2025 Packlink Shipping S.L
 * @license   http://www.apache.org/licenses/LICENSE-2.0.txt  Apache License 2.0
 */
var Packlink = window.Packlink || {};

(function () {
    function OfflinePaymentsController(configuration) {

        let offlineModules = [];
        const ajaxService = Packlink.customAjaxService;

        const hideOfflinePayments = () => {
            const paymentMethods = document.querySelectorAll('#checkout-payment-step input[name="payment-option"]');
            paymentMethods.forEach(method => {
                if (offlineModules.includes(method.dataset.moduleName)) {
                    const container = method.closest('.payment-option') || method.closest('.payment__option');
                    if (container) {
                        container.style.display = 'none';
                        if (method.checked) {
                            method.checked = false;
                        }
                    }
                }
            });
        };

        this.init = function () {
            const checkoutContainer = document.querySelector('#checkout');
            if (!checkoutContainer) return;

            if (configuration.selectedService === null) {
                offlineModules = [];
            } else {
                let payload = {selectedService: configuration.selectedService};
                    ajaxService.post(
                        configuration.offlinePaymentMethods,
                        payload,
                        (response) => {
                            try {
                                offlineModules = response.data.map(module => module.name);
                                hideOfflinePayments();
                            } catch (e) {
                                console.error('Failed to parse offline payment modules:', e, response);
                            }
                        }
                    );
            }

            const observer = new MutationObserver(() => {
                const paymentStep = document.querySelector('#checkout-payment-step');
                let payload = {selectedService: configuration.selectedService};
                if (paymentStep) {
                    ajaxService.post(
                        configuration.offlinePaymentMethods,
                        payload,
                        (response) => {
                            try {
                                offlineModules = response.data.map(module => module.name);
                                hideOfflinePayments();
                            } catch (e) {
                                console.error('Failed to parse offline payment modules:', e, response);
                            }
                        }
                    );
                }
            });

            observer.observe(checkoutContainer, { childList: true, subtree: true });
        };
    }

    Packlink.OfflinePaymentsController = OfflinePaymentsController;
})();
