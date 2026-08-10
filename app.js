(function (window) {
  'use strict';

  /**
   * A location picker showing exactly one <select> at a time — the children
   * of whatever's currently selected — with a breadcrumb above it for the
   * path chosen so far (click an earlier crumb to jump back up). Replaces
   * the previous "grows a new <select> per level, stacked vertically"
   * design, which took up too much visual space once locations routinely
   * went 4+ levels deep (that stacked design itself had replaced an even
   * older fixed-3-level picker for the same reason — see git history in
   * part_modal.php, owned_sets.php's "move" modal, and
   * owned_set_wizard.php's location step, the three places this is used).
   *
   * @param {HTMLElement} container - emptied and filled with a
   *   ".location-breadcrumb" trail plus a single ".location-level" row.
   * @param {{selectPlaceholder:string, noChildren:string, levelLabel:string, rootLabel:string}} texts
   *   levelLabel is a template containing a literal "{n}" placeholder.
   * @param {function(?string):void} onChange - called with the deepest
   *   currently selected location id (as a string), or null if nothing is
   *   selected at any level.
   * @param {?string} [initialLocationId] - if given, the picker restores
   *   this location's full breadcrumb/select path on load instead of
   *   starting empty at the root (one extra request for its ancestor
   *   chain — see action=location_ancestors). Silently falls back to an
   *   empty root start if the id no longer exists.
   * @returns {{getValue: function():?string, getLabel: function():string, reset: function():void}}
   */
  function createLocationPicker(container, texts, onChange, initialLocationId) {
    var path = []; // [{id, name}], deepest last; empty means nothing chosen yet

    function currentParentId() {
      return path.length > 0 ? path[path.length - 1].id : null;
    }

    function deepestValue() {
      return path.length > 0 ? path[path.length - 1].id : null;
    }

    function deepestLabel() {
      return path.length > 0 ? path[path.length - 1].name : '';
    }

    var breadcrumbEl = document.createElement('div');
    breadcrumbEl.className = 'location-breadcrumb';
    breadcrumbEl.style.display = 'none';
    container.appendChild(breadcrumbEl);

    var wrap = document.createElement('div');
    wrap.className = 'location-level';
    var labelSpan = document.createElement('span');
    labelSpan.className = 'location-level-label';
    wrap.appendChild(labelSpan);
    var select = document.createElement('select');
    wrap.appendChild(select);
    var hint = document.createElement('span');
    hint.className = 'location-hint';
    wrap.appendChild(hint);
    container.appendChild(wrap);

    function renderBreadcrumb() {
      breadcrumbEl.innerHTML = '';
      if (path.length === 0) {
        breadcrumbEl.style.display = 'none';
        return;
      }
      breadcrumbEl.style.display = 'flex';

      var rootBtn = document.createElement('button');
      rootBtn.type = 'button';
      rootBtn.className = 'location-breadcrumb-item';
      rootBtn.textContent = texts.rootLabel;
      rootBtn.addEventListener('click', function () {
        goTo(0);
      });
      breadcrumbEl.appendChild(rootBtn);

      path.forEach(function (crumb, i) {
        var sep = document.createElement('span');
        sep.className = 'location-breadcrumb-sep';
        sep.textContent = '/';
        breadcrumbEl.appendChild(sep);

        if (i === path.length - 1) {
          var current = document.createElement('span');
          current.className = 'location-breadcrumb-current';
          current.textContent = crumb.name;
          breadcrumbEl.appendChild(current);
        } else {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'location-breadcrumb-item';
          btn.textContent = crumb.name;
          btn.addEventListener('click', function () {
            goTo(i + 1);
          });
          breadcrumbEl.appendChild(btn);
        }
      });
    }

    // select/hint are reused across levels (unlike the old per-level
    // <select> design), so a slow response from a level the user has since
    // navigated away from must not be allowed to land on top of whatever
    // loadLevel() shows now — loadToken guards exactly that.
    var loadToken = 0;

    function loadLevel() {
      var token = ++loadToken;
      select.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
      select.style.display = '';
      hint.textContent = '';
      labelSpan.textContent = texts.levelLabel.replace('{n}', String(path.length + 1));

      var params = new URLSearchParams();
      params.set('action', 'location_children');
      var parentId = currentParentId();
      if (parentId !== null) {
        params.set('parent_id', parentId);
      }
      fetch('?' + params.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (token !== loadToken) {
            return;
          }
          var children = data.children || [];
          children.forEach(function (loc) {
            var opt = document.createElement('option');
            opt.value = loc.id;
            opt.textContent = loc.name;
            select.appendChild(opt);
          });
          if (children.length === 0) {
            select.style.display = 'none';
            hint.textContent = texts.noChildren;
          }
        })
        .catch(function () {
          if (token !== loadToken) {
            return;
          }
          select.style.display = 'none';
          hint.textContent = texts.noChildren;
        });
    }

    function goTo(depth) {
      path = path.slice(0, depth);
      renderBreadcrumb();
      loadLevel();
      onChange(deepestValue());
    }

    select.addEventListener('change', function () {
      if (!select.value) {
        return;
      }
      var opt = select.options[select.selectedIndex];
      path.push({ id: select.value, name: opt.textContent });
      renderBreadcrumb();
      loadLevel();
      onChange(deepestValue());
    });

    function restoreInitialPath(locationId) {
      var params = new URLSearchParams();
      params.set('action', 'location_ancestors');
      params.set('id', locationId);
      fetch('?' + params.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var ancestors = data.ancestors || [];
          if (ancestors.length === 0) {
            loadLevel();
            return;
          }
          // The response is already root-first/target-last — exactly the
          // shape `path` needs, so no per-level simulated clicking is
          // needed; loadLevel() below then loads whatever's one level
          // deeper than the restored target, same as after a real click.
          path = ancestors.map(function (a) {
            return { id: String(a.id), name: a.name };
          });
          renderBreadcrumb();
          loadLevel();
          onChange(deepestValue());
        })
        .catch(function () {
          loadLevel();
        });
    }

    if (initialLocationId) {
      restoreInitialPath(initialLocationId);
    } else {
      loadLevel();
    }

    return {
      getValue: deepestValue,
      getLabel: deepestLabel,
      reset: function () {
        path = [];
        renderBreadcrumb();
        loadLevel();
        onChange(null);
      },
    };
  }

  window.createLocationPicker = createLocationPicker;

  /**
   * Patches the status-bar stats (top of every app page — see renderApp()
   * in index.php, spans id="status-stat-<key>") after an AJAX action that
   * changed storage_items/owned_sets. Those actions already call
   * refreshAppStatsCache() server-side; this just needs the resulting
   * numbers included in their JSON response and passed here, so the bar
   * doesn't sit stale until the next full page load. A no-op for any key
   * whose span isn't on the current page. Mirrors the local applyStats()
   * already used by the owned_set_detail tabs (src/owned_sets.php) — kept
   * as a separate shared copy here rather than refactoring that one, since
   * it already works and isn't broken.
   */
  function applyStatusStats(stats) {
    if (!stats) {
      return;
    }
    var sep = document.documentElement.lang === 'de' ? '.' : ',';
    Object.keys(stats).forEach(function (key) {
      var el = document.getElementById('status-stat-' + key);
      var strong = el ? el.querySelector('strong') : null;
      if (strong) {
        strong.textContent = String(stats[key]).replace(/\B(?=(\d{3})+(?!\d))/g, sep);
      }
    });
  }

  window.applyStatusStats = applyStatusStats;

  /**
   * Measures the sticky status-bar+nav header's actual rendered height
   * (see .app-header-fixed, index.php's renderApp()) and exposes it as a
   * CSS custom property, so any page's own sticky element can offset
   * itself with `top: var(--sticky-header-height, 0px)` instead of
   * guessing/hardcoding a pixel value that would drift whenever the
   * header's own content wraps (e.g. the status bar's stats row on a
   * narrower viewport). A no-op if the header isn't present (shouldn't
   * happen on an authenticated page, but cheap to guard).
   */
  function updateStickyHeaderHeight() {
    var header = document.querySelector('.app-header-fixed');
    if (!header) {
      return;
    }
    document.documentElement.style.setProperty('--sticky-header-height', header.offsetHeight + 'px');
  }
  // A single measurement right at DOMContentLoaded can still land ~50-60px
  // short — the DOM exists by then, but the browser hasn't necessarily
  // finished a real layout/paint pass yet (e.g. the status bar's flex-wrap
  // stats row settling into its final wrapped/unwrapped shape). Re-measuring
  // inside requestAnimationFrame (after the browser's next layout) and again
  // on the full "load" event (everything, including any late-settling
  // content, finished) catches that without guessing a timeout duration.
  function scheduleUpdateStickyHeaderHeight() {
    updateStickyHeaderHeight();
    window.requestAnimationFrame(updateStickyHeaderHeight);
  }
  // app.js itself loads in <head> without defer (see renderApp()'s own doc
  // comment on that script tag), so it runs before <body> — and
  // .app-header-fixed with it — exists. Calling updateStickyHeaderHeight()
  // immediately here always found nothing and silently no-opped, leaving
  // the CSS variable at its 0px fallback forever: the sidebar then stuck
  // flush with the real viewport top, scrolling out from *behind* the
  // (also sticky, opaque) header instead of sitting below it — exactly the
  // "top of the sidebar disappears behind the header" symptom this fixes.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleUpdateStickyHeaderHeight);
  } else {
    // Already past "loading" (e.g. this script got injected/re-run later)
    // — DOMContentLoaded won't fire again, so call directly.
    scheduleUpdateStickyHeaderHeight();
  }
  window.addEventListener('load', updateStickyHeaderHeight);
  window.addEventListener('resize', updateStickyHeaderHeight);
})(window);
