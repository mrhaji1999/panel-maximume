(function($){
  function ucSetStep(stepNumber){
    var $m = $('#uc-card-modal');
    // 1) body steps
    $m.find('.uc-step').hide();
    $m.find('.uc-step-'+stepNumber).show();
    // 2) stepper head sync (supports both legacy and new class names)
    var $items = $m.find('.uc-stepper li');
    $items.removeClass('is-active is-complete active done').removeAttr('aria-current');
    $items.each(function(i){
      var n = parseInt($(this).data('step'),10);
      if (!n) n = i+1;
      if (n < stepNumber) {
        $(this).addClass('is-complete done');
      } else if (n === stepNumber) {
        $(this).addClass('is-active active').attr('aria-current','step');
      }
    });
    $m.attr('data-step', stepNumber);
  }
  // Failsafe: clear any pre-existing active/done classes from server markup
  $(function(){
    var $m = $('#uc-card-modal');
    $m.find('.uc-stepper li').removeClass('active done');
  });
  function switchTab($container, tab){
    $container.find('.uc-tab').removeClass('active');
    $container.find('.uc-tab[data-tab="'+tab+'"]').addClass('active');
    $container.find('.uc-tab-content').removeClass('active');
    $container.find('#uc-tab-'+tab).addClass('active');
  }

  $(document).on('click', '.uc-auth .uc-tab', function(){
    var tab = $(this).data('tab');
    switchTab($(this).closest('.uc-auth'), tab);
  });

  // Login
  $('#uc-login-form').on('submit', function(e){
    e.preventDefault();
    var $f = $(this), data = $f.serialize();
    $f.addClass('uc-loading');
    $.post(UC_Ajax.ajax_url, data).done(function(res){
      if(res.success){
        window.location.href = res.data.redirect;
      } else {
        $f.find('.uc-form-msg').text(res.data && res.data.message || UC_Ajax.i18n.serverError);
      }
    }).fail(function(){
      $f.find('.uc-form-msg').text(UC_Ajax.i18n.serverError);
    }).always(function(){ $f.removeClass('uc-loading'); });
  });

  // Register
  $('#uc-register-form').on('submit', function(e){
    e.preventDefault();
    var $f = $(this), data = $f.serialize();
    $f.addClass('uc-loading');
    $.post(UC_Ajax.ajax_url, data).done(function(res){
      if(res.success){
        window.location.href = res.data.redirect;
      } else {
        $f.find('.uc-form-msg').text(res.data && res.data.message || UC_Ajax.i18n.serverError);
      }
    }).fail(function(){
      $f.find('.uc-form-msg').text(UC_Ajax.i18n.serverError);
    }).always(function(){ $f.removeClass('uc-loading'); });
  });

  // Dashboard modal
  var currentCardId = null, selectedTime = '', validatedCode = '';

  function ucFetchAvailability() {
    var $m = $('#uc-card-modal');
    var iso = ($m.find('#uc-date-input').attr('data-gregorian') || '').toString();
    if (!currentCardId || !iso) return;
    var date = iso.substring(0,10);
    var endpoint = (window.location.origin || '') + '/wp-json/ucx/v1/availability?card_id=' + encodeURIComponent(currentCardId) + '&date=' + encodeURIComponent(date);
    $m.find('.uc-step-3').addClass('uc-loading');
    $.getJSON(endpoint).done(function(res){
      var map = {};
      if (res && res.hours) { res.hours.forEach(function(h){ map[String(h.hour).padStart(2,'0')] = !!h.available; }); }
      $m.find('.uc-time').each(function(){
        var label = ($(this).data('time') || '').toString();
        var hourKey = label.replace(/[^0-9]/g, '').padStart(2,'0');
        var isAvail = (hourKey in map) ? map[hourKey] : true; // default true if not configured
        if (isAvail) {
          $(this).prop('disabled', false).removeClass('is-disabled');
        } else {
          $(this).prop('disabled', true).removeClass('active').addClass('is-disabled');
          if (selectedTime === label) { selectedTime = ''; }
        }
      });
    }).always(function(){ $m.find('.uc-step-3').removeClass('uc-loading'); });
  }

  // Recompute availability on date change
  $(document).on('change', '#uc-date-input', function(){
    ucFetchAvailability();
  });

  function initUserSnippet(){
    if (typeof window.shamsiDatePickerInit === 'function') {
      window.shamsiDatePickerInit();
      return true;
    }
    return false;
  }

  $(document).on('click', '.uc-card', function(){
    var $card = $(this);
    currentCardId = $card.data('card-id');
    var content = $card.find('.uc-card-content').html();
    var $m = $('#uc-card-modal');
    $m.addClass('active');
    ucSetStep(1);
    $m.find('.uc-step-1 .uc-step-content').html(content);
    validatedCode = '';
    selectedTime = '';
    $('#uc-date-input').val('');
    $m.attr('aria-hidden', 'false');

    // Attempt early init
    initUserSnippet();
  });

  $(document).on('click', '.uc-modal-close, [data-action="close-modal"]', function(){
    $('#uc-card-modal').removeClass('active').attr('aria-hidden','true');
  });
  $(document).on('click', '.uc-modal-backdrop', function(){
    $('#uc-card-modal').removeClass('active').attr('aria-hidden','true');
  });

  // Step switches
  $(document).on('click', '[data-action="have-code"]', function(){
    var $m = $('#uc-card-modal');
    ucSetStep(2);
    $m.find('#uc-code-input').val('').focus();
    $m.find('.uc-step-2 .uc-inline-msg').text('');
  });
  $(document).on('click', '[data-action="back-1"]', function(){
    ucSetStep(1);
  });
  $(document).on('click', '[data-action="back-2"]', function(){
    ucSetStep(2);
  });

  // Validate code
  $(document).on('click', '[data-action="validate-code"]', function(){
    var $m = $('#uc-card-modal');
    var code = ($m.find('#uc-code-input').val() || '').trim();
    if(!code){ $m.find('.uc-step-2 .uc-inline-msg').text(UC_Ajax.i18n.invalidCode); return; }
    var payload = { action: 'uc_validate_code', _wpnonce: UC_Ajax.nonce, code: code, card_id: currentCardId };
    $m.find('.uc-step-2').addClass('uc-loading');
    $.post(UC_Ajax.ajax_url, payload).done(function(res){
      if(res.success){
        validatedCode = code;
        ucSetStep(3);
        $m.find('.uc-step-2 .uc-inline-msg').text('');
        // Ensure datepicker initializes when step 3 becomes visible
        setTimeout(function(){ initUserSnippet(); }, 0);\n        // When step 3 shows, try to fetch availability if date preselected\n        setTimeout(function(){ ucFetchAvailability(); }, 10);
      } else {
        var status = res.data && res.data.status;
        if(status === 'used'){ $m.find('.uc-step-2 .uc-inline-msg').text(UC_Ajax.i18n.usedCode); }
        else { $m.find('.uc-step-2 .uc-inline-msg').text(UC_Ajax.i18n.invalidCode); }
      }
    }).fail(function(){
      $m.find('.uc-step-2 .uc-inline-msg').text(UC_Ajax.i18n.serverError);
    }).always(function(){ $m.find('.uc-step-2').removeClass('uc-loading'); });
  });

  // Time select (prevent selecting disabled hours)
  $(document).on('click', '.uc-time', function(){
    if ($(this).is(':disabled')) return;
    $(".uc-time").removeClass('active');
    $(this).addClass('active');
    selectedTime = $(this).data('time');
  });
  // Submit form
  $(document).on('click', '[data-action="submit-form"]', function(){
    var $m = $('#uc-card-modal');
    var date = ($m.find('#uc-date-input').val() || '').trim();
    if(!validatedCode){ $m.find('.uc-step-3').prev('.uc-inline-msg').text(UC_Ajax.i18n.invalidCode); return; }
    if(!date || !selectedTime){ alert('لطفا تاریخ و ساعت را انتخاب کنید.'); return; }
    var payload = { action:'uc_submit_form', _wpnonce: UC_Ajax.nonce, card_id: currentCardId, code: validatedCode, date: date, time: selectedTime };
    $m.find('.uc-step-3').addClass('uc-loading');
    $.post(UC_Ajax.ajax_url, payload).done(function(res){
      if(res.success){
        ucSetStep(4);
        var msg = 'ثبت شد. \nکد سوپرایز شما: ' + (res.data && res.data.surprise || '');
        $m.find('.uc-step-4 .uc-success').text(msg);
      } else {
        alert((res.data && res.data.message) || UC_Ajax.i18n.serverError);
      }
    }).fail(function(){ alert(UC_Ajax.i18n.serverError); })
      .always(function(){ $m.find('.uc-step-3').removeClass('uc-loading'); });
  });
})(jQuery);
