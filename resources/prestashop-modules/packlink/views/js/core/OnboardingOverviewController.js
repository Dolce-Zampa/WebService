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
if (!window.Packlink) {
    window.Packlink = {};
}

(function () {
    function OnboardingOverviewController(configuration) {

        const templateService = Packlink.templateService,
            ajaxService = Packlink.ajaxService,
            translationService = Packlink.translationService,
            state = Packlink.state,
            templateId = 'pl-onboarding-overview-page';

        /**
         * @var {Parcel}
         */
        let defaultParcel;
        /**
         * @var {Warehouse}
         */
        let defaultWarehouse;

        /**
         * Displays page content.
         */
        this.display = function () {
            ajaxService.get(configuration.defaultParcelGet, fetchDefaultWarehouse);
        };

        function fetchDefaultWarehouse(response) {
            defaultParcel = response;
            ajaxService.get(configuration.defaultWarehouseGet, initializePage);
        }

        function initializePage(response) {
            defaultWarehouse = response;
            templateService.setCurrentTemplate(templateId);

            const segments = templateService.getMainPage().querySelectorAll('.pl-onboarding-overview-list .pl-list-item');
            populateSegment(segments[0], !!defaultParcel.weight, 'default-parcel', () => {
                return translationService.translate('onboardingOverview.parcelData', [
                    defaultParcel.weight, defaultParcel.height, defaultParcel.width, defaultParcel.length
                ]);
            });
            populateSegment(segments[1], !!defaultWarehouse.postal_code, 'default-warehouse', () => {
                return translationService.translate('onboardingOverview.warehouseData', [
                    defaultWarehouse.alias,
                    defaultWarehouse.name + ' ' + defaultWarehouse.surname,
                    defaultWarehouse.company || '-'
                ]);
            });

            const submitBtn = templateService.getComponent('pl-onboarding-overview-button');
            submitBtn.disabled = !defaultParcel.weight || !defaultWarehouse.postal_code;
            submitBtn.addEventListener('click', () => {
                state.goToState('my-shipping-services');
            });

            Packlink.utilityService.hideSpinner();
        }

        const populateSegment = (segment, data, editState, infoProvider) => {
            const icon = segment.querySelector('i');
            const button = segment.querySelector('button');
            const details = segment.querySelector('.pl-item-details');

            button.addEventListener('click', () => {
                state.goToState(editState, {
                    'code': 'onboarding',
                    'prevState': 'onboarding-overview',
                    'nextState': 'onboarding-overview',
                });
            });

            if (!data) {
                icon.innerText = 'close';
                icon.classList.add('pl-error-text');
                button.classList.add('pl-button-primary');
                button.classList.remove('pl-button-secondary');
                button.innerText = translationService.translate('general.complete');
                details.innerHTML = translationService.translate('onboardingOverview.missingInfo');
            } else {
                icon.innerText = 'check';
                icon.classList.add('pl-icon-text');
                button.classList.remove('pl-button-primary');
                button.classList.add('pl-button-secondary');
                button.innerText = translationService.translate('general.edit');
                details.innerHTML = infoProvider();
            }
        };
    }

    Packlink.OnboardingOverviewController = OnboardingOverviewController;
})();
