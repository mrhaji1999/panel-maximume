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
  var currentCardId = null,
      selectedHour = null,
      selectedTimeLabel = '',
      selectedDateIso = '',
      validatedCode = '';

  function getApiBase(){
    if (window.UC_Ajax && UC_Ajax.api_url) {
      return UC_Ajax.api_url.replace(/\/?$/, '');
    }
    var origin = (window.location && window.location.origin) ? window.location.origin : '';
    return origin + '/wp-json/user-cards-bridge/v1';
  }

  function resolveHour($el){
    if (!$el || !$el.length) return NaN;
    var dataHour = $el.data('hour');
    if (dataHour !== undefined && dataHour !== null && dataHour !== '') {
      var parsed = parseInt(dataHour, 10);
      if (!isNaN(parsed)) return parsed;
    }
    var label = ($el.data('time') || $el.text() || '').toString();
    var match = label.match(/\d{1,2}/);
    return match ? parseInt(match[0], 10) : NaN;
  }

  function availabilityMessageTarget($modal){
    var $target = $modal.find('.uc-step-3 .uc-inline-msg');
    if (!$target.length) {
      $target = $modal.find('.uc-step-3').prev('.uc-inline-msg');
    }
    return $target;
  }

  var weekdayNames = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'];

  function parseNumber(value) {
    var parsed = parseInt(value, 10);
    return isNaN(parsed) ? 0 : parsed;
  }

  function getWeekdayName(index) {
    var parsed = parseInt(index, 10);
    if (isNaN(parsed) || parsed < 0 || parsed > 6) {
      return '';
    }
    return weekdayNames[parsed] || '';
  }

  function formatHourLabel(hour) {
    var parsed = parseInt(hour, 10);
    if (isNaN(parsed) || parsed < 0) {
      return '';
    }
    return (parsed < 10 ? '0' + parsed : parsed) + ':00';
  }

  function formatUsageMessage(used, capacity, remaining) {
    var template = (UC_Ajax && UC_Ajax.i18n && UC_Ajax.i18n.availabilityUsage)
      ? UC_Ajax.i18n.availabilityUsage
      : 'رزرو شده {used} از {capacity} (باقی‌مانده {remaining})';

    return template
      .replace('{used}', used)
      .replace('{capacity}', capacity)
      .replace('{remaining}', remaining);
  }

  function ucFetchAvailability() {
    var $m = $('#uc-card-modal');
    var $input = $m.find('#uc-date-input');
    var isoFull = ($input.attr('data-gregorian') || '').toString();
    var isoDate = isoFull ? isoFull.split('T')[0] : '';
    var $msg = availabilityMessageTarget($m);
    var $summary = $m.find('.uc-availability-summary');
    var jalaliDisplayValue = ($input.attr('data-jalali') || ($input.val && $input.val() ? $input.val() : '')).toString().trim();
    if (jalaliDisplayValue) {
      $input.val(jalaliDisplayValue);
    }

    if (!currentCardId) {
      return;
    }

    if (!isoDate) {
      selectedDateIso = '';
      if ($msg.length && $input.val()) {
        $msg.text(UC_Ajax.i18n.invalidDate || '');
      }
      $m.find('.uc-time').each(function(){
        $(this).prop('disabled', true).removeClass('active').addClass('is-disabled');
      }).removeAttr('data-used data-capacity data-remaining title');
      if ($summary.length) {
        $summary.empty().removeClass('empty');
      }
      selectedHour = null;
      selectedTimeLabel = '';
      return;
    }

    selectedDateIso = isoDate;
    $msg.text('');
    if ($summary.length) {
      $summary.empty().removeClass('empty');
    }
    $m.find('.uc-step-3').addClass('uc-loading');

    var endpoint = getApiBase() + '/availability/' + encodeURIComponent(currentCardId) + '?date=' + encodeURIComponent(isoDate);
    var targetWeekday = null;
    var jsDate = new Date(isoDate + 'T00:00:00');
    if (!isNaN(jsDate.getTime())) {
      targetWeekday = jsDate.getDay();
    }

    $.getJSON(endpoint).done(function(res){
      var slots = [];
      if (res && res.success && res.data && Array.isArray(res.data.slots)) {
        slots = res.data.slots;
      } else if (res && Array.isArray(res.slots)) {
        slots = res.slots;
      }

      var grouped = {};
      slots.forEach(function(slot){
        var key = 'unknown';
        if (slot && Object.prototype.hasOwnProperty.call(slot, 'weekday')) {
          var wk = parseInt(slot.weekday, 10);
          if (!isNaN(wk)) {
            key = wk;
          }
        }
        if (!grouped[key]) {
          grouped[key] = [];
        }
        grouped[key].push(slot);
      });

      var slotsForDay = slots;
      if (targetWeekday !== null) {
        if (Object.prototype.hasOwnProperty.call(grouped, targetWeekday)) {
          slotsForDay = grouped[targetWeekday];
        } else if (Object.prototype.hasOwnProperty.call(grouped, 'unknown')) {
          slotsForDay = grouped.unknown;
        } else {
          slotsForDay = [];
        }
      }

      var map = {};
      slotsForDay.forEach(function(slot){
        if (!slot) {
          return;
        }
        var hour = parseInt(slot.hour, 10);
        if (isNaN(hour)) {
          return;
        }
        if (!map[hour]) {
          map[hour] = slot;
          return;
        }
        var currentRemaining = parseNumber(map[hour].remaining);
        var candidateRemaining = parseNumber(slot.remaining);
        if (candidateRemaining > currentRemaining) {
          map[hour] = slot;
        }
      });

      $m.find('.uc-time').each(function(){
        var $btn = $(this);
        var baseLabel = $btn.data('label');
        if (!baseLabel) {
          baseLabel = ($btn.data('time') || $btn.text() || '').toString();
          $btn.data('label', baseLabel);
        }
        $btn.text(baseLabel);
        $btn.removeAttr('data-used data-capacity data-remaining title');
        var hour = resolveHour($btn);
        var slot = map[hour];
        if (slot) {
          var capacity = parseNumber(slot.capacity);
          var used = Object.prototype.hasOwnProperty.call(slot, 'used') ? parseNumber(slot.used) : 0;
          var remaining = Object.prototype.hasOwnProperty.call(slot, 'remaining') ? parseNumber(slot.remaining) : 0;
          if (!Object.prototype.hasOwnProperty.call(slot, 'used') && capacity > 0) {
            used = Math.max(0, capacity - remaining);
          }
          if (!Object.prototype.hasOwnProperty.call(slot, 'remaining')) {
            remaining = Math.max(0, capacity - used);
          }
          var isFull = slot.is_full === true || slot.is_full === 1 || slot.is_full === '1';
          var hasCapacity = capacity > 0 && remaining > 0 && !isFull;
          if (capacity > 0) {
            $btn.text(baseLabel + ' • ' + used + '/' + capacity);
          }
          if (hasCapacity) {
            $btn.prop('disabled', false).removeClass('is-disabled');
            $btn.attr('title', formatUsageMessage(used, capacity, remaining));
          } else {
            $btn.prop('disabled', true).addClass('is-disabled');
            $btn.removeClass('active');
            if (selectedHour === hour) {
              selectedHour = null;
              selectedTimeLabel = '';
            }
          }
          $btn.attr('data-used', used);
          $btn.attr('data-capacity', capacity);
          $btn.attr('data-remaining', remaining);
        } else {
          $btn.prop('disabled', true).addClass('is-disabled');
          $btn.removeClass('active');
          if (selectedHour === hour) {
            selectedHour = null;
            selectedTimeLabel = '';
          }
        }
      });

      if ($summary.length) {
        var keys = Object.keys(map);
        if (!keys.length) {
          var noDataText = (UC_Ajax && UC_Ajax.i18n && UC_Ajax.i18n.availabilityNoData)
            ? UC_Ajax.i18n.availabilityNoData
            : 'برای این تاریخ ظرفیتی ثبت نشده است.';
          $summary.text(noDataText).addClass('empty');
        } else {
          keys.sort(function(a, b){ return parseInt(a, 10) - parseInt(b, 10); });
          var weekdayLabel = '';
          if (targetWeekday !== null) {
            weekdayLabel = getWeekdayName(targetWeekday);
          }
          if (!weekdayLabel && slotsForDay.length) {
            weekdayLabel = getWeekdayName(slotsForDay[0] && slotsForDay[0].weekday);
          }
          var headerLabel = (UC_Ajax && UC_Ajax.i18n && UC_Ajax.i18n.availabilityDayLabel)
            ? UC_Ajax.i18n.availabilityDayLabel
            : 'روز انتخابی:';
          var headerParts = [];
          if (weekdayLabel) {
            headerParts.push(weekdayLabel);
          }
          if (jalaliDisplayValue) {
            headerParts.push(jalaliDisplayValue);
          } else if (selectedDateIso) {
            headerParts.push(selectedDateIso);
          }
          var headerText = headerLabel;
          if (headerParts.length) {
            headerText += ' ' + headerParts.join(' - ');
          }

          var summaryHtml = '<strong>' + headerText + '</strong><ul>';
          keys.forEach(function(hourKey){
            var slotData = map[hourKey];
            if (!slotData) {
              return;
            }
            var capacityValue = parseNumber(slotData.capacity);
            var usedValue = Object.prototype.hasOwnProperty.call(slotData, 'used')
              ? parseNumber(slotData.used)
              : Math.max(0, capacityValue - parseNumber(slotData.remaining));
            var remainingValue = Object.prototype.hasOwnProperty.call(slotData, 'remaining')
              ? parseNumber(slotData.remaining)
              : Math.max(0, capacityValue - usedValue);
            summaryHtml += '<li>' + formatHourLabel(hourKey) + ' - ' + formatUsageMessage(usedValue, capacityValue, remainingValue) + '</li>';
          });
          summaryHtml += '</ul>';
          $summary.removeClass('empty').html(summaryHtml);
        }
      }
    }).fail(function(jqXHR){
      var message = UC_Ajax.i18n.serverError;
      if (jqXHR && jqXHR.responseJSON) {
        if (jqXHR.responseJSON.message) {
          message = jqXHR.responseJSON.message;
        } else if (jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
          message = jqXHR.responseJSON.data.message;
        }
      }
      if ($msg.length) {
        $msg.text(message);
      } else {
        alert(message);
      }
      if ($summary.length) {
        $summary.text(message).addClass('empty');
      }
      $m.find('.uc-time').each(function(){
        $(this).prop('disabled', true).removeClass('active').addClass('is-disabled');
      }).removeAttr('data-used data-capacity data-remaining title');
      selectedHour = null;
      selectedTimeLabel = '';
    }).always(function(){
      $m.find('.uc-step-3').removeClass('uc-loading');
    });
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
    selectedHour = null;
    selectedTimeLabel = '';
    selectedDateIso = '';
    $('#uc-date-input').val('').attr('data-gregorian','').removeAttr('data-jalali');
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
        setTimeout(function(){ initUserSnippet(); }, 0);
		 setTimeout(function(){ ucFetchAvailability(); }, 10);      } else {
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
    selectedTimeLabel = ($(this).data('time') || $(this).text() || '').toString();
    var hour = resolveHour($(this));
    selectedHour = isNaN(hour) ? null : hour;
  });
  // Submit form
  $(document).on('click', '[data-action="submit-form"]', function(){
    var $m = $('#uc-card-modal');
    var date = ($m.find('#uc-date-input').val() || '').trim();
    var $msg = availabilityMessageTarget($m);
    if(!validatedCode){ $m.find('.uc-step-3').prev('.uc-inline-msg').text(UC_Ajax.i18n.invalidCode); return; }
    if(!date || !selectedDateIso || selectedHour === null){
      if ($msg.length) { $msg.text(UC_Ajax.i18n.invalidDate || UC_Ajax.i18n.serverError); }
      alert('لطفا تاریخ و ساعت را انتخاب کنید.');
      return;
    }
    var payload = {
      action:'uc_submit_form',
      _wpnonce: UC_Ajax.nonce,
      card_id: currentCardId,
      code: validatedCode,
      date: date,
      time: selectedTimeLabel,
      reservation_date: selectedDateIso,
      slot_hour: selectedHour,
      hour: selectedHour
    };
    $m.find('.uc-step-3').addClass('uc-loading');
    $.ajax({
      url: UC_Ajax.ajax_url,
      method: 'POST',
      data: payload,
      dataType: 'json'
    }).done(function(res){
      if(res && res.success){
        ucSetStep(4);
        var msg = 'ثبت شد. \nکد سوپرایز شما: ' + (res.data && res.data.surprise || '');
        $m.find('.uc-step-4 .uc-success').text(msg);
      } else {
        var message = (res && res.data && res.data.message) ? res.data.message : UC_Ajax.i18n.serverError;
        alert(message);
      }
    }).fail(function(jqXHR){
      var message = UC_Ajax.i18n.serverError;
      if (jqXHR && jqXHR.status === 409) {
        message = UC_Ajax.i18n.slotFull || UC_Ajax.i18n.serverError;
      } else if (jqXHR && jqXHR.responseJSON) {
        if (jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
          message = jqXHR.responseJSON.data.message;
        } else if (jqXHR.responseJSON.message) {
          message = jqXHR.responseJSON.message;
        }
      }
      alert(message);
      if ($msg.length) { $msg.text(message); }
    }).always(function(){ $m.find('.uc-step-3').removeClass('uc-loading'); });
  });
})(jQuery);
