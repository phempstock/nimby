/* NIMBY map page.
   Loads the nightly data files written by bin/sync.php and shows them on a Leaflet map. */
(function () {
  'use strict';

  var CATEGORIES = [
    { id: 'pending',   label: 'Pending' },
    { id: 'granted',   label: 'Granted' },
    { id: 'refused',   label: 'Refused' },
    { id: 'withdrawn', label: 'Withdrawn' },
    { id: 'other',     label: 'Other outcome' }
  ];
  var LIST_PAGE = 100;
  var NEW_DAYS = 7;
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var MOBILE = window.matchMedia('(max-width: 760px)');

  var state = {
    apps: [],                 // one object per application
    bySlug: new Map(),
    shown: [],                // apps passing the filters
    cats: new Set(CATEGORIES.map(function (c) { return c.id; })),
    when: '1',
    trees: true,
    terms: [],
    pinned: null,             // slug from ?app=, always shown whatever the filters
    active: null,             // slug whose popup is open
    listLimit: LIST_PAGE
  };

  var el = {
    fresh: document.getElementById('fresh'),
    notice: document.getElementById('notice'),
    q: document.getElementById('q'),
    when: document.getElementById('when'),
    chips: document.getElementById('chips'),
    trees: document.getElementById('trees'),
    treesCount: document.getElementById('trees-count'),
    heading: document.getElementById('results-heading'),
    list: document.getElementById('list'),
    more: document.getElementById('more'),
    mapWrap: document.querySelector('.map-wrap')
  };

  // ---------- Small helpers ----------

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function formatDate(iso) {
    if (!iso) return '';
    return Number(iso.slice(8, 10)) + ' ' + MONTHS[Number(iso.slice(5, 7)) - 1] + ' ' + iso.slice(0, 4);
  }

  function isoDaysAgo(days) {
    var d = new Date();
    d.setDate(d.getDate() - days);
    return d.toISOString().slice(0, 10);
  }

  function isoYearsAgo(years) {
    var d = new Date();
    d.setFullYear(d.getFullYear() - years);
    return d.toISOString().slice(0, 10);
  }

  // "1 Clumber Road East, Nottingham, Nottingham City, NG7 1BD" -> "1 Clumber Road East, NG7 1BD"
  function tidyAddress(address) {
    if (!address) return '';
    return address.split(',')
      .map(function (part) { return part.replace(/\s+/g, ' ').trim(); })
      .filter(function (part) { return part && !/^nottingham( city)?$/i.test(part); })
      .join(', ');
  }

  // For the list: drop the postcode and any trailing "The Park".
  function shortAddress(address) {
    var parts = tidyAddress(address).split(', ').filter(function (part) {
      return !/^NG\d/i.test(part) && !/^the park$/i.test(part);
    });
    return parts.join(', ') || 'Address not given';
  }

  function slugFor(ref) {
    return String(ref || '').toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  function oneLine(text) {
    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function shareUrl(slug) {
    return location.origin + location.pathname + '?app=' + encodeURIComponent(slug);
  }

  // ---------- Map ----------

  var map = L.map('map', { minZoom: 12, maxZoom: 19, zoomSnap: 0.5, keyboard: true });
  map.attributionControl.setPrefix(false);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · Planning data: Nottingham City Council (OGL)'
  }).addTo(map);
  map.setView([52.9515, -1.1625], 16);

  var cluster = L.markerClusterGroup({
    maxClusterRadius: 38,
    showCoverageOnHover: false,
    spiderfyOnMaxZoom: true,
    chunkedLoading: true,
    iconCreateFunction: function (group) {
      var children = group.getAllChildMarkers();
      var n = children.length;
      var pending = children.filter(function (m) { return m.options.category === 'pending'; }).length;
      var size = n < 10 ? 32 : n < 100 ? 38 : 46;
      return L.divIcon({
        html: '<div>' + n + '</div>' + (pending ? '<b>' + pending + '</b>' : ''),
        className: 'cluster' + (pending ? ' has-pending' : ''),
        iconSize: [size, size]
      });
    }
  });
  map.addLayer(cluster);

  // Fit the map to The Park once it has a real size. If the page loads while the map
  // is hidden or has no size yet (a background tab, a phone turning sideways), Leaflet
  // would otherwise zoom right in; the observer fits it as soon as it can be seen.
  var parkBounds = null;
  var fitted = false;

  function fitPark() {
    var size = map.getSize();
    if (!parkBounds || size.x < 80 || size.y < 80) return false;
    map.fitBounds(parkBounds, { padding: [24, 24] });
    fitted = true;
    return true;
  }

  if (window.ResizeObserver) {
    new ResizeObserver(function () {
      map.invalidateSize({ pan: false });
      if (!fitted && fitPark()) {
        var slug = state.active || state.pinned;
        if (slug) openApp(slug, false);
      }
    }).observe(document.getElementById('map'));
  }

  function drawBoundary(geojson) {
    var outline = L.geoJSON(geojson, {
      interactive: false,
      style: { color: '#1f3d34', weight: 2.5, opacity: 0.9, dashArray: '7 5', fill: false }
    }).addTo(map);

    // Pale veil over everything outside the conservation area.
    var holes = [];
    geojson.features.forEach(function (f) {
      var polys = f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates : [f.geometry.coordinates];
      polys.forEach(function (rings) {
        holes.push(rings[0].map(function (c) { return [c[1], c[0]]; }));
      });
    });
    L.polygon([[[85, -180], [85, 180], [-85, 180], [-85, -180]]].concat(holes), {
      stroke: false, fillColor: '#f2f3ef', fillOpacity: 0.5, interactive: false, className: 'outside'
    }).addTo(map);
    outline.bringToFront();

    parkBounds = outline.getBounds();
    map.setMaxBounds(parkBounds.pad(1.5));
    fitPark();
  }

  // ---------- Applications ----------

  function buildApps(geojson) {
    var newSince = isoDaysAgo(NEW_DAYS);
    geojson.features.forEach(function (f) {
      var p = f.properties;
      var c = f.geometry && f.geometry.coordinates;
      if (!c) return;
      var app = {
        slug: p.slug || slugFor(p.ref),
        ref: p.ref,
        typeLabel: p.type_label,
        trees: !!p.trees,
        address: p.address,
        proposal: p.proposal || '',
        received: p.received,
        valid: p.valid,
        date: p.received || p.valid || '',
        status: p.status,
        decision: p.decision,
        decisionDate: p.decision_date,
        category: p.category || 'other',
        isNew: !!(p.first_seen && p.first_seen >= newSince),
        url: p.url,
        latlng: L.latLng(c[1], c[0])
      };
      app.search = [app.ref, app.address, app.proposal, app.typeLabel].join(' ').toLowerCase();
      app.marker = makeMarker(app);
      state.apps.push(app);
      state.bySlug.set(app.slug, app);
    });
    state.apps.sort(function (a, b) {
      return a.date < b.date ? 1 : a.date > b.date ? -1 : (a.ref < b.ref ? 1 : -1);
    });
  }

  function makeMarker(app) {
    var marker = L.marker(app.latlng, {
      icon: L.divIcon({
        className: 'pin cat-' + app.category + (app.isNew ? ' is-new' : ''),
        html: '<span></span>',
        iconSize: [16, 16]
      }),
      title: app.ref + ', ' + shortAddress(app.address),
      alt: app.ref,
      keyboard: true,
      riseOnHover: true,
      category: app.category
    });
    marker.bindPopup(function () { return cardHtml(app); }, {
      maxWidth: 400, autoPanPaddingTopLeft: [20, 60], autoPanPaddingBottomRight: [20, 20]
    });
    marker.on('popupopen', function () { setActive(app.slug); });
    marker.on('popupclose', function () { if (state.active === app.slug) setActive(null); });
    return marker;
  }

  function cardHtml(app) {
    var text = oneLine(app.proposal) ? app.proposal.trim() : 'No description given.';
    var truncated = app.proposal.length >= 295;
    var status = app.status || app.decision || 'Not recorded';
    var rows = '<dt>Status</dt><dd><span class="status-line"><span class="dot cat-' + app.category + '"></span>' + esc(status) + '</span></dd>';
    if (app.received) rows += '<dt>Received</dt><dd>' + formatDate(app.received) + '</dd>';
    if (app.valid && app.valid !== app.received) rows += '<dt>Validated</dt><dd>' + formatDate(app.valid) + '</dd>';
    if (app.decision && app.decision !== app.status) rows += '<dt>Decision</dt><dd>' + esc(app.decision) + '</dd>';
    if (app.decisionDate) rows += '<dt>Decided</dt><dd>' + formatDate(app.decisionDate) + '</dd>';

    return '<article class="card">' +
      '<h3 class="card-address">' + esc(tidyAddress(app.address) || 'Address not given') + '</h3>' +
      '<p class="card-type">' + esc(app.typeLabel) + ', ' + esc(app.ref) +
        (app.isNew ? '<span class="badge-new">New this week</span>' : '') + '</p>' +
      '<p class="card-proposal" style="white-space: pre-line">' + esc(text) +
        (truncated ? ' <span class="more-text">(continues in the case file)</span>' : '') + '</p>' +
      '<dl class="facts">' + rows + '</dl>' +
      '<div class="card-actions">' +
        '<a class="btn-council" href="' + esc(app.url) + '" target="_blank" rel="noopener">Open the council’s case file<span class="sr-only"> (opens in a new tab)</span></a>' +
        '<button type="button" class="btn-copy" data-slug="' + esc(app.slug) + '">Copy link to this application</button>' +
      '</div>' +
    '</article>';
  }

  function setActive(slug) {
    var previous = state.active && state.bySlug.get(state.active);
    if (previous && previous.marker.getElement()) previous.marker.getElement().classList.remove('is-active');
    state.active = slug;

    var app = slug && state.bySlug.get(slug);
    if (app && app.marker.getElement()) app.marker.getElement().classList.add('is-active');

    var params = new URLSearchParams(location.search);
    if (slug) {
      params.set('app', slug);
    } else {
      params.delete('app');
    }
    var query = params.toString();
    history.replaceState(null, '', location.pathname + (query ? '?' + query : ''));

    Array.prototype.forEach.call(el.list.querySelectorAll('.item'), function (b) {
      b.setAttribute('aria-current', b.dataset.slug === slug ? 'true' : 'false');
    });
  }

  function openApp(slug, fromList) {
    var app = state.bySlug.get(slug);
    if (!app) return false;
    if (fromList && MOBILE.matches) el.mapWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    cluster.zoomToShowLayer(app.marker, function () { app.marker.openPopup(); });
    return true;
  }

  // ---------- Filtering ----------

  function passes(app, ignore) {
    if (app.slug === state.pinned) return true;
    if (state.when !== 'all' && (!app.date || app.date < state.cutoff)) return false;
    if (ignore !== 'trees' && !state.trees && app.trees) return false;
    for (var i = 0; i < state.terms.length; i++) {
      if (app.search.indexOf(state.terms[i]) === -1) return false;
    }
    if (ignore !== 'category' && !state.cats.has(app.category)) return false;
    return true;
  }

  function readFilters() {
    state.when = el.when.value;
    state.cutoff = state.when === 'all' ? '' : isoYearsAgo(Number(state.when));
    state.trees = el.trees.checked;
    state.terms = el.q.value.toLowerCase().split(/\s+/).filter(Boolean);
    state.cats = new Set(Array.prototype.map.call(el.chips.querySelectorAll('input:checked'), function (i) { return i.value; }));
  }

  function apply() {
    readFilters();

    var counts = {};
    CATEGORIES.forEach(function (c) { counts[c.id] = 0; });
    var treeCount = 0;
    state.apps.forEach(function (app) {
      if (passes(app, 'category')) counts[app.category] = (counts[app.category] || 0) + 1;
      if (app.trees && passes(app, 'trees')) treeCount++;
    });
    CATEGORIES.forEach(function (c) {
      var node = el.chips.querySelector('[data-count="' + c.id + '"]');
      if (node) node.textContent = counts[c.id];
    });
    el.treesCount.textContent = '(' + treeCount + ')';

    state.shown = state.apps.filter(function (app) { return passes(app); });
    cluster.clearLayers();
    cluster.addLayers(state.shown.map(function (app) { return app.marker; }));

    state.listLimit = LIST_PAGE;
    renderList();
  }

  function renderChips() {
    el.chips.innerHTML = CATEGORIES.map(function (c) {
      return '<label class="chip"><input type="checkbox" value="' + c.id + '" checked>' +
        '<span><span class="dot cat-' + c.id + '"></span>' + esc(c.label) +
        ' <span class="count" data-count="' + c.id + '"></span></span></label>';
    }).join('');
  }

  // ---------- List ----------

  function renderList() {
    var bounds = map.getBounds();
    var inView = state.shown.filter(function (app) { return bounds.contains(app.latlng); });

    var total = state.shown.length;
    var heading;
    if (total === 0) {
      heading = 'No applications match';
    } else if (inView.length === total) {
      heading = total === 1 ? '1 application' : total.toLocaleString('en-GB') + ' applications';
    } else {
      heading = inView.length.toLocaleString('en-GB') + ' of ' + total.toLocaleString('en-GB') +
        ' <span class="sub">in this part of the map</span>';
    }
    el.heading.innerHTML = heading;

    var focusedSlug = document.activeElement && document.activeElement.classList.contains('item')
      ? document.activeElement.dataset.slug : null;

    if (total === 0) {
      el.list.innerHTML = '<li class="empty"><strong>Nothing matches these filters.</strong> ' +
        'Try a longer time period, tick more statuses, or clear the search.</li>';
      el.more.hidden = true;
      return;
    }
    if (inView.length === 0) {
      el.list.innerHTML = '<li class="empty">None of the matching applications are in this part of the map. Zoom out to see them.</li>';
      el.more.hidden = true;
      return;
    }

    el.list.innerHTML = inView.slice(0, state.listLimit).map(function (app) {
      var status = app.status || app.decision || 'Status not recorded';
      return '<li><button type="button" class="item" data-slug="' + esc(app.slug) + '" aria-current="' + (app.slug === state.active) + '">' +
        '<span class="item-top"><span class="item-address">' + esc(shortAddress(app.address)) +
          (app.isNew ? '<span class="badge-new">New</span>' : '') + '</span>' +
          '<span class="item-date">' + formatDate(app.date) + '</span></span>' +
        '<span class="item-proposal">' + esc(oneLine(app.proposal) || 'No description given.') + '</span>' +
        '<span class="item-meta"><span class="dot cat-' + app.category + '"></span>' + esc(status) +
          '<span class="ref">' + esc(app.ref) + '</span></span>' +
      '</button></li>';
    }).join('');

    var remaining = inView.length - state.listLimit;
    el.more.hidden = remaining <= 0;
    if (remaining > 0) el.more.textContent = 'Show ' + Math.min(remaining, LIST_PAGE) + ' more of ' + remaining.toLocaleString('en-GB');

    if (focusedSlug) {
      var again = el.list.querySelector('.item[data-slug="' + focusedSlug + '"]');
      if (again) again.focus({ preventScroll: true });
    }
  }

  // ---------- Events ----------

  var searchTimer;
  el.q.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 180);
  });
  el.when.addEventListener('change', apply);
  el.trees.addEventListener('change', apply);
  el.chips.addEventListener('change', apply);
  document.getElementById('filters').addEventListener('submit', function (e) { e.preventDefault(); apply(); });

  el.list.addEventListener('click', function (e) {
    var button = e.target.closest('.item');
    if (button) openApp(button.dataset.slug, true);
  });
  el.more.addEventListener('click', function () {
    state.listLimit += LIST_PAGE;
    renderList();
  });

  var listTimer;
  map.on('moveend', function () {
    clearTimeout(listTimer);
    listTimer = setTimeout(function () { state.listLimit = LIST_PAGE; renderList(); }, 120);
  });

  document.getElementById('map').addEventListener('click', function (e) {
    var button = e.target.closest('.btn-copy');
    if (!button) return;
    var url = shareUrl(button.dataset.slug);
    var done = function () { button.textContent = 'Link copied'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url, done); });
    } else {
      fallbackCopy(url, done);
    }
  });

  function fallbackCopy(text, done) {
    var input = document.createElement('input');
    input.value = text;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try { if (document.execCommand('copy')) done(); } catch (err) { /* leave the button as it was */ }
    document.body.removeChild(input);
  }

  function showNotice(text) {
    el.notice.textContent = text;
    el.notice.hidden = false;
  }

  // ---------- Start ----------

  function getJson(path) {
    return fetch(path, { cache: 'no-cache' }).then(function (r) {
      if (!r.ok) throw new Error(path + ' returned ' + r.status);
      return r.json();
    });
  }

  renderChips();

  var requested = new URLSearchParams(location.search).get('app');
  if (requested) state.pinned = slugFor(requested);

  Promise.all([getJson('data/applications.geojson'), getJson('data/park-boundary.geojson'), getJson('data/meta.json')])
    .then(function (results) {
      drawBoundary(results[1]);
      buildApps(results[0]);

      var synced = new Date(results[2].synced_at.replace(' ', 'T'));
      el.fresh.textContent = 'Council data checked ' + synced.toLocaleString('en-GB', {
        day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London'
      }).replace(',', ' at');

      apply();

      if (state.pinned) {
        if (!state.bySlug.has(state.pinned)) {
          showNotice('Application ' + requested + ' isn’t in the data for The Park. It may be outside the conservation area.');
          state.pinned = null;
          setActive(null);
        } else if (fitted) {
          openApp(state.pinned, false);
        }
      }
    })
    .catch(function (err) {
      el.fresh.textContent = 'The applications couldn’t be loaded. Refresh the page to try again.';
      el.heading.textContent = 'Applications unavailable';
      if (window.console) console.error(err);
    });
})();
