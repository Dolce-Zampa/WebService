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
     * The ResponseService constructor.
     *
     * @constructor
     */
    function ResponseService() {
        const utilityService = Packlink.utilityService,
            validationService = Packlink.validationService;

        /**
         * Handles an error response from the submit action.
         *
         * @param {{success: boolean, error?: string, messages?: ValidationMessage[]}} response
         */
        this.errorHandler = (response) => {
            utilityService.hideSpinner();
            if (response.error) {
                utilityService.showFlashMessage(response.error, 'danger', 7000);
            } else if (response.messages) {
                validationService.handleValidationErrors(response.messages);
            } else {
                utilityService.showFlashMessage('Unknown error occurred.', 'danger', 7000);
            }
        };
    }

    Packlink.responseService = new ResponseService();
})();
