(function($){
  function getText(key, fallback){
    if (typeof UC_Admin !== 'undefined' && UC_Admin.i18n && UC_Admin.i18n[key]) {
      return UC_Admin.i18n[key];
    }
    return fallback || '';
  }

  function setStatus($el, type, message){
    if(!$el || !$el.length) return;
    var colors = {
      success: '#1d8102',
      error: '#a00',
      loading: '#555'
    };
    var color = colors[type] || '';
    $el.text(message || '');
    if(color){ $el.css('color', color); }
    else { $el.css('color', ''); }
  }

  function fetchPosts(pt){
    var $sel = $('#uc_related_post_id');
    $sel.prop('disabled', true).empty().append('<option>Loading…</option>');
    $.get(UC_Admin.ajax_url, { action:'uc_admin_fetch_posts', post_type: pt })
      .done(function(res){
        $sel.empty().append('<option value="">-- Select --</option>');
        if(res.success && res.data.posts){
          res.data.posts.forEach(function(p){ $sel.append('<option value="'+p.id+'">'+$('<div/>').text(p.text).html()+'</option>'); });
        }
      })
      .always(function(){ $sel.prop('disabled', false); });
  }

  $(document).on('change', '#uc_related_post_type', function(){ fetchPosts($(this).val()); });

  // Upload + init import
  $(document).on('click', '#uc_upload_start', function(){
    var card = $(this).data('card');
    var file = $('#uc_codes_csv')[0].files[0];
    if(!file){ alert('ابتدا فایل CSV را انتخاب کنید.'); return; }
    var fd = new FormData();
    fd.append('action','uc_import_codes_init');
    fd.append('nonce', UC_Admin.nonce);
    fd.append('card_id', card);
    fd.append('file', file);
    $('#uc_import_progress').show().find('span').css('width','0');
    $.ajax({url: UC_Admin.ajax_url, method:'POST', data: fd, processData:false, contentType:false})
      .done(function(res){ if(res.success){ runBatch(card); } else { alert(res.data && res.data.message || 'خطا در آپلود'); } })
      .fail(function(){ alert('خطا در آپلود'); });
  });

  function runBatch(card){
    $.post(UC_Admin.ajax_url, { action:'uc_import_codes_batch', nonce: UC_Admin.nonce, card_id: card })
      .done(function(res){
        if(res.success){
          var prog = res.data.progress || 0;
          $('#uc_import_progress span').css('width', prog+'%');
          if(res.data.done){ $('#uc_import_progress span').css('width','100%'); location.reload(); }
          else { setTimeout(function(){ runBatch(card); }, 150); }
        } else {
          alert(res.data && res.data.message || 'خطا در پردازش');
        }
      }).fail(function(){ alert('خطا در پردازش'); });
  }

  // Pricing repeater
  $(document).on('click', '#uc-price-add', function(){
    var tpl = document.getElementById('uc-price-template');
    if (!tpl) return;
    var html = tpl.innerHTML;
    $('#uc-price-rows').append(html);
  });
  $(document).on('click', '.uc-price-remove', function(){
    var $row = $(this).closest('.uc-price-row');
    // Keep at least one row
    if ($('.uc-price-row').length > 1) $row.remove();
  });

  function showScheduleSection(supervisorId){
    var id = supervisorId;
    if (typeof id === 'undefined' || id === null) {
      id = $('#uc-schedule-supervisor').val();
    }
    $('.uc-schedule-section').each(function(){
      var $section = $(this);
      var match = !id || String($section.data('supervisor')) === String(id);
      $section.toggle(match);
    });
  }

  $(document).on('change', '#uc-schedule-supervisor', function(){
    showScheduleSection($(this).val());
  });

  $(function(){
    if ($('#uc-schedule-supervisor').length) {
      showScheduleSection($('#uc-schedule-supervisor').val());
    }
  });

  $(document).on('click', '#uc_sms_test_connection', function(e){
    e.preventDefault();
    if (typeof UC_Admin === 'undefined') return;
    var $btn = $(this);
    var $status = $('#uc_sms_test_connection_status');
    setStatus($status, 'loading', getText('testing', 'در حال بررسی...'));
    $btn.prop('disabled', true);
    $.post(UC_Admin.ajax_url, {
      action: 'uc_sms_test_connection',
      nonce: UC_Admin.sms_nonce,
      gateway: $('#uc_sms_gateway').val(),
      username: $('#uc_sms_username').val(),
      password: $('#uc_sms_password').val(),
      sender_number: $('#uc_sms_sender_number').val()
    }).done(function(res){
      if(res && res.success){
        var msg = (res.data && res.data.message) ? res.data.message : getText('testConnectionSuccess', 'اتصال با موفقیت برقرار شد.');
        setStatus($status, 'success', msg);
      } else {
        var msgErr = (res && res.data && res.data.message) ? res.data.message : getText('testConnectionFailed', 'اتصال برقرار نشد. خطا را بررسی کنید.');
        setStatus($status, 'error', msgErr);
      }
    }).fail(function(xhr){
      var msg = getText('testConnectionFailed', 'اتصال برقرار نشد. خطا را بررسی کنید.');
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
        msg = xhr.responseJSON.data.message;
      }
      setStatus($status, 'error', msg);
    }).always(function(){
      $btn.prop('disabled', false);
    });
  });

  $(document).on('click', '#uc_sms_send_test', function(e){
    e.preventDefault();
    if (typeof UC_Admin === 'undefined') return;
    var $btn = $(this);
    var $status = $('#uc_sms_send_test_status');
    setStatus($status, 'loading', getText('testing', 'در حال بررسی...'));
    $btn.prop('disabled', true);
    $.post(UC_Admin.ajax_url, {
      action: 'uc_sms_send_test',
      nonce: UC_Admin.sms_nonce,
      gateway: $('#uc_sms_gateway').val(),
      username: $('#uc_sms_username').val(),
      password: $('#uc_sms_password').val(),
      sender_number: $('#uc_sms_sender_number').val(),
      pattern_code: $('#uc_sms_default_pattern_code').val(),
      pattern_vars: $('#uc_sms_default_pattern_vars').val(),
      test_phone: $('#uc_sms_test_phone').val(),
      variables: $('#uc_sms_test_variables').val()
    }).done(function(res){
      if(res && res.success){
        var msg = (res.data && res.data.message) ? res.data.message : getText('testSendSuccess', 'پیامک تستی با موفقیت ارسال شد.');
        setStatus($status, 'success', msg);
      } else {
        var msgErr = (res && res.data && res.data.message) ? res.data.message : getText('testSendFailed', 'ارسال پیامک تستی با خطا مواجه شد.');
        setStatus($status, 'error', msgErr);
      }
    }).fail(function(xhr){
      var msg = getText('testSendFailed', 'ارسال پیامک تستی با خطا مواجه شد.');
      if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
        msg = xhr.responseJSON.data.message;
      }
      setStatus($status, 'error', msg);
    }).always(function(){
      $btn.prop('disabled', false);
    });
  });
})(jQuery);
