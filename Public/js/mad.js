(function () {
  function toggle() {
    var enabled = !!jQuery("input[name='mad_enabled']:checked").length;
    jQuery('#mad_settings_block').toggleClass('hidden', !enabled);
    jQuery('#mad_users_block').toggleClass('hidden', !enabled);
  }

  jQuery(function () {
    if (!jQuery("input[name='mad_enabled']").length) {
      return;
    }
    toggle();
    jQuery(document).on('change', "input[name='mad_enabled']", toggle);
  });
})();
