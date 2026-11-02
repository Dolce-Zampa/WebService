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
    function OnboardingStateController(configuration) {

        const state = Packlink.state,
            ajaxService = Packlink.ajaxService,
            welcomeController = 'onboarding-welcome',
            overviewController = 'onboarding-overview';

        /**
         * Displays page content.
         */
        this.display = function () {
            ajaxService.get(configuration.getState, showPageBasedOnState);
        };

        function showPageBasedOnState(response) {
            if (response.state === 'welcome') {
                state.goToState(welcomeController);
            } else {
                state.goToState(overviewController);
            }
        }
    }

    Packlink.OnboardingStateController = OnboardingStateController;
})();
