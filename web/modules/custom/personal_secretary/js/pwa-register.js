(() => {
  'use strict';

  if (!('serviceWorker' in navigator)) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/personal-secretary-service-worker.js', { scope: '/' })
      .catch(() => {});
  });
})();
