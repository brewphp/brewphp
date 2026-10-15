// BrewPHP admin: "select all" for list actions and the selection counter.
// Served from resources/admin/ (no inline scripts, so a strict CSP works).
(function () {
    'use strict';

    function init() {
        var toggle = document.getElementById('action-toggle');
        var boxes = Array.prototype.slice.call(document.querySelectorAll('input.action-select'));
        var counter = document.querySelector('.action-counter');
        if (!boxes.length) {
            return;
        }

        function update() {
            var checked = boxes.filter(function (box) { return box.checked; }).length;
            boxes.forEach(function (box) {
                var row = box.closest('tr');
                if (row) {
                    row.classList.toggle('selected', box.checked);
                }
            });
            if (counter) {
                counter.textContent = checked + ' of ' + boxes.length + ' selected';
            }
            if (toggle) {
                toggle.checked = checked === boxes.length;
                toggle.indeterminate = checked > 0 && checked < boxes.length;
            }
        }

        if (toggle) {
            toggle.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = toggle.checked; });
                update();
            });
        }
        boxes.forEach(function (box) { box.addEventListener('change', update); });
        update();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
