(() => {
  const root = document.documentElement;
  const file = root?.dataset?.ownpadFile;
  const syncUrl = root?.dataset?.ownpadSyncUrl;
  const intervalSeconds = Math.max(30, parseInt(root?.dataset?.ownpadSyncInterval || '120', 10));
  if (!file || !syncUrl) {
    return;
  }

  const getRequestToken = () => {
    if (window.OC) {
      if (typeof OC.getRequestToken === 'function') {
        return OC.getRequestToken();
      }
      if (OC.requestToken) {
        return OC.requestToken;
      }
    }
    if (window.oc_requesttoken) {
      return window.oc_requesttoken;
    }
    return document.querySelector('meta[name="requesttoken"]')?.getAttribute('content') || '';
  };

  const requestToken = getRequestToken();
  if (!requestToken) {
    return;
  }

  const buildFormBody = () => {
    const body = new URLSearchParams();
    body.set('file', file);
    body.set('requesttoken', requestToken);
    return body.toString();
  };

  const syncNow = () => {
    fetch(syncUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'requesttoken': requestToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: buildFormBody(),
    }).catch(() => {
      // Ignore network errors; next interval will retry.
    });
  };

  const syncOnUnload = () => {
    if (!navigator.sendBeacon) {
      syncNow();
      return;
    }
    const data = new FormData();
    data.append('file', file);
    data.append('requesttoken', requestToken);
    navigator.sendBeacon(syncUrl, data);
  };

  syncNow();
  const timer = setInterval(syncNow, intervalSeconds * 1000);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      syncOnUnload();
    }
  });

  window.addEventListener('pagehide', () => {
    clearInterval(timer);
    syncOnUnload();
  });
})();
