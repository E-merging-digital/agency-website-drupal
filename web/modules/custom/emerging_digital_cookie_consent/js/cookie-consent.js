(function (Drupal, once, drupalSettings) {
  'use strict';

  const STORAGE_KEY = 'ed_cookie_consent_v1';
  const CONSENT_VALIDITY_MONTHS = 6;
  let lastFocusedElement = null;

  // Preserve existing FR/EN messages. Use Dutch only on Dutch pages.
  const isDutch = document.documentElement.lang.toLowerCase().startsWith('nl');
  const labels = isDutch ? {
    policy: 'Cookiebeleid',
    region: 'Cookievoorkeuren',
    message: 'Wij gebruiken noodzakelijke technische cookies en, met uw toestemming, externe inhoud (bijvoorbeeld Google Maps).',
    reject: 'Weigeren',
    accept: 'Accepteren',
    preferences: 'Cookievoorkeuren',
    close: 'Venster sluiten',
    modalDescription: 'Noodzakelijke cookies blijven altijd actief.',
    external: 'Externe inhoud toestaan (Google Maps, diensten van derden)',
    cancel: 'Annuleren',
    save: 'Opslaan',
    mapsBlocked: 'Google Maps is uitgeschakeld totdat u externe inhoud toestaat.',
    allowExternal: 'Externe inhoud toestaan',
  } : {
    policy: 'Politique de cookies',
    region: 'Préférences cookies',
    message: 'Nous utilisons des cookies techniques nécessaires et, avec votre accord, des contenus externes (ex: Google Maps).',
    reject: 'Refuser',
    accept: 'Accepter',
    preferences: 'Préférences',
    close: 'Fermer la fenêtre',
    modalDescription: 'Les cookies nécessaires sont toujours actifs.',
    external: 'Activer les contenus externes (Google Maps, services tiers)',
    cancel: 'Annuler',
    save: 'Enregistrer',
    mapsBlocked: "Google Maps est désactivé tant que vous n'acceptez pas les contenus externes.",
    allowExternal: 'Autoriser les contenus externes',
  };

  function getFocusableElements(container) {
    return Array.from(
      container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
    );
  }

  function readConsent() {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return null;
      }

      const parsed = JSON.parse(raw);
      if (typeof parsed !== 'object' || parsed === null ||
        parsed.necessary !== true || typeof parsed.external !== 'boolean' ||
        typeof parsed.updatedAt !== 'string') {
        return null;
      }

      const updatedAt = new Date(parsed.updatedAt);
      if (Number.isNaN(updatedAt.getTime()) ||
        updatedAt.toISOString() !== parsed.updatedAt ||
        updatedAt.getTime() > Date.now()) {
        return null;
      }

      // Calendar months, clamped at month-end (e.g. 31 August -> 28/29 February).
      const expiresAt = new Date(updatedAt.getTime());
      expiresAt.setUTCDate(1);
      expiresAt.setUTCMonth(expiresAt.getUTCMonth() + CONSENT_VALIDITY_MONTHS);
      const lastDay = new Date(Date.UTC(expiresAt.getUTCFullYear(), expiresAt.getUTCMonth() + 1, 0)).getUTCDate();
      expiresAt.setUTCDate(Math.min(updatedAt.getUTCDate(), lastDay));
      if (Date.now() >= expiresAt.getTime()) {
        return null;
      }

      return {
        necessary: true,
        external: parsed.external,
      };
    }
    catch (error) {
      return null;
    }
  }

  function writeConsent(externalAccepted) {
    const payload = {
      necessary: true,
      external: Boolean(externalAccepted),
      updatedAt: new Date().toISOString(),
    };

    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    window.dispatchEvent(new CustomEvent('ed-cookie-consent-updated', { detail: payload }));
    showPreferencesShortcut();
  }

  function getPolicyLink() {
    const path = drupalSettings.emergingDigitalCookieConsent?.policyPath;
    if (typeof path === 'string' && path.trim().length > 0) {
      return path.trim();
    }
    return '';
  }

  // Keep consent choices accessible even after dismissing the banner.
  function showPreferencesShortcut() {
    if (document.getElementById('ed-cookie-preferences-shortcut')) {
      return;
    }
    const button = document.createElement('button');
    button.id = 'ed-cookie-preferences-shortcut';
    button.type = 'button';
    button.className = 'ed-cookie-banner__btn ed-cookie-preferences-shortcut';
    button.textContent = labels.preferences;
    button.addEventListener('click', function () {
      openPreferencesModal(button);
    });
    document.body.appendChild(button);
  }

  function createBanner() {
    if (document.getElementById('ed-cookie-banner')) {
      return;
    }

    const policyLink = getPolicyLink();
    const policyMarkup = policyLink
      ? `<p class="ed-cookie-banner__meta"><a href="${policyLink}" class="ed-cookie-banner__link">${labels.policy}</a></p>`
      : '';

    const banner = document.createElement('div');
    banner.id = 'ed-cookie-banner';
    banner.className = 'ed-cookie-banner';
    banner.innerHTML = `
      <div class="ed-cookie-banner__content" role="region" aria-live="polite" aria-label="${labels.region}">
        <div class="ed-cookie-banner__message">
          <p class="ed-cookie-banner__text">
            ${labels.message}
          </p>
          ${policyMarkup}
        </div>
        <div class="ed-cookie-banner__actions">
          <div class="ed-cookie-banner__row ed-cookie-banner__row--primary">
            <button type="button" class="ed-cookie-banner__btn ed-cookie-banner__btn--secondary" data-ed-cookie-action="reject">${labels.reject}</button>
            <button type="button" class="ed-cookie-banner__btn ed-cookie-banner__btn--primary" data-ed-cookie-action="accept">${labels.accept}</button>
          </div>
          <div class="ed-cookie-banner__row ed-cookie-banner__row--secondary">
            <button type="button" class="ed-cookie-banner__btn ed-cookie-banner__btn--ghost" data-ed-cookie-action="preferences">${labels.preferences}</button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(banner);

    banner.addEventListener('click', function (event) {
      const action = event.target?.dataset?.edCookieAction;
      if (!action) {
        return;
      }

      if (action === 'accept') {
        writeConsent(true);
        banner.remove();
        return;
      }

      if (action === 'reject') {
        writeConsent(false);
        banner.remove();
        return;
      }

      if (action === 'preferences') {
        openPreferencesModal(event.target);
      }
    });
  }

  function closePreferencesModal(modal) {
    modal.classList.remove('is-open');
    modal.removeEventListener('keydown', trapFocusInModal);

    if (lastFocusedElement?.isConnected && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
      return;
    }

    const preferencesButton = document.getElementById('ed-cookie-preferences-shortcut') ||
      document.querySelector('[data-ed-cookie-action="preferences"]');
    if (preferencesButton) {
      preferencesButton.focus();
    }
  }

  function trapFocusInModal(event) {
    const modal = event.currentTarget;
    if (!modal || !modal.classList.contains('is-open')) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      closePreferencesModal(modal);
      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    const focusable = getFocusableElements(modal);
    if (!focusable.length) {
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
      return;
    }

    if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function openPreferencesModal(triggerElement) {
    if (triggerElement && typeof triggerElement.focus === 'function') {
      lastFocusedElement = triggerElement;
    }

    let modal = document.getElementById('ed-cookie-preferences-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'ed-cookie-preferences-modal';
      modal.className = 'ed-cookie-modal';
      modal.innerHTML = `
        <div class="ed-cookie-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ed-cookie-modal-title" aria-describedby="ed-cookie-modal-description">
          <button type="button" class="ed-cookie-modal__close" data-ed-cookie-modal-action="cancel" aria-label="${labels.close}">×</button>
          <h2 id="ed-cookie-modal-title" class="ed-cookie-modal__title">${labels.region}</h2>
          <p id="ed-cookie-modal-description" class="ed-cookie-modal__description">${labels.modalDescription}</p>
          <label class="ed-cookie-modal__option">
            <input type="checkbox" data-ed-cookie-toggle="external" />
            ${labels.external}
          </label>
          <div class="ed-cookie-modal__actions">
            <button type="button" class="ed-cookie-banner__btn ed-cookie-banner__btn--secondary" data-ed-cookie-modal-action="cancel">${labels.cancel}</button>
            <button type="button" class="ed-cookie-banner__btn ed-cookie-banner__btn--primary" data-ed-cookie-modal-action="save">${labels.save}</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);

      modal.addEventListener('click', function (event) {
        const action = event.target?.dataset?.edCookieModalAction;
        if (!action) {
          return;
        }

        if (action === 'cancel') {
          closePreferencesModal(modal);
        }

        if (action === 'save') {
          const toggle = modal.querySelector('[data-ed-cookie-toggle="external"]');
          const accepted = Boolean(toggle?.checked);
          writeConsent(accepted);
          closePreferencesModal(modal);

          const banner = document.getElementById('ed-cookie-banner');
          if (banner) {
            banner.remove();
          }
        }
      });

      modal.addEventListener('keydown', trapFocusInModal);
    }

    const consent = readConsent();
    const toggle = modal.querySelector('[data-ed-cookie-toggle="external"]');
    if (toggle) {
      toggle.checked = Boolean(consent?.external);
    }

    modal.classList.add('is-open');

    const focusable = getFocusableElements(modal);
    if (focusable.length) {
      focusable[0].focus();
    }
  }

  function isGoogleMapsIframe(iframe) {
    const src = (iframe.getAttribute('src') || '').toLowerCase();
    return src.includes('google.com/maps') || src.includes('google.fr/maps');
  }

  function blockExternalIframe(iframe) {
    if (!iframe.getAttribute('src')) {
      return;
    }

    if (!isGoogleMapsIframe(iframe)) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'ed-cookie-placeholder';
    iframe.parentNode.insertBefore(wrapper, iframe);
    wrapper.appendChild(iframe);

    iframe.dataset.edCookieBlockedSrc = iframe.getAttribute('src');
    iframe.removeAttribute('src');
    iframe.setAttribute('loading', 'lazy');

    const placeholder = document.createElement('div');
    placeholder.className = 'ed-cookie-placeholder__message';
    placeholder.innerHTML = `
      <p>${labels.mapsBlocked}</p>
      <button type="button" class="ed-cookie-banner__btn ed-cookie-banner__btn--primary" data-ed-cookie-action="accept-external">${labels.allowExternal}</button>
    `;

    wrapper.appendChild(placeholder);

    placeholder.addEventListener('click', function (event) {
      const action = event.target?.dataset?.edCookieAction;
      if (action === 'accept-external') {
        writeConsent(true);
        const banner = document.getElementById('ed-cookie-banner');
        if (banner) {
          banner.remove();
        }
      }
    });
  }

  function applyConsentToEmbeds() {
    const consent = readConsent();
    const externalAccepted = Boolean(consent?.external);

    document.querySelectorAll('iframe[data-ed-cookie-blocked-src]').forEach(function (iframe) {
      const wrapper = iframe.closest('.ed-cookie-placeholder');
      const message = wrapper ? wrapper.querySelector('.ed-cookie-placeholder__message') : null;
      if (externalAccepted) {
        if (!iframe.getAttribute('src')) {
          iframe.setAttribute('src', iframe.dataset.edCookieBlockedSrc);
        }
        if (message) {
          message.remove();
        }
      }
      else if (message) {
        message.style.display = '';
      }
    });
  }

  Drupal.behaviors.emergingDigitalCookieConsent = {
    attach(context) {
      once('ed-cookie-consent-init', 'body', context).forEach(function () {
        const consent = readConsent();
        if (!consent) {
          createBanner();
        }
        else {
          showPreferencesShortcut();
        }

        document.querySelectorAll('iframe').forEach(function (iframe) {
          if (iframe.dataset.edCookieBlockedSrc) {
            return;
          }
          blockExternalIframe(iframe);
        });

        applyConsentToEmbeds();

        window.addEventListener('ed-cookie-consent-updated', function () {
          applyConsentToEmbeds();
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
