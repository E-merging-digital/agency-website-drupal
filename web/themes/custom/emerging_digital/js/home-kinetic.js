/**
 * Agency #1418 — isolated opt-in homepage animation, candidate only.
 * Uses actual Drupal SDC hero and its content/links. No autoplay or network.
 */
(function (Drupal) {
  'use strict';

  const DURATION = 12000;
  const labels = {
    fr: { play: 'Voir la présentation', pause: 'Pause', resume: 'Reprendre',
      replay: 'Revoir', stop: 'Arrêter', progress: 'Progression de la présentation',
      reduced: 'Animation réduite selon les préférences de votre appareil.',
      final: 'Présentation terminée.', words: ['CRÉER', 'AMÉLIORER', 'MODERNISER'] },
    en: { play: 'Watch the presentation', pause: 'Pause', resume: 'Resume',
      replay: 'Replay', stop: 'Stop', progress: 'Presentation progress',
      reduced: 'Motion is reduced according to your device preferences.',
      final: 'Presentation finished.', words: ['CREATE', 'IMPROVE', 'MODERNIZE'] }
  };

  function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text) element.textContent = text;
    return element;
  }

  function init(hero) {
    if (hero.dataset.edKineticReady === 'true') return;
    const layout = hero.querySelector('.ed-section__hero-layout');
    const content = hero.querySelector('.ed-section__hero-content');
    const title = hero.querySelector('.ed-section__title--hero');
    const media = layout && layout.querySelector('.ed-section__hero-illustration');
    // Fail closed instead of fabricating/duplicating the existing homepage H1.
    if (!layout || !content || !title || !media) return;
    hero.dataset.edKineticReady = 'true';

    const l = document.documentElement.lang.toLowerCase().startsWith('en') ? labels.en : labels.fr;
    const visual = node('div', 'ed-home-kinetic__visual');
    visual.dataset.kineticStatic = 'true';
    media.parentNode.insertBefore(visual, media);
    visual.appendChild(media);
    visual.appendChild(node('span', 'ed-home-kinetic__accent'));
    const words = l.words.map(word => {
      const element = node('span', 'ed-home-kinetic__word', word);
      element.setAttribute('aria-hidden', 'true');
      visual.appendChild(element);
      return element;
    });
    const controls = node('div', 'ed-home-kinetic__controls');
    const play = node('button', 'ed-home-kinetic__button', l.play);
    play.type = 'button';
    const replay = node('button', 'ed-home-kinetic__button', l.replay);
    replay.type = 'button';
    replay.hidden = true;
    const stopButton = node('button', 'ed-home-kinetic__button', l.stop);
    stopButton.type = 'button';
    stopButton.hidden = true;
    const status = node('p', 'ed-home-kinetic__status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    const progress = node('progress', 'ed-home-kinetic__progress');
    progress.max = DURATION;
    progress.value = 0;
    progress.setAttribute('aria-label', l.progress);
    controls.append(play, replay, stopButton, status, progress);
    content.appendChild(controls);

    let tracks = [];
    let elapsed = 0;
    let last = null;
    let running = false;
    let raf = null;
    let initialized = false;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function segment() {
      return [
        { opacity: 0, transform: 'translateY(38px)', filter: 'blur(10px)', offset: 0 },
        { opacity: 1, transform: 'translateY(0)', filter: 'blur(0)', offset: .19 },
        { opacity: 1, transform: 'translateY(0)', filter: 'blur(0)', offset: .8 },
        { opacity: 0, transform: 'translateY(-30px)', filter: 'blur(5px)', offset: 1 }
      ];
    }

    function createTracks() {
      if (initialized) return;
      initialized = true;
      const animations = [
        [title, [
          { opacity: .1, clipPath: 'inset(0 0 95% 0)', transform: 'translateY(28px)', offset: 0 },
          { opacity: .7, clipPath: 'inset(0 0 50% 0)', transform: 'translateY(12px)', offset: .45 },
          { opacity: 1, clipPath: 'inset(0 0 0 0)', transform: 'translateY(0)', offset: 1 }
        ], 0, 1600],
        [media, [
          { opacity: .35, clipPath: 'inset(0 100% 0 0)', transform: 'scale(1.06)', offset: 0 },
          { opacity: 1, clipPath: 'inset(0 0 0 0)', transform: 'scale(1)', offset: 1 }
        ], 700, 2000]
      ];
      words.forEach((el, index) => animations.push([
        el, segment(), [2700, 5500, 8100][index], [2550, 2300, 3100][index]
      ]));
      animations.forEach(([element, frames, delay, duration]) => {
        const effect = element.animate(frames, {
          delay, duration, fill: 'both', easing: 'cubic-bezier(.22,1,.36,1)'
        });
        effect.pause();
        tracks.push(effect);
      });
    }

    function releaseTracks() {
      tracks.forEach(effect => effect.cancel());
      tracks = [];
      initialized = false;
    }

    function finish() {
      running = false;
      releaseTracks();
      play.textContent = l.play;
      replay.hidden = false;
      stopButton.hidden = true;
      status.textContent = l.final;
      visual.dataset.kineticStatic = 'true';
    }

    function draw() {
      progress.value = Math.round(elapsed);
      for (const effect of tracks) effect.currentTime = elapsed;
      if (elapsed >= DURATION) finish();
    }

    function frame(timestamp) {
      if (!running) return;
      if (last === null) last = timestamp;
      // Count real visible time even when the browser skips animation frames.
      // Hidden tabs are paused separately by the visibilitychange listener.
      elapsed = Math.min(DURATION, elapsed + Math.max(0, timestamp - last));
      last = timestamp;
      draw();
      if (running) raf = window.requestAnimationFrame(frame);
    }

    function pause() {
      running = false;
      last = null;
      if (raf !== null) window.cancelAnimationFrame(raf);
      raf = null;
      play.textContent = l.resume;
    }

    function stop() {
      pause();
      releaseTracks();
      elapsed = 0;
      progress.value = 0;
      play.textContent = l.play;
      stopButton.hidden = true;
      replay.hidden = true;
      visual.dataset.kineticStatic = 'true';
      status.textContent = '';
    }

    function start(restart) {
      if (reduceMotion.matches) {
        status.textContent = l.reduced;
        visual.dataset.kineticStatic = 'true';
        return;
      }
      createTracks();
      if (restart || elapsed >= DURATION) elapsed = 0;
      visual.dataset.kineticStatic = 'false';
      running = true;
      last = null;
      play.textContent = l.pause;
      replay.hidden = true;
      stopButton.hidden = false;
      status.textContent = '';
      draw();
      raf = window.requestAnimationFrame(frame);
    }

    play.addEventListener('click', () => running ? pause() : start(false));
    replay.addEventListener('click', () => start(true));
    stopButton.addEventListener('click', stop);
    document.addEventListener('visibilitychange', () => {
      if (document.hidden && running) pause();
    });
    status.textContent = '';
  }

  Drupal.behaviors.emergingDigitalHomeKinetic = {
    attach() {
      const hero = document.querySelector('.page-main--front .ed-section--hero-home');
      if (hero) init(hero);
    }
  };
})(Drupal);
