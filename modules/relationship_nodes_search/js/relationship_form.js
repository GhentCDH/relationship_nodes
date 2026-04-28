/**
 * @file
 * Handles parent/child checkbox relationship behavior in the index field config form.
 *
 * Disables parent "add" buttons when no child checkboxes are selected.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.relationshipForm = {
    attach(context) {
      once('relationship-checkbox', '.relationship-child-checkbox', context).forEach((checkbox) => {
        checkbox.addEventListener('change', function () {
          const currentTr = checkbox.closest('tr');
          let parentTr = currentTr.previousElementSibling;
          while (parentTr && !parentTr.querySelector('.relationship-parent-add')) {
            parentTr = parentTr.previousElementSibling;
          }

          if (!parentTr) {
            return;
          }

          const parentButton = parentTr.querySelector('.relationship-parent-add');

          let anyChecked = false;
          let nextRow = parentTr.nextElementSibling;
          while (nextRow && nextRow.querySelector('.relationship-child-checkbox')) {
            const cb = nextRow.querySelector('.relationship-child-checkbox');
            if (cb.checked) {
              anyChecked = true;
              break;
            }
            nextRow = nextRow.nextElementSibling;
          }

          if (anyChecked) {
            parentButton.disabled = false;
            parentButton.classList.remove('is-disabled');
          } else {
            parentButton.disabled = true;
            parentButton.classList.add('is-disabled');
          }
        });
        checkbox.dispatchEvent(new Event('change'));
      });
    }
  };
})(Drupal, once);