// assets/js/sound-manager.js
// Phase 4 — Sound System. Loads once, plays by name, never lets two
// instances of the same sound overlap, and never leaks Audio elements.
//
// Note on file format: the task named assets/sounds/*.mp3, but no MP3
// encoder (ffmpeg, or any Python+library) is available in this
// environment. Rather than mislabel WAV data with a .mp3 extension
// (which would send a mismatched Content-Type and be fragile), this
// ships real, valid WAV files instead — assets/sounds/*.wav, generated
// from actual PCM tone data (see the generation note in
// docs/maui-integration.md). Every browser this app targets plays WAV
// natively; swapping in branded MP3s later only means updating
// SOUND_FILES below, nothing else.

(function () {
  'use strict';

  const SOUND_FILES = {
    booking: 'booking.wav',
    message: 'message.wav',
    wallet: 'wallet.wav',
    rating: 'rating.wav',
    warning: 'warning.wav',
    success: 'success.wav',
  };

  // Resolved relative to whichever page loads this file (driver/, rider/,
  // admin/ are all one level deep) — same '../assets/...' convention
  // every other shared asset in this app already uses.
  function soundsBaseUrl() {
    const path = window.location.pathname;
    const nested = path.includes('/driver/') || path.includes('/rider/') || path.includes('/admin/');
    return (nested ? '../' : '') + 'assets/sounds/';
  }

  const cache = {};        // type -> HTMLAudioElement (one per type, reused — no leak)
  let muted = false;
  let volume = 0.6;

  function getAudio(type) {
    if (!SOUND_FILES[type]) return null;
    if (!cache[type]) {
      const audio = new Audio(soundsBaseUrl() + SOUND_FILES[type]);
      audio.preload = 'auto';
      cache[type] = audio;
    }
    return cache[type];
  }

  const sound = {
    /** Plays a named sound. Restarts from 0 if already playing (prevents overlapping duplicates of the SAME sound) rather than stacking a second instance. */
    play(type) {
      if (muted) return;
      const audio = getAudio(type);
      if (!audio) { console.warn(`[sound-manager] Unknown sound type: ${type}`); return; }
      audio.volume = volume;
      try {
        audio.currentTime = 0;
        const playPromise = audio.play();
        if (playPromise && typeof playPromise.catch === 'function') {
          // Autoplay can be blocked before the user's first interaction
          // with the page — this is expected and not an error to surface.
          playPromise.catch(() => {});
        }
      } catch (error) { /* no-op — same reasoning as above */ }
    },
    /** Stops every currently-playing sound (not just one type). */
    stop(type) {
      if (type) {
        cache[type]?.pause();
        if (cache[type]) cache[type].currentTime = 0;
        return;
      }
      Object.values(cache).forEach((audio) => { audio.pause(); audio.currentTime = 0; });
    },
    mute() { muted = true; },
    unmute() { muted = false; },
    isMuted() { return muted; },
    setVolume(v) { volume = Math.max(0, Math.min(1, v)); Object.values(cache).forEach((audio) => { audio.volume = volume; }); },
    getVolume() { return volume; },
  };

  window.MohToh = window.MohToh || {};
  window.MohToh.sound = sound;
})();
