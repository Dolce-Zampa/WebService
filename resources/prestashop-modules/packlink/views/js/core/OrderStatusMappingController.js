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
    /**
     * @param {{getMappingAndStatusesUrl: string, setUrl: string}} configuration
     *
     * @constructor
     */
    function OrderStatusMappingController(configuration) {
        const templateService = Packlink.templateService,
            utilityService = Packlink.utilityService,
            translator = Packlink.translationService,
            ajaxService = Packlink.ajaxService,
            state = Packlink.state,
            templateId = 'pl-order-status-mapping-page';

        let mappings = {};

        /**
         * Handles mapping changed event.
         *
         * @param event
         */
        const mappingChangedHandler = event => {
            mappings[event.target.name] = event.target.value;
        };

        /**
         * Saves order status mappings.
         */
        const saveOrderStatusMappings = () => {
            utilityService.showSpinner();
            ajaxService.post(
                configuration.setUrl,
                mappings,
                () => {
                    state.goToState('configuration');
                },
                Packlink.responseService.errorHandler
            );
        };

        /**
         * Appends new status.
         *
         * @param {string} statusCode
         * @param {string} statusLabel
         * @param {string} mappedValue
         * @param {[]} orderStatuses
         * @return {Element}
         */
        const injectStatus = (statusCode, statusLabel, mappedValue, orderStatuses) => {
            const div = document.createElement('div');

            div.innerHTML = templateService.getComponent('pl-status-mapping-template').innerHTML;
            div.querySelector('.pl-packlink-status').innerHTML = statusLabel;
            const select = div.querySelector('select');
            select.name = statusCode;

            for (const orderStatus in orderStatuses) {
                if (orderStatuses.hasOwnProperty(orderStatus)) {
                    const option = document.createElement('option');
                    option.value = orderStatus;
                    option.innerHTML = orderStatuses[orderStatus];
                    select.appendChild(option);
                }
            }

            select.value = mappedValue || '';
            select.addEventListener('change', mappingChangedHandler, true);

            return div.firstElementChild;
        };

        /**
         * Finalizes page load by applying selected mappings to selection form.
         *
         * @param {{packlinkStatuses: {}, mappings: {}, orderStatuses: {}, systemName: string}} response
         */
        const constructPage = (response) => {
            const page = templateService.getMainPage().querySelector('.pl-order-status-mapping-page'),
                name = [response.systemName],
                mappingsDiv = templateService.getComponent('pl-order-status-mappings'),
                btn = templateService.getComponent('pl-page-submit-btn'),
                statuses = response.packlinkStatuses;

            mappings = response.mappings;
            for (const status in statuses) {
                mappingsDiv.appendChild(injectStatus(status, statuses[status], mappings[status], response.orderStatuses));
            }

            btn.addEventListener('click', saveOrderStatusMappings, true);

            page.querySelector('.pl-page-info').innerHTML = translator.translate('orderStatusMapping.description', name);

            page.querySelector('.pl-order-status-mappings-header .pl-system-order-status').innerHTML = translator.translate(
                'orderStatusMapping.systemOrderStatus',
                name
            );

            utilityService.hideSpinner();
        };

        /**
         * Displays the page.
         */
        this.display = () => {
            templateService.setCurrentTemplate(templateId);
            const mainPage = templateService.getMainPage(),
                backButton = mainPage.querySelector('.pl-sub-header button');

            backButton.addEventListener('click', () => {
                state.goToState('configuration');
            });

            ajaxService.get(configuration.getMappingAndStatusesUrl, constructPage);
        };
    }

    Packlink.OrderStatusMappingController = OrderStatusMappingController;
})();
