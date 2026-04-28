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

  function getFocusableElements(container) {
    if (!container) {
      return [];
    }

    return Array.prototype.slice.call(container.querySelectorAll(
      'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (element) {
      return element.offsetParent !== null;
    });
  }

  function initMobileNavigation(header) {
    if (!header || header.dataset.mobileNavReady === 'true') {
      return;
    }

    var toggle = header.querySelector('[data-mobile-nav-toggle]');
    var drawer = header.querySelector('[data-mobile-nav-drawer]');
    var content = header.querySelector('[data-mobile-nav-content]');
    var closeButton = header.querySelector('[data-mobile-nav-close]');
    var main = header.querySelector('.page-header__main');
    var menuList = main ? main.querySelector('.main-navigation__list') : null;
    var menuBlock = main ? main.querySelector('.block-system-menu-blockmain') : null;
    var aside = header.querySelector('.page-header__inner > .page-header__aside');

    if (!menuBlock && menuList) {
      menuBlock = menuList.closest('.block-system-menu-blockmain') ||
        menuList.closest('.block') ||
        menuList.closest('nav') ||
        menuList;
    }

    if (!toggle || !drawer || !content || !closeButton) {
      return;
    }

    var mediaQuery = window.matchMedia('(max-width: 48rem)');
    var menuPlaceholder = document.createComment('mobile-nav-menu-placeholder');
    var asidePlaceholder = document.createComment('mobile-nav-aside-placeholder');
    var isOpen = false;
    var isMoved = false;
    var previousFocus = null;

    if (menuBlock && menuBlock.parentNode) {
      menuBlock.parentNode.insertBefore(menuPlaceholder, menuBlock);
    }
    if (aside && aside.parentNode) {
      aside.parentNode.insertBefore(asidePlaceholder, aside);
    }

    function closeMenu(options) {
      if (!isOpen) {
        header.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Ouvrir le menu principal');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.removeProperty('overflow');
        return;
      }

      isOpen = false;
      header.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Ouvrir le menu principal');
      drawer.setAttribute('aria-hidden', 'true');
      document.body.style.removeProperty('overflow');

      if (options && options.restoreFocus && previousFocus) {
        previousFocus.focus();
      }
    }

    function openMenu() {
      if (isOpen || !mediaQuery.matches) {
        return;
      }

      previousFocus = document.activeElement;
      isOpen = true;
      header.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'Fermer le menu principal');
      drawer.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';

      var focusables = getFocusableElements(content);
      if (focusables.length) {
        focusables[0].focus();
      }
    }

    function moveDrawerNodes(enableMobile) {
      if (enableMobile && !isMoved) {
        if (menuBlock) {
          content.appendChild(menuBlock);
        }
        if (aside) {
          content.appendChild(aside);
        }
        isMoved = true;
        return;
      }

      if (!enableMobile && isMoved) {
        if (menuBlock && menuPlaceholder.parentNode) {
          menuPlaceholder.parentNode.insertBefore(menuBlock, menuPlaceholder.nextSibling);
        }
        if (aside && asidePlaceholder.parentNode) {
          asidePlaceholder.parentNode.insertBefore(aside, asidePlaceholder.nextSibling);
        }
        closeMenu();
        isMoved = false;
      }
    }

    function onViewportChange(event) {
      moveDrawerNodes(event.matches);
    }

    toggle.addEventListener('click', function () {
      if (isOpen) {
        closeMenu({ restoreFocus: true });
        return;
      }
      openMenu();
    });

    closeButton.addEventListener('click', function () {
      closeMenu({ restoreFocus: true });
    });

    content.addEventListener('click', function (event) {
      if (event.target.closest('a')) {
        closeMenu();
      }
    });

    header.addEventListener('keydown', function (event) {
      if (!isOpen) {
        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu({ restoreFocus: true });
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      var focusables = getFocusableElements(content);
      if (!focusables.length) {
        event.preventDefault();
        toggle.focus();
        return;
      }

      var firstElement = focusables[0];
      var lastElement = focusables[focusables.length - 1];

      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault();
        lastElement.focus();
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault();
        firstElement.focus();
      }
    });

    if (typeof mediaQuery.addEventListener === 'function') {
      mediaQuery.addEventListener('change', onViewportChange);
    } else {
      mediaQuery.addListener(onViewportChange);
    }

    moveDrawerNodes(mediaQuery.matches);
    closeMenu();
    header.dataset.mobileNavReady = 'true';
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
  initMobileNavigation(document.querySelector('.page-header'));

  var observer = new MutationObserver(function () {
    ensureCookiePolicyLinks();
    initLanguageSwitchers();
    initMobileNavigation(document.querySelector('.page-header'));
  });
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
})();
