/**
 * BackgroundTaskField — client-side controller
 *
 * Uses event delegation so it works with dynamically loaded CMS content (PJAX).
 * - Start/Stop: fetch() POST to field actions
 * - Progress:   native EventSource (SSE) to /task-stream/{taskId}
 */
(function () {
  'use strict';

  // Track active EventSource instances per field (keyed by field DOM id or name)
  const activeSources = new Map();

  // ─── Event delegation ───────────────────────────────────────────────

  document.addEventListener('click', function (e) {
    const startBtn = e.target.closest('.background-task-field__start');
    if (startBtn) {
      e.preventDefault();
      const field = startBtn.closest('.background-task-field');
      if (field) startTask(field);
      return;
    }

    const stopBtn = e.target.closest('.background-task-field__stop');
    if (stopBtn) {
      e.preventDefault();
      const field = stopBtn.closest('.background-task-field');
      if (field) stopTask(field);
    }
  });

  // ─── Recovery: reconnect to running tasks on load / PJAX ──────

  function recoverFields(root) {
    var fields = (root || document).querySelectorAll('.background-task-field[data-task-id]');
    fields.forEach(function (field) {
      var taskId = field.dataset.taskId;
      if (!taskId) return;

      // Skip if already connected
      var key = getFieldKey(field);
      if (activeSources.has(key)) return;

      setFieldState(field, 'running');
      connectSSE(field, taskId);
    });
  }

  // Initial scan on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { recoverFields(); });
  } else {
    recoverFields();
  }

  // Re-scan after Silverstripe CMS PJAX navigation
  document.addEventListener('cms:contentupdated', function (e) {
    recoverFields(e.target);
  });

  // ─── Start ──────────────────────────────────────────────────────────

  function startTask(field) {
    const url = field.dataset.urlStart;
    const securityId = field.dataset.securityId;

    setFieldState(field, 'starting');

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'SecurityID=' + encodeURIComponent(securityId),
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          field.dataset.taskId = data.taskId;
          setFieldState(field, 'running');
          connectSSE(field, data.taskId);
        } else {
          appendLog(field, data.message || 'Failed to start', 'error');
          setFieldState(field, 'idle');
        }
      })
      .catch(function (err) {
        appendLog(field, 'Network error: ' + err.message, 'error');
        setFieldState(field, 'idle');
      });
  }

  // ─── Stop ───────────────────────────────────────────────────────────

  function stopTask(field) {
    const taskId = field.dataset.taskId;
    if (!taskId) return;

    const url = field.dataset.urlStop;
    const securityId = field.dataset.securityId;

    disconnectSSE(field);

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'SecurityID=' + encodeURIComponent(securityId) +
            '&taskId=' + encodeURIComponent(taskId),
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        appendLog(field, data.message || 'Task stopped', 'warning');
        setFieldState(field, 'stopped');
      })
      .catch(function (err) {
        appendLog(field, 'Error stopping task: ' + err.message, 'error');
        setFieldState(field, 'stopped');
      });
  }

  // ─── SSE ────────────────────────────────────────────────────────────

  function connectSSE(field, taskId) {
    disconnectSSE(field); // clean up any previous connection

    var streamUrl = field.dataset.urlStream + encodeURIComponent(taskId);
    var es = new EventSource(streamUrl, { withCredentials: true });
    var fieldKey = getFieldKey(field);
    activeSources.set(fieldKey, es);

    es.addEventListener('output', function (e) {
      try {
        var payload = JSON.parse(e.data);
        appendLog(field, payload.text, payload.type || 'info');

        // Update progress if embedded in payload
        if (payload.progress !== undefined) {
          updateProgress(field, payload.progress);
        }
      } catch (_) {
        appendLog(field, e.data, 'info');
      }
    });

    es.addEventListener('meta', function (e) {
      try {
        var meta = JSON.parse(e.data);
        if (meta.progress !== undefined) {
          updateProgress(field, meta.progress);
        }
        if (meta.status) {
          setStatusText(field, meta.status);
        }
        // If the task already completed (e.g. on reconnect), finish immediately
        if (meta.completed || meta.status === 'completed' || meta.status === 'failed') {
          updateProgress(field, 100);
          disconnectSSE(field);
          setFieldState(field, 'finished');
        }
      } catch (_) {
        // ignore
      }
    });

    es.addEventListener('finished', function (e) {
      try {
        var meta = JSON.parse(e.data);
        var status = meta.status || 'completed';
        appendLog(field, 'Task ' + status, status === 'completed' ? 'success' : 'error');
        updateProgress(field, 100);
      } catch (_) {
        appendLog(field, 'Task finished', 'success');
        updateProgress(field, 100);
      }
      disconnectSSE(field);
      setFieldState(field, 'finished');
    });

    es.addEventListener('error', function () {
      // When the server closes the connection (PHP exit), EventSource
      // fires error with readyState=CONNECTING and tries to reconnect.
      // Don't let it — close immediately and transition to finished.
      // The background task runs independently regardless.
      disconnectSSE(field);
      var statusEl = field.querySelector('.background-task-field__status');
      if (statusEl && statusEl.dataset.status !== 'finished') {
        setFieldState(field, 'finished');
      }
    });
  }

  function disconnectSSE(field) {
    var key = getFieldKey(field);
    var es = activeSources.get(key);
    if (es) {
      es.close();
      activeSources.delete(key);
    }
  }

  function getFieldKey(field) {
    // Use the task-name data attribute as unique key within a page
    return field.dataset.taskName || field.id || Math.random().toString(36);
  }

  // ─── UI helpers ─────────────────────────────────────────────────────

  function setFieldState(field, state) {
    var startBtn = field.querySelector('.background-task-field__start');
    var stopBtn = field.querySelector('.background-task-field__stop');
    var progressEl = field.querySelector('.background-task-field__progress');
    var logEl = field.querySelector('.background-task-field__log');
    var statusEl = field.querySelector('.background-task-field__status');

    if (statusEl) statusEl.dataset.status = state;

    switch (state) {
      case 'idle':
        startBtn.hidden = false;
        startBtn.disabled = false;
        stopBtn.hidden = true;
        break;

      case 'starting':
        startBtn.hidden = false;
        startBtn.disabled = true;
        stopBtn.hidden = true;
        setStatusText(field, 'Starting…');
        logEl.hidden = false;
        clearLog(field);
        break;

      case 'running':
        startBtn.hidden = true;
        stopBtn.hidden = false;
        progressEl.hidden = false;
        logEl.hidden = false;
        setStatusText(field, 'Running');
        break;

      case 'stopped':
      case 'finished':
        startBtn.hidden = false;
        startBtn.disabled = false;
        stopBtn.hidden = true;
        setStatusText(field, state === 'stopped' ? 'Stopped' : 'Finished');
        break;
    }
  }

  function setStatusText(field, text) {
    var el = field.querySelector('.background-task-field__status');
    if (el) el.textContent = text;
  }

  function updateProgress(field, percent) {
    var bar = field.querySelector('.background-task-field__progress-bar');
    var text = field.querySelector('.background-task-field__progress-text');
    if (bar) bar.style.width = Math.min(100, Math.max(0, percent)) + '%';
    if (text) text.textContent = Math.round(percent) + '%';
  }

  function appendLog(field, message, type) {
    var container = field.querySelector('.background-task-field__log-content');
    if (!container) return;

    var line = document.createElement('div');
    line.className = 'background-task-field__log-line background-task-field__log-line--' + (type || 'info');
    line.textContent = message;
    container.appendChild(line);

    // Auto-scroll
    container.scrollTop = container.scrollHeight;
  }

  function clearLog(field) {
    var container = field.querySelector('.background-task-field__log-content');
    if (container) container.innerHTML = '';
  }
})();
