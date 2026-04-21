/**
 * @file
 * Minimal behavior bootstrap for the Emerging Digital theme.
 */

(function () {
  'use strict';

  var COOKIE_POLICY_URL = '/cookies';
  var COOKIE_POLICY_TEXT = 'Politique de cookies';

  function createCookiePolicyLink(className) {
    var link = document.createElement('a');
    link.href = COOKIE_POLICY_URL;
    link.className = className;
    link.textContent = COOKIE_POLICY_TEXT;
    link.setAttribute('aria-label', 'Consulter la politique de cookies');
    return link;
  }

  function ensureBannerCookiePolicyLink() {
    var bannerSelectors = [
      '[data-cookie-banner]',
      '.cookie-banner',
      '.cookies-banner',
      '.cc-window',
      '[id*="cookie"][id*="banner"]',
      '[class*="cookie"][class*="banner"]'
    ];
    var banner = document.querySelector(bannerSelectors.join(','));
    if (!banner || banner.querySelector('.js-cookie-policy-link--banner')) {
      return;
    }

    var textContainerSelectors = [
      '[data-cookie-banner-text]',
      '.cookie-banner__text',
      '.cookies-banner__text',
      '.cc-message',
      'p'
    ];
    var textContainer = banner.querySelector(textContainerSelectors.join(',')) || banner;
    var link = createCookiePolicyLink('cookie-policy-link cookie-policy-link--banner js-cookie-policy-link--banner');
    var separator = document.createTextNode(' ');

    textContainer.appendChild(separator);
    textContainer.appendChild(link);
  }

  function ensureModalCookiePolicyLink() {
    var modalSelectors = [
      '[data-cookie-preferences-modal]',
      '.cookie-preferences-modal',
      '.cookies-modal',
      '.cc-preferences',
      '[id*="cookie"][id*="modal"]',
      '[class*="cookie"][class*="modal"]'
    ];
    var modal = document.querySelector(modalSelectors.join(','));
    if (!modal || modal.querySelector('.js-cookie-policy-link--modal')) {
      return;
    }

    var footerSelectors = [
      '[data-cookie-modal-footer]',
      '.cookie-modal__footer',
      '.cookies-modal__footer',
      '.cc-preferences__footer',
      '.cc-compliance',
      '.modal-footer'
    ];
    var footer = modal.querySelector(footerSelectors.join(',')) || modal;
    var wrapper = document.createElement('p');
    wrapper.className = 'cookie-policy-link-wrapper';
    wrapper.appendChild(createCookiePolicyLink('cookie-policy-link cookie-policy-link--modal js-cookie-policy-link--modal'));
    footer.appendChild(wrapper);
  }

  function ensureCookiePolicyLinks() {
    ensureBannerCookiePolicyLink();
    ensureModalCookiePolicyLink();
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('a[href="#main-content"]');
    if (!trigger) {
      return;
    }

    var target = document.getElementById('main-content');
    if (!target) {
      return;
    }

    target.focus();
  });

  ensureCookiePolicyLinks();

  var observer = new MutationObserver(function () {
    ensureCookiePolicyLinks();
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
})();
