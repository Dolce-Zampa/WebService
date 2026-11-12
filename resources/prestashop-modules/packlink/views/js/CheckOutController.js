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
  function CheckOutControllerConstructor(configuration) {
    let shippingService = Packlink.shippingService;

    this.init = init;

    let currentMethod = null;
    let dropOffIds = [];
    let inpostCarrierIds = {};
    let cashOnDeliveryIds = [];
    let mapController = null;
    let selectedId = null;
    let ids = [];
    let checkoutEventsRegistered = false;
    let refreshTimeout = null;

    function init() {
      dropOffIds = Object.keys(configuration.dropoffIds || {});
      inpostCarrierIds = configuration.inpostCarrierIds || {};
      cashOnDeliveryIds = configuration.cashOnDelivery || [];

      let dropOffs = shippingService.getDropOffShippingMethods(dropOffIds);
      if (shippingService.markCashOnDeliveryMethods) {
        ids = shippingService.markCashOnDeliveryMethods(cashOnDeliveryIds);
      }

      let selectedLocation = configuration.selectedLocation;
      let selectedCarrier = configuration.selectedCarrier;
      selectedId = selectedLocation && selectedLocation.id ? String(selectedLocation.id) : null;
      currentMethod = null;

      for (let dropOff of dropOffs) {
        if (dropOff.checked && dropOff.getAttribute('data-pl-dropoff') === 'true') {
          currentMethod = dropOff;
          let carrierId = currentMethod.getAttribute('data-pl-id');

          if (usesInpostEndpoint(carrierId)) {
            showDropoff(Packlink.trans.select, true);
            restoreInpostSelection();
          } else if (carrierId === selectedCarrier && selectedLocation) {
            showDropoff(Packlink.trans.change);
            showSelectedLocation(selectedLocation);
          } else {
            showDropoff(Packlink.trans.select, true);
          }
        }

        if (!dropOff.getAttribute('data-pl-checkout-bound')) {
          dropOff.addEventListener('change', dropoffChangedHandler);
          dropOff.setAttribute('data-pl-checkout-bound', 'true');
        }
      }

      for (let cod of ids) {
        if (cod.checked && cod.getAttribute('data-pl-cod') === 'true') {
          shippingService.showCODMessage(cod, configuration.offlineMethod);
        }

        if (!cod.getAttribute('data-pl-cod-bound')) {
          cod.addEventListener('change', codChangedHandler);
          cod.setAttribute('data-pl-cod-bound', 'true');
        }
      }

      registerCheckoutEvents();
    }

    /**
     * Handles changed event.
     *
     * @param event
     */
    function codChangedHandler(event) {
      currentMethod = event.target;

      if (currentMethod.getAttribute('data-pl-cod') === 'true') {
        shippingService.showCODMessage(currentMethod, configuration.offlineMethod);
      } else {
        shippingService.hideCODMessage();
      }
    }

    /**
     * Handles changed event.
     *
     * @param event
     */
    function dropoffChangedHandler(event) {
      currentMethod = event.target;
      selectedId = null;

      if (currentMethod.getAttribute('data-pl-dropoff') === 'true') {
        showDropoff(Packlink.trans.select, true);
        if (usesInpostEndpoint(currentMethod.getAttribute('data-pl-id'))) {
          restoreInpostSelection();
        }
      } else {
        hideDropoff();
      }
    }

    /**
     * Handles click event on select dropoff button.
     */
    function dropOffButtonClickedHandler() {
      if (!currentMethod) {
        return;
      }

      let id = currentMethod.getAttribute('data-pl-id');
      let isInpost = usesInpostEndpoint(id);

      mapController = new Packlink.MapModalController(
          {
            getUrl: isInpost ? configuration.inpostLocationsUrl : configuration.getLocationsUrl,
            methodId: isInpost ? null : configuration.dropoffIds[id],
            carrierId: id,
            useInpostEndpoint: isInpost,
            onComplete: modalCompleteCallback,
            dropOffId: selectedId,
            lang: configuration.lang,
            trans: Packlink.trans
          }
      );

      mapController.display();
    }

    /**
     * Rehydrates the active InPost point from the dedicated endpoint. The
     * endpoint owns cart, carrier and address resolution, so no such values
     * are sent from the browser.
     */
    function restoreInpostSelection() {
      if (!currentMethod || !usesInpostEndpoint(currentMethod.getAttribute('data-pl-id'))) {
        return;
      }

      let controller = new Packlink.MapModalController(
          {
            getUrl: configuration.inpostLocationsUrl,
            useInpostEndpoint: true,
            trans: Packlink.trans,
            onComplete: function () {}
          }
      );

      controller.restoreSelection(
          function (location) {
            if (!currentMethod || !usesInpostEndpoint(currentMethod.getAttribute('data-pl-id'))) {
              return;
            }

            if (location && location.id) {
              selectedId = String(location.id);
              showDropoff(Packlink.trans.change);
              showSelectedLocation(location);
              shippingService.enableSubmit();
            } else {
              selectedId = null;
              showDropoff(Packlink.trans.select, true);
            }
          },
          function () {
            selectedId = null;
            showDropoff(Packlink.trans.select, true);
            shippingService.setMessage(Packlink.trans.loadError);
          }
      );
    }

    /**
     * Handles callback from modal.
     *
     * @param payload
     */
    function modalCompleteCallback(payload) {
      if (mapController) {
        mapController.close();
        mapController = null;
      }

      if (payload.type === 'no-locations') {
        selectedId = null;
        shippingService.setMessage(Packlink.trans.wrongAddress);
        shippingService.disableSubmit();
      }

      if (payload.type === 'success') {
        selectedId = String(payload.dropOff.id);
        showSelectedLocation(payload.dropOff, payload.address);
        shippingService.changeBtnText(Packlink.trans.change);
        shippingService.enableSubmit();
      }
    }

    /**
     * Shows select dropoff section.
     *
     * @param {string} btnMsg
     * @param {boolean} [disable]
     */
    function showDropoff(btnMsg, disable) {
      shippingService.showDropOff(dropOffButtonClickedHandler, currentMethod, btnMsg);
      shippingService.setMessage('');

      if (disable) {
        shippingService.disableSubmit();
      }
    }

    /**
     * Hides dropoff select section.
     */
    function hideDropoff() {
      shippingService.hideDropOff();
      shippingService.enableSubmit();
    }

    /**
     * Renders endpoint-provided text without interpreting it as markup.
     *
     * @param {object} location
     * @param {string} [address]
     */
    function showSelectedLocation(location, address) {
      let formattedAddress = address || getAddressString(location);
      if (shippingService.setLocationMessage) {
        shippingService.setLocationMessage(Packlink.trans.address, formattedAddress);
      } else {
        shippingService.setMessage(Packlink.trans.address + '<br /> <i>' + formattedAddress + '</i>');
      }
    }

    /**
     * The dedicated route is opt-in. Existing non-InPost Packlink carriers
     * preserve their methodId-based controller contract.
     *
     * @param {string} carrierId
     * @return {boolean}
     */
    function usesInpostEndpoint(carrierId) {
      return !!configuration.inpostLocationsUrl
          && Object.prototype.hasOwnProperty.call(inpostCarrierIds, carrierId);
    }

    /**
     * Reloads the selection after PrestaShop refreshes the carrier/address
     * section. The following init sees the new DOM and asks the server whether
     * the previously selected point is still valid for the active cart.
     */
    function registerCheckoutEvents() {
      if (checkoutEventsRegistered) {
        return;
      }

      checkoutEventsRegistered = true;
      if (window.prestashop && typeof window.prestashop.on === 'function') {
        ['updatedDeliveryForm', 'updatedDeliveryOption', 'updatedAddressForm'].forEach(function (eventName) {
          window.prestashop.on(eventName, scheduleRefresh);
        });
      }
    }

    function scheduleRefresh() {
      if (refreshTimeout) {
        clearTimeout(refreshTimeout);
      }

      refreshTimeout = setTimeout(function () {
        refreshTimeout = null;
        init();
      }, 0);
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
  }

  Packlink.CheckOutController = CheckOutControllerConstructor;
})();
