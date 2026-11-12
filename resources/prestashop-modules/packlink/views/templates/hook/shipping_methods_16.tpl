{**
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
 *}
<div class="pl-template">
  <div class="row pl-dropof" id="pl-dropoff">
    <div class="col-md-12">
      <div class="row">
        <div class="col-md-12 pl-sm-margin-bottom">
          <p style="font-size: 12px">
            {l s='This shipping service supports delivery to pre-defined drop-off locations. Please choose location that suits you the most by clicking on the "Select drop-off location" button.' mod='packlink' }
          </p>
        </div>
      </div>
      <div class="row pl-sm-margin-bottom">
        <div class="col-xs-5">
          <span id="pl-message" class="pl-message"></span>
        </div>
        <div class="col-xs-7">
          <div class="btn btn-primary button-primary pull-right" id="pl-dropoff-button">
          </div>
        </div>
      </div>
    </div>
  </div>

  <location-picker-template>
    <div class="lp-template" id="template-container">
      <div data-lp-id="working-hours-template" class="lp-hour-wrapper">
        <div class="day" data-lp-id="day">
        </div>
        <div class="hours" data-lp-id="hours">
        </div>
      </div>

      <div class="lp-location-wrapper" data-lp-id="location-template">
        <div class="composite lp-expand">
          <div class="street-name uppercase" data-lp-id="composite-address"></div>
          <button type="button" class="lp-working-hours-btn excluded" data-lp-composite data-lp-id="show-composite-working-hours-btn"></button>
          <div data-lp-id="composite-working-hours" class="lp-working-hours">

          </div>
          <div class="lp-select-column">
            <button type="button" class="lp-select-button excluded" data-lp-id="composite-select-btn"></button>
            <a class="excluded" href="#" data-lp-id="composite-show-on-map" target="_blank"></a>
          </div>
        </div>
        <div class="name uppercase lp-collapse" data-lp-id="location-name"></div>
        <div class="street lp-collapse">
          <div class="street-name uppercase" data-lp-id="location-street"></div>
          <button type="button" class="lp-working-hours-btn excluded" data-lp-id="show-working-hours-btn"></button>
          <div data-lp-id="working-hours" class="lp-working-hours">

          </div>
        </div>
        <div class="city uppercase lp-collapse" data-lp-id="location-city">
        </div>
        <div class="lp-select-column lp-collapse">
          <button type="button" class="lp-select-button excluded" data-lp-id="select-btn"></button>
        </div>
        <a class="excluded lp-collapse" href="#" data-lp-id="show-on-map" target="_blank">
          <div class="lp-show-on-map-btn excluded"></div>
        </a>
      </div>
    </div>
  </location-picker-template>
</div>

<div class="pl-input-mask hidden" id="pl-map-modal" role="dialog" aria-modal="true" aria-label="{l s='Choose drop-off location' mod='packlink'}" aria-hidden="true">
  <div class="pl-map-modal" id="pl-modal-content">
    <div class="pl-modal-spinner-wrapper disabled" id="pl-modal-spinner">
      <div class="pl-modal-spinner"></div>
    </div>
    <button type="button" class="pl-close-modal" id="pl-close-modal-btn" aria-label="{l s='Close' mod='packlink'}">X</button>
    <div class="pl-modal-feedback pl-hidden" id="pl-modal-feedback" role="status" aria-live="polite">
      <span id="pl-modal-feedback-message"></span>
      <button type="button" class="btn btn-primary" id="pl-modal-retry-btn"></button>
    </div>

    <location-picker>
      <div class="lp-content" data-lp-id="content">
        <div class="lp-locations">
          <div class="lp-input-wrapper">
            <div class="input">
              <input type="text" aria-label="{l s='Search drop-off locations' mod='packlink'}" data-lp-id="search-box">
              <span class="label" data-lp-id="search-box-label"></span>
            </div>
          </div>

          <div data-lp-id="locations"></div>
        </div>
      </div>
    </location-picker>

  </div>
</div>

<script>
  Packlink.trans = {
    select: "{l s='Select drop-off location' mod='packlink'}",
    change: "{l s='Change drop-off location' mod='packlink'}",
    address: "{l s='Package will be delivered to:' mod='packlink'}",
    wrongAddress: "{l s='There are no delivery locations available for your delivery address. Please change your address.' mod='packlink'}"
  };

  Packlink.checkOut = new Packlink.CheckOutController(
      JSON.parse(
          '{$configuration|json_encode|escape:'htmlall':'UTF-8'|htmlspecialchars_decode:3}'
              .replace(/&quot;/g, '"')
              .replace(/&amp;/g, '&')
      )
  )

  function checkLoadStatus() {
    let elements = document.getElementsByClassName('delivery_option_radio');
    if (elements.length === 0) {
      setTimeout(checkLoadStatus, 100);
    } else {
      Packlink.checkOut.init();
    }
  }

  checkLoadStatus();
</script>
