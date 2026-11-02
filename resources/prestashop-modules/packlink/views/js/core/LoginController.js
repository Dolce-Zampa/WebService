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
     * Handles login page logic.
     *
     * @param {{submit: string, listOfCountriesUrl: string, connect: string}} configuration
     * @constructor
     */
    function LoginController(configuration) {
        const templateService = Packlink.templateService,
            ajaxService = Packlink.ajaxService,
            state = Packlink.state,
            errorMessage = Packlink.translationService.translate('login.apiKeyIncorrect');

        const templateId = 'pl-login-page';
        let loginBtn, connectBtn, inputElem;

        /**
         * Displays page content.
         */
        this.display = () => {
            templateService.setCurrentTemplate(templateId);
            const loginPage = templateService.getMainPage();

            // Check for apiKey form version
            loginBtn = templateService.getComponent('pl-login-button', loginPage);
            inputElem = templateService.getComponent('pl-login-api-key', loginPage);

            if (inputElem && loginBtn) {
                // API Key form mode
                inputElem.addEventListener('input', enableButton);
                templateService.getComponent('pl-login-form', loginPage).addEventListener('submit', login);
            }

            // Check for OAuth connect version
            connectBtn = templateService.getComponent('pl-connect-button', loginPage);
            if (connectBtn) {
                connectBtn.disabled = false;
                connectBtn.addEventListener('click', connect);
            }

            templateService.getComponent('pl-go-to-register', loginPage).addEventListener('click', goToRegister);

            Packlink.utilityService.hideSpinner();
        };

        /**
         * Handles form submit for API key login.
         */
        const login = (event) => {
            event.preventDefault();
            Packlink.utilityService.showSpinner();

            const apiKey = event.target['apiKey'].value;
            ajaxService.post(configuration.submit, { apiKey }, successfulLogin, failedLogin);
            return false;
        };

        const connect = (event) => {
            event.preventDefault();
            Packlink.utilityService.showSpinner();

            /**
             * @type {HTMLSelectElement|null}
             */
            const selectElem = templateService.getMainPage().querySelector('#pl-connect-select-country');
            const selectedCountry = (selectElem && selectElem.value) ? selectElem.value : 'WW';

            let url = configuration.connect;
            url += url.includes('?') ? '&' : '?';
            url += 'domain=' + encodeURIComponent(selectedCountry);

            ajaxService.get(url, (response) => {
                if (response && response.redirectUrl) {
                    window.open(response.redirectUrl, '_blank');
                } else {
                    failedLogin();
                }
            }, failedLogin);

            return false;
        };


        /**
         * Redirects to register modal.
         */
        const goToRegister = (event) => {
            event.preventDefault();

            let registerModalController = new Packlink.RegisterModalController(
                'pl-modal-mask',
                configuration.listOfCountriesUrl,
            );
            registerModalController.display();

            return false;
        };

        const enableButton = (event) => {
            Packlink.validationService.removeError(inputElem);
            loginBtn.disabled = event.target.value.length === 0;
        };

        const successfulLogin = (response) => {
            if (response.success) {
                state.goToState('onboarding-state');
            } else {
                failedLogin();
            }
        };

        const failedLogin = () => {
            if (inputElem) {
                Packlink.validationService.setError(inputElem, errorMessage);
            }
            Packlink.utilityService.hideSpinner();
        };
    }

    Packlink.LoginController = LoginController;
})();
