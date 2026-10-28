/** Agency #1470 — purposeful page motion with a complete static fallback. */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.agencyHomeV2 = {
    attach() {
      const root = document.querySelector('[data-agency-v2]');
      if (!root || root.dataset.agencyV2Ready === 'true') return;
      root.dataset.agencyV2Ready = 'true';

      // Reduced-motion and unsupported browsers keep every element visible.
      if (!('IntersectionObserver' in window) ||
          (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        return;
      }

      const hero = document.querySelector('.page-main--front .ed-section--hero-home');
      const selectors = [
        '.agency-v2-trust .agency-v2-container > span',
        '.agency-v2-section .agency-v2-container > .agency-v2-eyebrow',
        '.agency-v2-section .agency-v2-container > h2',
        '.agency-v2-section .agency-v2-container > .agency-v2-lead',
        '.agency-v2-demo > div:first-child > .agency-v2-eyebrow',
        '.agency-v2-demo > div:first-child > h2',
        '.agency-v2-demo > div:first-child > .agency-v2-lead',
        '.agency-v2-path',
        '.agency-v2-step',
        '.agency-v2-demo__flow',
        '.agency-v2-tech-list li',
        '.agency-v2-final .agency-v2-container > *',
      ];

      const targets = Array.from(root.querySelectorAll(selectors.join(',')));
      const counters = new Map();
      targets.forEach(target => {
        target.setAttribute('data-agency-reveal', '');
        const group = target.closest(
          '.agency-v2-paths, .agency-v2-steps, .agency-v2-tech-list, .agency-v2-trust, .agency-v2-final, .agency-v2-demo'
        ) || target.closest('.agency-v2-section') || root;
        const index = counters.get(group) || 0;
        counters.set(group, index + 1);
        target.style.setProperty('--agency-v2-delay', Math.min(index, 4) * 110 + 'ms');
      });

      const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          entry.target.classList.add('agency-v2-is-visible');
          observer.unobserve(entry.target);
        }
      }, { threshold: 0.08, rootMargin: '0px 0px -8% 0px' });

      targets.forEach(target => observer.observe(target));
      root.classList.add('agency-v2-motion-ready');
      if (hero) {
        window.requestAnimationFrame(() => hero.classList.add('agency-v2-hero-active'));
      }

      const media = hero && hero.querySelector('.agency-v2-hero-media');
      if (media && window.matchMedia('(max-width: 47.99rem)').matches) {
        let mediaVisible = false;
        const storyObserver = new IntersectionObserver(entries => {
          mediaVisible = entries.some(entry => entry.isIntersecting);
          tryStartStory();
        }, { threshold: 0.35 });

        function tryStartStory() {
          if (!mediaVisible || document.hidden || document.getElementById('ed-cookie-banner')) return;
          media.classList.add('agency-v2-story-started');
          storyObserver.disconnect();
          window.removeEventListener('ed-cookie-consent-updated', consentChanged);
        }

        function consentChanged() {
          window.requestAnimationFrame(tryStartStory);
        }

        window.addEventListener('ed-cookie-consent-updated', consentChanged);
        storyObserver.observe(media);
      }
    }
  };
})(Drupal);
