/**
 * @file
 * Handles confirmation dialog for mirror field changes on vocabulary forms.
 *
 * Provides save and cancel button functionality for the mirror field
 * change confirmation dialog.
 */


(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.mirrorChangeConfirm = {
    attach: function (context, settings) {
      // Handle save button click
      once('relationship-nodes-modal-save', '#relationship-nodes-modal-save', context)
        .forEach(function (el) {
          $(el).on('click', function (e) {
            e.preventDefault();
            $('#relationship-nodes-confirm-mirror-change').val(1);
            $('#relationship-nodes-hidden-submit').trigger('click');
            $(el).closest('.ui-dialog-content').dialog('close');
          });
        });

      // Handle cancel button click
      once('relationship-nodes-modal-cancel', '#relationship-nodes-modal-cancel', context)
        .forEach(function (el) {
          $(el).on('click', function (e) {
            e.preventDefault();
            var dialog = $(el).closest('.ui-dialog-content');
            var originalValue = $('#relationship-nodes-confirm-mirror-change').attr('data-original');
            $('#relationship-nodes-referencing-type input[type="radio"]').each(function () {
              $(this).prop('checked', $(this).val() === originalValue);
            });
            dialog.dialog('close');
          });
        });
    }
  };
})(jQuery, Drupal, once);
