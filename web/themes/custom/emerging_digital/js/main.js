/**
 * @file
 * Minimal behavior bootstrap for the Emerging Digital theme.
 */

(function () {
  'use strict';

  var COOKIE_POLICY_URL = '/cookies';
  var COOKIE_POLICY_TEXT = 'Politique de cookies';
  var COOKIE_POLICY_MODAL_TEXT = 'Consulter la Politique de cookies';

  function createCookiePolicyLink(className, text) {
    var link = document.createElement('a');
    link.href = COOKIE_POLICY_URL;
    link.className = className;
    link.textContent = text || COOKIE_POLICY_TEXT;
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
    var link = createCookiePolicyLink('cookie-policy-link cookie-policy-link--banner js-cookie-policy-link--banner', COOKIE_POLICY_TEXT);
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
    var actionsSelectors = [
      '[data-cookie-modal-actions]',
      '.cookie-modal__actions',
      '.cookies-modal__actions',
      '.cc-compliance',
      '.modal-actions',
      '.modal-footer',
      '.buttons'
    ];
    var actionsContainer = footer.querySelector(actionsSelectors.join(','));
    if (!actionsContainer) {
      actionsContainer = modal.querySelector('button, .button') ? modal.querySelector('button, .button').parentElement : null;
    }

    var wrapper = document.createElement('p');
    wrapper.className = 'cookie-policy-link-wrapper cookie-policy-link-wrapper--modal';
    wrapper.appendChild(createCookiePolicyLink('cookie-policy-link cookie-policy-link--modal js-cookie-policy-link--modal', COOKIE_POLICY_MODAL_TEXT));

    if (actionsContainer && actionsContainer.parentElement) {
      actionsContainer.parentElement.insertBefore(wrapper, actionsContainer);
      return;
    }

    footer.appendChild(wrapper);
  }

  function ensureCookiePolicyLinks() {
    ensureBannerCookiePolicyLink();
    ensureModalCookiePolicyLink();
  }

  function closeLanguageSwitcher(switcher) {
    var toggle = switcher.querySelector('.language-switcher__toggle');
    var menu = switcher.querySelector('.language-switcher__menu');
    if (!toggle || !menu) {
      return;
    }

    toggle.setAttribute('aria-expanded', 'false');
    menu.hidden = true;
  }

  function openLanguageSwitcher(switcher) {
    var toggle = switcher.querySelector('.language-switcher__toggle');
    var menu = switcher.querySelector('.language-switcher__menu');
    if (!toggle || !menu) {
      return;
    }

    toggle.setAttribute('aria-expanded', 'true');
    menu.hidden = false;
  }

  function closeAllLanguageSwitchers() {
    var switchers = document.querySelectorAll('[data-language-switcher]');
    switchers.forEach(closeLanguageSwitcher);
  }

  function initLanguageSwitcher(switcher) {
    if (switcher.dataset.languageSwitcherReady === 'true') {
      return;
    }

    var toggle = switcher.querySelector('.language-switcher__toggle');
    var menu = switcher.querySelector('.language-switcher__menu');

    if (!toggle || !menu) {
      return;
    }

    switcher.dataset.languageSwitcherReady = 'true';

    toggle.addEventListener('click', function () {
      var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
      closeAllLanguageSwitchers();
      if (!isExpanded) {
        openLanguageSwitcher(switcher);
      }
    });

    switcher.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') {
        return;
      }

      closeLanguageSwitcher(switcher);
      toggle.focus();
    });
  }

  function initLanguageSwitchers() {
    var switchers = document.querySelectorAll('[data-language-switcher]');
    switchers.forEach(initLanguageSwitcher);
  }

  document.addEventListener('click', function (event) {
    var clickedInSwitcher = event.target.closest('[data-language-switcher]');
    if (!clickedInSwitcher) {
      closeAllLanguageSwitchers();
    }

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
  initLanguageSwitchers();

  var observer = new MutationObserver(function () {
    ensureCookiePolicyLinks();
    initLanguageSwitchers();
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
})();
