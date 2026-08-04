(function (window) {
  'use strict';

  /**
   * A location picker that starts with one <select> and grows a new level
   * each time the current deepest selection turns out to have children (via
   * action=location_children), stopping once a level has none. Replaces the
   * fixed-3-level pickers previously duplicated across part_modal.php,
   * owned_sets.php's "move" modal, and owned_set_wizard.php's location step
   * — none of them could reach a location nested any deeper than that,
   * confirmed as a real problem once storage locations routinely went
   * 4+ levels deep.
   *
   * @param {HTMLElement} container - emptied and filled with one
   *   ".location-level" div per level.
   * @param {{selectPlaceholder:string, noChildren:string, levelLabel:string}} texts
   *   levelLabel is a template containing a literal "{n}" placeholder.
   * @param {function(?string):void} onChange - called with the deepest
   *   currently selected location id (as a string), or null if nothing is
   *   selected at any level.
   * @returns {{getValue: function():?string, getLabel: function():string, reset: function():void}}
   */
  function createLocationPicker(container, texts, onChange) {
    var levels = [];

    function clearFrom(index) {
      while (levels.length > index) {
        var level = levels.pop();
        level.wrap.remove();
      }
    }

    function deepestValue() {
      for (var i = levels.length - 1; i >= 0; i--) {
        if (levels[i].select.value) {
          return levels[i].select.value;
        }
      }
      return null;
    }

    function deepestLabel() {
      for (var i = levels.length - 1; i >= 0; i--) {
        var select = levels[i].select;
        if (select.value) {
          var opt = select.options[select.selectedIndex];
          return opt ? opt.textContent : '';
        }
      }
      return '';
    }

    function addLevel(parentId) {
      var wrap = document.createElement('div');
      wrap.className = 'location-level';

      var labelSpan = document.createElement('span');
      labelSpan.className = 'location-level-label';
      labelSpan.textContent = texts.levelLabel.replace('{n}', String(levels.length + 1));
      wrap.appendChild(labelSpan);

      var select = document.createElement('select');
      select.innerHTML = '<option value="">' + texts.selectPlaceholder + '</option>';
      wrap.appendChild(select);

      var hint = document.createElement('span');
      hint.className = 'location-hint';
      wrap.appendChild(hint);

      container.appendChild(wrap);

      var levelIndex = levels.length;
      levels.push({ wrap: wrap, select: select, hint: hint });

      var params = new URLSearchParams();
      params.set('action', 'location_children');
      if (parentId !== null) {
        params.set('parent_id', parentId);
      }
      fetch('?' + params.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var children = data.children || [];
          children.forEach(function (loc) {
            var opt = document.createElement('option');
            opt.value = loc.id;
            opt.textContent = loc.name;
            select.appendChild(opt);
          });
          if (children.length === 0) {
            hint.textContent = texts.noChildren;
          }
        })
        .catch(function () {
          hint.textContent = texts.noChildren;
        });

      select.addEventListener('change', function () {
        clearFrom(levelIndex + 1);
        onChange(deepestValue());
        if (select.value) {
          addLevel(select.value);
        }
      });
    }

    addLevel(null);

    return {
      getValue: deepestValue,
      getLabel: deepestLabel,
      reset: function () {
        container.innerHTML = '';
        levels = [];
        addLevel(null);
        onChange(null);
      },
    };
  }

  window.createLocationPicker = createLocationPicker;
})(window);
