/**
 * @file
 * Keeps legacy theme-generated cookie policy links aligned with live aliases.
 */

(function () {
  'use strict';

  function getPolicyPath() {
    var langcode = (document.documentElement.lang || 'fr').substring(0, 2);
    return langcode === 'en'
      ? '/en/cookie-policy'
      : '/fr/politique-de-cookies';
  }

  function normalizeCookiePolicyLinks() {
    var policyPath = getPolicyPath();
    document.querySelectorAll('a[href="/cookies"]').forEach(function (link) {
      link.setAttribute('href', policyPath);
    });
  }

  normalizeCookiePolicyLinks();

  var observer = new MutationObserver(normalizeCookiePolicyLinks);
  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
})();
