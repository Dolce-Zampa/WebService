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
  function MapModalControllerConstructor(configuration) {
    this.display = display;
    this.close = close;
    this.restoreSelection = restoreSelection;

    let modal = null;
    let closeButton = null;
    let retryButton = null;
    let previouslyFocusedElement = null;
    let ajaxService = Packlink.ajaxService;

    let locations = [];
    let selectedId = null;
    let lang = null;

    function usesInpostEndpoint() {
      return configuration.useInpostEndpoint === true;
    }

    function display() {
      modal = document.getElementById('pl-map-modal');
      if (!modal) {
        return;
      }

      previouslyFocusedElement = document.activeElement;
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      setLoading(true);
      clearFeedback();

      closeButton = document.getElementById('pl-close-modal-btn');
      if (closeButton) {
        closeButton.addEventListener('click', closeClickedListener);
      }

      retryButton = document.getElementById('pl-modal-retry-btn');
      if (retryButton) {
        retryButton.addEventListener('click', retryClickedListener);
      }

      modal.addEventListener('keydown', modalKeydownHandler);
      lang = configuration.lang ? configuration.lang : 'en';

      if (closeButton) {
        closeButton.focus();
      }

      if (usesInpostEndpoint()) {
        getInpostSelection();
      } else {
        selectedId = configuration.dropOffId || null;
        getLegacyLocations();
      }
    }

    /**
     * Retrieves the saved dedicated-endpoint selection without opening the modal.
     *
     * @param {function} onSuccess
     * @param {function} onError
     */
    function restoreSelection(onSuccess, onError) {
      if (!usesInpostEndpoint()) {
        onSuccess(null);
        return;
      }

      ajaxService.post(
          configuration.getUrl,
          {method: 'getSelection'},
          function (response) {
            onSuccess(getSelectionFromResponse(response));
          },
          onError
      );
    }

    /**
     * Closes modal.
     */
    function close() {
      if (!modal) {
        return;
      }

      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      modal.removeEventListener('keydown', modalKeydownHandler);

      if (closeButton) {
        closeButton.removeEventListener('click', closeClickedListener);
      }

      if (retryButton) {
        retryButton.removeEventListener('click', retryClickedListener);
      }

      if (previouslyFocusedElement && typeof previouslyFocusedElement.focus === 'function') {
        previouslyFocusedElement.focus();
      }

      closeButton = null;
      retryButton = null;
      modal = null;
    }

    /**
     * Handles communication with location picker library.
     *
     * @param id
     */
    function onDropoffSelected(id) {
      selectedId = id;
      selectDropoff();
    }

    /**
     * Gets the saved selection before loading the current list. The endpoint
     * determines the cart and carrier from the server-side context.
     */
    function getInpostSelection() {
      ajaxService.post(
          configuration.getUrl,
          {method: 'getSelection'},
          function (response) {
            let selection = getSelectionFromResponse(response);
            selectedId = selection && selection.id ? String(selection.id) : null;
            getInpostLocations();
          },
          getLocationsErrorHandler
      );
    }

    /**
     * Retrieves dedicated-endpoint locations. It intentionally carries no
     * client supplied cart, carrier, address, method or drop-off data.
     */
    function getInpostLocations() {
      ajaxService.post(
          configuration.getUrl,
          {method: 'getLocations'},
          getLocationsSuccessHandler,
          getLocationsErrorHandler
      );
    }

    /**
     * Keeps the generic Packlink flow intact for carriers not explicitly
     * marked as InPost in the checkout configuration.
     */
    function getLegacyLocations() {
      let locationsModel = {
        method: 'getLocations',
        methodId: configuration.methodId
      };

      if (configuration.addressId) {
        locationsModel.addressId = configuration.addressId;
      }

      ajaxService.post(configuration.getUrl, locationsModel, getLocationsSuccessHandler);
    }

    /**
     * Location retrieve success handler.
     *
     * @param response
     */
    function getLocationsSuccessHandler(response) {
      locations = Array.isArray(response) ? response : (response && response.locations) || [];

      if (!locations.length) {
        setLoading(false);
        configuration.onComplete({type: 'no-locations'});
        return;
      }

      submitLocations();
    }

    /**
     * Submits location to location library.
     */
    function submitLocations() {
      Packlink.locationPicker.display(locations, onDropoffSelected, selectedId, lang);
      setLoading(false);
    }

    /**
     * Handles failed requests for the dedicated endpoint in the modal rather
     * than exposing proxy details to the customer.
     */
    function getLocationsErrorHandler() {
      setLoading(false);
      showFeedback(getTranslation('loadError'));
    }

    /**
     * Handles close button clicked event.
     */
    function closeClickedListener() {
      configuration.onComplete({type: 'close'});
    }

    /**
     * Handles retry button clicked event.
     */
    function retryClickedListener() {
      clearFeedback();
      setLoading(true);

      if (usesInpostEndpoint()) {
        getInpostSelection();
      } else {
        getLegacyLocations();
      }
    }

    /**
     * Supports closing the modal with Escape and keeps keyboard focus inside it.
     *
     * @param event
     */
    function modalKeydownHandler(event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        configuration.onComplete({type: 'close'});
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      let focusable = modal.querySelectorAll(
          'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
      if (!focusable.length) {
        return;
      }

      let first = focusable[0];
      let last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    /**
     * Selects dropoff location.
     */
    function selectDropoff() {
      setLoading(true);

      let dropOffModel;
      if (usesInpostEndpoint()) {
        dropOffModel = {
          method: 'selectLocation',
          pointId: selectedId
        };
      } else {
        dropOffModel = {
          method: 'postSelectedDropoff',
          carrierId: configuration.carrierId,
          dropOff: getSelectedDropoff()
        };

        if (configuration.cartId) {
          dropOffModel.cartId = configuration.cartId;
          dropOffModel.orderId = configuration.orderId;
        }
      }

      ajaxService.post(
          configuration.getUrl,
          dropOffModel,
          selectDropoffSuccessHandler,
          selectDropoffErrorHandler
      );
    }

    /**
     * Select dropoff location success callback.
     *
     * @param response
     */
    function selectDropoffSuccessHandler(response) {
      setLoading(false);
      let selectedDropoff = usesInpostEndpoint()
          ? getSelectionFromResponse(response) || getSelectedDropoff()
          : getSelectedDropoff();

      if (!selectedDropoff || !selectedDropoff.id) {
        showFeedback(getTranslation('loadError'));
        return;
      }

      configuration.onComplete(
          {
            type: 'success',
            address: getAddressString(selectedDropoff),
            dropOff: selectedDropoff
          }
      );
    }

    /**
     * Keeps an invalid selection from enabling checkout.
     */
    function selectDropoffErrorHandler() {
      setLoading(false);
      showFeedback(getTranslation('selectError'));
    }

    /**
     * Extracts a point from the intentionally small dedicated endpoint
     * response while tolerating the legacy DTO names used by Packlink.
     *
     * @param response
     * @return {object|null}
     */
    function getSelectionFromResponse(response) {
      if (!response) {
        return null;
      }

      if (typeof response === 'string' || typeof response === 'number') {
        return {id: String(response)};
      }

      let selection = response.selection || response.location || response.dropOff || response;
      if (!selection || typeof selection !== 'object') {
        return null;
      }

      if (!selection.id && selection.pointId) {
        selection = Object.assign({}, selection, {id: String(selection.pointId)});
      }

      return selection.id ? selection : null;
    }

    /**
     * Retrieves selected dropoff.
     *
     * @return {object}
     */
    function getSelectedDropoff() {
      for (let loc of locations) {
        if (String(loc.id) === String(selectedId)) {
          return loc;
        }
      }

      return {};
    }

    /**
     * Returns formatted address.
     *
     * @param {object} location
     * @return {string}
     */
    function getAddressString(location) {
      return [location.name, location.address, location.zip, location.city]
          .filter(function (part) { return part !== null && typeof part !== 'undefined' && part !== ''; })
          .join(', ');
    }

    function setLoading(isLoading) {
      let spinner = document.getElementById('pl-modal-spinner');
      if (spinner) {
        spinner.classList.toggle('disabled', !isLoading);
      }
    }

    function showFeedback(message) {
      let feedback = document.getElementById('pl-modal-feedback');
      let messageElement = document.getElementById('pl-modal-feedback-message');
      if (!feedback || !messageElement) {
        return;
      }

      messageElement.textContent = message;
      feedback.classList.remove('pl-hidden');
      if (retryButton) {
        retryButton.textContent = getTranslation('retry');
        retryButton.focus();
      }
    }

    function clearFeedback() {
      let feedback = document.getElementById('pl-modal-feedback');
      if (feedback) {
        feedback.classList.add('pl-hidden');
      }
    }

    function getTranslation(key) {
      return configuration.trans && configuration.trans[key]
          ? configuration.trans[key]
          : key;
    }
  }

  Packlink.MapModalController = MapModalControllerConstructor;
})();
