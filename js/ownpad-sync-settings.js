(() => {
  const container = document.getElementById('ownpad-sync-settings');
  if (!container) {
    return;
  }

  const enabledEl = document.getElementById('ownpad-sync-enabled');
  const indexEl = document.getElementById('ownpad-sync-index-content');
  const intervalEl = document.getElementById('ownpad-sync-interval');
  const saveEl = document.getElementById('ownpad-sync-save');
  const statusEl = document.getElementById('ownpad-sync-status');

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

  const setStatus = (text) => {
    statusEl.textContent = text;
    if (text) {
      setTimeout(() => {
        statusEl.textContent = '';
      }, 3000);
    }
  };

  const settingsToken = getRequestToken();
  if (!settingsToken) {
    setStatus('Missing request token');
    return;
  }

  const settingsUrl = OC.generateUrl('/apps/ownpad/ajax/v1.0/getsyncsettings') + '?requesttoken=' + encodeURIComponent(settingsToken);
  fetch(settingsUrl, {
    credentials: 'same-origin',
    headers: {
      'requesttoken': settingsToken,
      'X-Requested-With': 'XMLHttpRequest',
    },
  }).then((response) => response.json())
    .then((payload) => {
      const data = payload?.data;
      if (!data) {
        return;
      }
      enabledEl.checked = !!data.enabled;
      indexEl.checked = !!data.indexContent;
      intervalEl.value = data.intervalSeconds ?? 120;
    }).catch(() => {
      setStatus('Failed to load settings');
    });

  saveEl.addEventListener('click', () => {
    const interval = Math.max(30, parseInt(intervalEl.value || '120', 10));
    const requestToken = getRequestToken();
    if (!requestToken) {
      setStatus('Missing request token');
      return;
    }
    const body = new URLSearchParams();
    body.set('intervalSeconds', interval.toString());
    body.set('enabled', enabledEl.checked ? '1' : '0');
    body.set('indexContent', indexEl.checked ? '1' : '0');
    body.set('requesttoken', requestToken);

    fetch(OC.generateUrl('/apps/ownpad/ajax/v1.0/setsyncsettings'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'requesttoken': requestToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    }).then((response) => {
      if (!response.ok) {
        throw new Error('Save failed');
      }
      setStatus('Saved');
    }).catch(() => {
      setStatus('Save failed');
    });
  });
})();
