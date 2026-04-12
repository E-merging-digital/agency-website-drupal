/**
 * @file
 * Minimal behavior bootstrap for the Emerging Digital theme.
 */

(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('a[href="#main-content"]');
    if (!trigger) {
      return;
    }

    var target = document.getElementById('main-content');
    if (!target) {
      return;
    }

    target.focus({ preventScroll: false });
  });
})();
