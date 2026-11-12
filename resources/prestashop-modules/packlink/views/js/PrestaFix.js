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
/**
 * Hides presta shop spinner.
 */
function hidePrestaSpinner() {
  let prestaSpinner = document.getElementById("ajax_running");

  if (prestaSpinner) {
    prestaSpinner.style.display = "none";
  }
}

/**
 * Calculates content height.
 *
 * Footer can be dynamically hidden or displayed by Prestashop,
 * so we have to periodically recalculate content height.
 *
 * @param {number} offset
 */
function calculateContentHeight(offset) {
  if (typeof offset === 'undefined') {
    offset = 0;
  }

  let content = document.getElementById('pl-page');
  let localOffset = offset + content.offsetTop + 20;

  let footer = document.getElementById('footer');
  if (footer) {
    localOffset += footer.clientHeight;
  }

  content.style.height = `calc(100vh - ${localOffset}px`;

  setTimeout(calculateContentHeight, 250, offset);
}
