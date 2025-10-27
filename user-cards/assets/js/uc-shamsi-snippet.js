// Shamsi (Jalali) datepicker implementation used inside the user cards plugin.
// The calendar logic has been rewritten to provide a reliable conversion layer
// for modern browsers while keeping the original fallback implementation for
// legacy environments without Intl Persian calendar support.
;(function(){
  if (window.shamsiDatePickerInit) return;

  const weekDays = ["ش","ی","د","س","چ","پ","ج"];
  const monthNames = ["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"];
  const DAY_IN_MS = 24 * 60 * 60 * 1000;

  const legacyJalaali = (function(){
    const gDaysInMonth = [31,28,31,30,31,30,31,31,30,31,30,31];
    const jDaysInMonth = [31,31,31,31,31,31,30,30,30,30,30,29];

    function div(a, b) {
      return Math.floor(a / b);
    }

    function toJalaali(gy, gm, gd){
      gy = parseInt(gy, 10);
      gm = parseInt(gm, 10);
      gd = parseInt(gd, 10);

      let gy2 = gy - 1600;
      let gm2 = gm - 1;
      let gd2 = gd - 1;

      let gDayNo = 365 * gy2 + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400);

      for (let i = 0; i < gm2; ++i) gDayNo += gDaysInMonth[i];
      if (gm2 > 1 && ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0))) gDayNo += 1;
      gDayNo += gd2;

      let jDayNo = gDayNo - 79;
      const jNp = div(jDayNo, 12053);
      jDayNo %= 12053;

      let jy = 979 + 33 * jNp + 4 * div(jDayNo, 1461);
      jDayNo %= 1461;

      if (jDayNo >= 366) {
        jy += div(jDayNo - 366, 365);
        jDayNo = (jDayNo - 366) % 365;
      }

      let jm;
      for (jm = 0; jm < 11 && jDayNo >= jDaysInMonth[jm]; ++jm) {
        jDayNo -= jDaysInMonth[jm];
      }
      const jd = jDayNo + 1;

      return [jy, jm + 1, jd];
    }

    function toGregorian(jy, jm, jd) {
      jy = parseInt(jy, 10);
      jm = parseInt(jm, 10);
      jd = parseInt(jd, 10);

      jy -= 979;
      jm -= 1;
      jd -= 1;

      let jDayNo = 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4);
      for (let i = 0; i < jm; ++i) jDayNo += jDaysInMonth[i];
      jDayNo += jd;

      let gDayNo = jDayNo + 79;

      let gy = 1600 + 400 * div(gDayNo, 146097);
      gDayNo %= 146097;

      let leap = true;
      if (gDayNo >= 36525) {
        gDayNo--;
        gy += 100 * div(gDayNo, 36524);
        gDayNo %= 36524;

        if (gDayNo >= 365) {
          gDayNo++;
        } else {
          leap = false;
        }
      }

      gy += 4 * div(gDayNo, 1461);
      gDayNo %= 1461;

      if (gDayNo >= 366) {
        leap = false;
        gDayNo--;
        gy += div(gDayNo, 365);
        gDayNo %= 365;
      }

      let gm;
      for (gm = 0; gm < 11; ++gm) {
        const monthLength = gDaysInMonth[gm] + (gm === 1 && leap ? 1 : 0);
        if (gDayNo < monthLength) break;
        gDayNo -= monthLength;
      }

      const gd = gDayNo + 1;

      return [gy, gm + 1, gd];
    }

    function fromDate(date) {
      return toJalaali(date.getFullYear(), date.getMonth() + 1, date.getDate());
    }

    return { toJalaali: toJalaali, fromDate: fromDate, toGregorian: toGregorian };
  })();

  const persianDigits = {
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
    '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
  };

  function normalizeDigits(str){
    return String(str).replace(/[۰-۹٠-٩]/g, function(ch){ return persianDigits[ch] || ch; });
  }

  function pad2(value){
    return ('0' + value).slice(-2);
  }

  function formatJalaaliDate(jy, jm, jd){
    return jy + '/' + pad2(jm) + '/' + pad2(jd);
  }

  const supportsIntl = (function(){
    try {
      if (typeof Intl === 'undefined' || typeof Intl.DateTimeFormat !== 'function') return false;
      const formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric' });
      const parts = formatter.formatToParts(new Date());
      return Array.isArray(parts) && parts.length > 0;
    } catch (err) {
      return false;
    }
  })();

  const intlFormatter = supportsIntl
    ? new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: 'numeric', day: 'numeric' })
    : null;

  function toJalaliParts(date){
    if (!(date instanceof Date) || isNaN(date.getTime())) return null;
    if (intlFormatter){
      try {
        const parts = intlFormatter.formatToParts(date);
        const result = { year: null, month: null, day: null };
        parts.forEach(function(part){
          if (part.type === 'year') result.year = parseInt(normalizeDigits(part.value), 10);
          if (part.type === 'month') result.month = parseInt(normalizeDigits(part.value), 10);
          if (part.type === 'day') result.day = parseInt(normalizeDigits(part.value), 10);
        });
        if (result.year && result.month && result.day) return result;
      } catch (err) {}
    }
    try {
      const values = legacyJalaali.fromDate(date);
      return { year: values[0], month: values[1], day: values[2] };
    } catch (err) {
      return null;
    }
  }

  function jalaliToDate(jy, jm, jd){
    if (intlFormatter){
      const approx = new Date(jy + 621, 0, 1);
      approx.setHours(0, 0, 0, 0);
      const maxDays = 800; // ~2 years coverage on both sides
      for (let offset = 0; offset <= maxDays; offset++){
        const candidate = new Date(approx.getTime() + offset * DAY_IN_MS);
        candidate.setHours(0,0,0,0);
        const parts = toJalaliParts(candidate);
        if (parts && parts.year === jy && parts.month === jm && parts.day === jd) {
          return candidate;
        }
      }
      for (let offset = 1; offset <= maxDays; offset++){
        const candidate = new Date(approx.getTime() - offset * DAY_IN_MS);
        candidate.setHours(0,0,0,0);
        const parts = toJalaliParts(candidate);
        if (parts && parts.year === jy && parts.month === jm && parts.day === jd) {
          return candidate;
        }
      }
    }
    try {
      const g = legacyJalaali.toGregorian(jy, jm, jd);
      const date = new Date(g[0], g[1] - 1, g[2]);
      date.setHours(0,0,0,0);
      return date;
    } catch (err) {
      return null;
    }
  }

  function parseJalaliString(value){
    if (typeof value !== 'string') return null;
    const trimmed = value.trim();
    if (!trimmed) return null;
    const parts = trimmed.split('/');
    if (parts.length !== 3) return null;
    const jy = parseInt(normalizeDigits(parts[0]), 10);
    const jm = parseInt(normalizeDigits(parts[1]), 10);
    const jd = parseInt(normalizeDigits(parts[2]), 10);
    if ([jy, jm, jd].some(isNaN)) return null;
    return jalaliToDate(jy, jm, jd);
  }

  function buildMonthContextIntl(baseDate){
    const safeDate = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
    safeDate.setHours(0,0,0,0);
    const headerParts = toJalaliParts(safeDate);
    if (!headerParts) return null;
    const shYear = headerParts.year;
    const shMonthIndex = headerParts.month;
    const shMonthName = monthNames[shMonthIndex - 1] || '';

    const monthStart = jalaliToDate(shYear, shMonthIndex, 1);
    const nextMonthDate = (shMonthIndex === 12)
      ? jalaliToDate(shYear + 1, 1, 1)
      : jalaliToDate(shYear, shMonthIndex + 1, 1);

    if (!monthStart || !nextMonthDate) return null;

    const days = [];
    for (let time = monthStart.getTime(); time < nextMonthDate.getTime(); time += DAY_IN_MS){
      const current = new Date(time);
      current.setHours(0,0,0,0);
      const parts = toJalaliParts(current);
      if (!parts || parts.year !== shYear || parts.month !== shMonthIndex) break;
      days.push({
        gregorian: current,
        jalaliYear: parts.year,
        jalaliMonth: parts.month,
        jalaliDay: parts.day
      });
    }

    if (!days.length) return null;

    const prevMonthDate = new Date(monthStart.getTime() - DAY_IN_MS);
    prevMonthDate.setHours(0,0,0,0);

    return {
      shYear,
      shMonthIndex,
      shMonthName,
      monthStart,
      days,
      prevMonthDate,
      nextMonthDate
    };
  }

  function buildMonthContextLegacy(baseDate){
    const safeDate = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
    safeDate.setHours(0,0,0,0);
    const headerParts = legacyJalaali.fromDate(safeDate);
    const shYear = headerParts[0];
    const shMonthIndex = headerParts[1];
    const shMonthName = monthNames[shMonthIndex - 1] || '';

    const monthStartParts = legacyJalaali.toGregorian(shYear, shMonthIndex, 1);
    const monthStart = new Date(monthStartParts[0], monthStartParts[1] - 1, monthStartParts[2]);
    monthStart.setHours(0,0,0,0);

    const nextMonthParts = (shMonthIndex === 12)
      ? legacyJalaali.toGregorian(shYear + 1, 1, 1)
      : legacyJalaali.toGregorian(shYear, shMonthIndex + 1, 1);
    const nextMonthDate = new Date(nextMonthParts[0], nextMonthParts[1] - 1, nextMonthParts[2]);
    nextMonthDate.setHours(0,0,0,0);

    const monthLength = Math.round((nextMonthDate.getTime() - monthStart.getTime()) / DAY_IN_MS);
    const days = [];
    for (let day = 1; day <= monthLength; day++) {
      const currentParts = legacyJalaali.toGregorian(shYear, shMonthIndex, day);
      const currentDay = new Date(currentParts[0], currentParts[1] - 1, currentParts[2]);
      currentDay.setHours(0,0,0,0);
      days.push({
        gregorian: currentDay,
        jalaliYear: shYear,
        jalaliMonth: shMonthIndex,
        jalaliDay: day
      });
    }

    const prevMonthDate = new Date(monthStart.getTime() - DAY_IN_MS);
    prevMonthDate.setHours(0,0,0,0);

    return {
      shYear,
      shMonthIndex,
      shMonthName,
      monthStart,
      days,
      prevMonthDate,
      nextMonthDate
    };
  }

  function getJalaliMonthContext(baseDate){
    return buildMonthContextIntl(baseDate) || buildMonthContextLegacy(baseDate);
  }

  function renderShamsiCalendar(targetId, date){
    const inputField = document.getElementById(targetId);
    if (!inputField) return;
    const container = inputField.closest('.shamsi-datepicker-container') || inputField.parentElement;
    let popup = container.querySelector('.shamsi-calendar-popup');
    if (!popup){
      popup = document.createElement('div');
      popup.className = 'shamsi-calendar-popup';
      container.appendChild(popup);
    }

    document.querySelectorAll('.shamsi-calendar-popup').forEach(function(other){
      if (other !== popup) other.style.display = 'none';
    });

    const storedJalaliValue = (inputField.getAttribute('data-jalali') || '').trim();
    const viewDate = (date instanceof Date && !isNaN(date.getTime())) ? new Date(date.getTime()) : new Date();
    viewDate.setHours(0,0,0,0);
    const monthContext = getJalaliMonthContext(viewDate);
    if (!monthContext) return;
    const { shYear, shMonthIndex, shMonthName, monthStart, days, prevMonthDate, nextMonthDate } = monthContext;
    const firstDayOffset = (monthStart.getDay() + 1) % 7; // Saturday = 0
    const todayAtMidnight = new Date();
    todayAtMidnight.setHours(0,0,0,0);

    let body = '<div class="shamsi-calendar-body">';
    weekDays.forEach(function(d){
      body += "<div class='shamsi-calendar-day-name'>" + d + "</div>";
    });
    for (let i = 0; i < firstDayOffset; i++) body += '<div></div>';

    days.forEach(function(dayEntry){
      const dayDate = dayEntry.gregorian;
      const isToday = dayDate.getTime() === todayAtMidnight.getTime();
      let classes = 'shamsi-calendar-day is-selectable';
      if (isToday) classes += ' is-today';
      const displayValue = formatJalaaliDate(dayEntry.jalaliYear, dayEntry.jalaliMonth, dayEntry.jalaliDay);
      if (storedJalaliValue && storedJalaliValue === displayValue) {
        classes += ' is-selected';
      }
      body += '<div class="' + classes + '" data-date="' + displayValue + '" data-gregorian-ms="' + dayDate.getTime() + '">' + dayEntry.jalaliDay + '</div>';
    });
    body += '</div>';

    popup.innerHTML = `
      <div class="shamsi-calendar-header">
        <button type="button" class="shamsi-nav-next">ماه بعد &raquo;</button>
        <span class="shamsi-current-month"> ${shMonthName} ${shYear} </span>
        <button type="button" class="shamsi-nav-prev">&laquo; ماه قبل</button>
      </div>
      ${body}
    `;
    popup.style.display = 'block';

    popup.querySelectorAll('.shamsi-calendar-day.is-selectable').forEach(function(el){
      el.addEventListener('click', function(){
        const displayValue = this.getAttribute('data-date') || '';
        const gregorianMs = parseInt(this.getAttribute('data-gregorian-ms'), 10);
        const gregorianIso = isNaN(gregorianMs) ? '' : new Date(gregorianMs).toISOString();
        inputField.value = displayValue;
        if (gregorianIso) inputField.setAttribute('data-gregorian', gregorianIso);
        inputField.setAttribute('data-jalali', displayValue);
        try {
          inputField.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (err) {
          const ev = document.createEvent('Event');
          ev.initEvent('change', true, false);
          inputField.dispatchEvent(ev);
        }
        setTimeout(function(){ inputField.value = displayValue; }, 0);
        popup.style.display = 'none';
      });
    });

    const prevNav = popup.querySelector('.shamsi-nav-prev');
    const nextNav = popup.querySelector('.shamsi-nav-next');

    if (prevNav){
      prevNav.addEventListener('click', function(){
        renderShamsiCalendar(targetId, prevMonthDate);
      });
    }

    if (nextNav){
      nextNav.addEventListener('click', function(){
        renderShamsiCalendar(targetId, nextMonthDate);
      });
    }
  }

  window.shamsiDatePickerInit = function(){
    document.querySelectorAll('.shamsi-datepicker-field').forEach(function(field){
      field.setAttribute('readonly','readonly');
      const storedJalali = field.getAttribute('data-jalali');
      if (storedJalali) field.value = storedJalali;
      field.addEventListener('click', function(){
        const storedGregorian = this.getAttribute('data-gregorian');
        const storedJalaliValue = this.getAttribute('data-jalali');
        let baseDate = null;
        if (storedGregorian) {
          const parsed = new Date(storedGregorian);
          if (!isNaN(parsed.getTime())) baseDate = parsed;
        }
        if (!baseDate) baseDate = parseJalaliString(storedJalaliValue);
        if (!baseDate) baseDate = new Date();
        baseDate.setHours(0,0,0,0);
        renderShamsiCalendar(this.id, baseDate);
      });
    });
  };

  document.addEventListener('click', function(evt){
    const target = evt.target;
    if (!target) return;
    if (typeof Element !== 'undefined' && !(target instanceof Element)) return;
    if (target.closest('.shamsi-datepicker-container')) return;
    document.querySelectorAll('.shamsi-calendar-popup').forEach(function(popup){
      popup.style.display = 'none';
    });
  });

  document.addEventListener('DOMContentLoaded', function(){
    if (typeof window.shamsiDatePickerInit === 'function') window.shamsiDatePickerInit();
  });
})();
