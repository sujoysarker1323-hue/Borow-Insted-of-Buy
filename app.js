/* BIB — shared UI behaviour (static preview) */
(function () {
  'use strict';

  /* ---------- Theme ---------- */
  var root = document.documentElement;
  var stored = null;
  try { stored = localStorage.getItem('bib-theme'); } catch (e) {}
  if (stored === 'dark' || (!stored && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    root.setAttribute('data-theme', 'dark');
  }
  function syncThemeButtons() {
    var dark = root.getAttribute('data-theme') === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      b.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
    });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (!t) return;
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    if (next === 'dark') root.setAttribute('data-theme', 'dark');
    else root.removeAttribute('data-theme');
    try { localStorage.setItem('bib-theme', next); } catch (err) {}
    syncThemeButtons();
  });
  syncThemeButtons();

  /* ---------- Mobile nav ---------- */
  var mt = document.querySelector('[data-menu-toggle]');
  var mn = document.querySelector('.mobile-nav');
  if (mt && mn) {
    mt.addEventListener('click', function () {
      mn.classList.toggle('open');
      mt.setAttribute('aria-expanded', mn.classList.contains('open') ? 'true' : 'false');
    });
  }

  /* ---------- Toast ---------- */
  window.bibToast = function (msg) {
    var wrap = document.querySelector('.toast-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toast-wrap'; document.body.appendChild(wrap); }
    var el = document.createElement('div');
    el.className = 'toast';
    el.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg><span></span>';
    el.querySelector('span').textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 2400);
    setTimeout(function () { el.remove(); }, 2800);
  };

  /* ---------- Modal ---------- */
  window.bibModal = function (id, open) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.toggle('open', open !== false);
    document.body.style.overflow = m.classList.contains('open') ? 'hidden' : '';
  };
  document.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
      e.target.classList.remove('open');
      document.body.style.overflow = '';
    }
    var c = e.target.closest('[data-modal-close]');
    if (c) {
      var bd = c.closest('.modal-backdrop');
      if (bd) { bd.classList.remove('open'); document.body.style.overflow = ''; }
    }
    var o = e.target.closest('[data-modal-open]');
    if (o) { e.preventDefault(); window.bibModal(o.getAttribute('data-modal-open'), true); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(function (m) { m.classList.remove('open'); });
      document.body.style.overflow = '';
    }
  });

  /* ---------- Save / wishlist ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.save-btn');
    if (!b) return;
    e.preventDefault();
    b.classList.toggle('saved');
    var on = b.classList.contains('saved');
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    window.bibToast(on ? 'Saved to your wishlist' : 'Removed from wishlist');
  });

  /* ---------- Tabs ---------- */
  document.querySelectorAll('[data-tabs]').forEach(function (group) {
    group.querySelectorAll('.tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-tab');
        group.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        var scope = group.parentElement;
        scope.querySelectorAll('.tab-panel').forEach(function (p) {
          p.classList.toggle('active', p.getAttribute('data-panel') === name);
        });
      });
    });
  });

  /* ---------- Gallery ---------- */
  var mainImg = document.querySelector('[data-gallery-main]');
  document.querySelectorAll('[data-gallery-thumb]').forEach(function (t) {
    t.addEventListener('click', function () {
      document.querySelectorAll('[data-gallery-thumb]').forEach(function (x) { x.classList.remove('active'); });
      t.classList.add('active');
      if (mainImg) mainImg.src = t.getAttribute('data-src');
    });
  });

  /* ---------- Calendar ---------- */
  document.querySelectorAll('[data-calendar]').forEach(function (cal) {
    var booked = (cal.getAttribute('data-booked') || '').split(',').filter(Boolean).map(Number);
    var grid = cal.querySelector('.cal-grid');
    var label = cal.querySelector('[data-cal-label]');
    var view = new Date(2026, 10, 1); // Nov 2026
    var selected = null;

    function render() {
      var y = view.getFullYear(), m = view.getMonth();
      label.textContent = view.toLocaleString('en-US', { month: 'long', year: 'numeric' });
      grid.querySelectorAll('.cal-day').forEach(function (n) { n.remove(); });
      var first = new Date(y, m, 1).getDay();
      var days = new Date(y, m + 1, 0).getDate();
      var prevDays = new Date(y, m, 0).getDate();
      for (var i = first - 1; i >= 0; i--) {
        var d = document.createElement('div');
        d.className = 'cal-day muted';
        d.textContent = prevDays - i;
        grid.appendChild(d);
      }
      for (var day = 1; day <= days; day++) {
        var cell = document.createElement('div');
        var isBooked = booked.indexOf(day) > -1;
        cell.className = 'cal-day ' + (isBooked ? 'booked' : 'available');
        if (day === 2 && m === 10 && y === 2026) cell.classList.add('today');
        if (selected === day) cell.classList.add('selected');
        cell.textContent = day;
        cell.setAttribute('role', 'button');
        cell.setAttribute('tabindex', '0');
        if (!isBooked) {
          cell.addEventListener('click', function (dd) {
            return function () { selected = dd; render(); };
          }(day));
        }
        grid.appendChild(cell);
      }
    }
    cal.querySelector('[data-cal-prev]').addEventListener('click', function () { view.setMonth(view.getMonth() - 1); render(); });
    cal.querySelector('[data-cal-next]').addEventListener('click', function () { view.setMonth(view.getMonth() + 1); render(); });
    render();
  });

  /* ---------- Filter chips (search page) ---------- */
  document.querySelectorAll('[data-filter-pill]').forEach(function (p) {
    p.querySelector('button').addEventListener('click', function () {
      p.remove();
      window.bibToast('Filter removed');
    });
  });

  /* ---------- Demo forms ---------- */
  document.querySelectorAll('[data-demo-form]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      window.bibToast(f.getAttribute('data-demo-form') || 'Saved');
      var bd = f.closest('.modal-backdrop');
      if (bd) { bd.classList.remove('open'); document.body.style.overflow = ''; }
    });
  });

  /* ---------- Copy link ---------- */
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var url = window.location.href;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { window.bibToast('Link copied to clipboard'); });
      } else {
        window.bibToast('Link copied to clipboard');
      }
    });
  });

  /* ---------- Print ---------- */
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  /* ---------- Reveal on scroll ---------- */
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.style.opacity = '1'; en.target.style.transform = 'none'; io.unobserve(en.target); }
      });
    }, { threshold: 0.08 });
    document.querySelectorAll('[data-reveal]').forEach(function (el) {
      el.style.opacity = '0';
      el.style.transform = 'translateY(14px)';
      el.style.transition = 'opacity .5s ease, transform .5s ease';
      io.observe(el);
    });
  }
})();
