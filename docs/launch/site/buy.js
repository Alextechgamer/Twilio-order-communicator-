/**
 * Checkout link config — the ONE file to edit at go-live.
 *
 * Paste a Stripe Payment Link (or any checkout URL) next to each tier.
 * Buttons with a URL become direct checkout links. Buttons without one
 * fall back to the email-to-buy link already present in the HTML, which
 * works from day one: send the buyer a Stripe invoice, then mint their
 * key with license-server/bin/create-key.php (or let the Stripe webhook
 * do both automatically — see docs/launch/GO-LIVE.md).
 *
 * IMPORTANT: give every Stripe Payment Link metadata `item_slug` and
 * `tier` so the license-server webhook can mint the right key.
 */
window.BUY_LINKS = {
  'orderring-pro': '',
  'orderring-agency': '',
  'storecanvas-pro-year': '',
  'storecanvas-pro-lifetime': '',
  'storecanvas-agency-lifetime': '',
  'orderbay-pro': '',
  'orderbay-agency': '',
  'cardingdesk-pro': '',
  'cardingdesk-agency': '',
  'checkout-sentinel-1': '',
  'checkout-sentinel-3': '',
  'checkout-sentinel-10': '',
  'formdrop-pro': '',
  'formdrop-agency': '',
  'formdrop-agency-25': '',
  'bcfe-pro': '',
  'bcfe-agency': '',
  'layoutguard-10': '',
  'layoutguard-unlimited': ''
};

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-buy]').forEach(function (el) {
    var url = window.BUY_LINKS[el.getAttribute('data-buy')];
    if (url) {
      el.href = url;
      el.removeAttribute('title');
    }
  });
});
