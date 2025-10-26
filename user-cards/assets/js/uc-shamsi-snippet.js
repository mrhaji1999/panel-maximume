// Port of user-provided Shamsi datepicker snippet as a plugin asset
;(function(){
  if (window.shamsiDatePickerInit) return;

  const weekDays = ["ش","ی","د","س","چ","پ","ج"];

  const jalaali = (function(){
    const monthNames = ["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"];
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

    return { toJalaali: toJalaali, fromDate: fromDate, toGregorian: toGregorian, monthNames: monthNames };
  })();

  function formatJalaaliDate(jy, jm, jd) {
    const year = ('' + jy);
    const month = ('0' + jm).slice(-2);
    const day = ('0' + jd).slice(-2);
    return year + '/' + month + '/' + day;
  }

  const displayFormatter = (function(){
    try {
      if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
        return new Intl.DateTimeFormat('fa-IR-u-ca-gregory', {
          year: 'numeric',
          month: '2-digit',
          day: '2-digit'
        });
      }
    } catch (err) {}
    return null;
  })();

  function formatDisplayDate(dayDate) {
    if (displayFormatter) {
      try {
        return displayFormatter.format(dayDate);
      } catch (err) {}
    }
    const year = dayDate.getFullYear();
    const month = ('0' + (dayDate.getMonth() + 1)).slice(-2);
    const day = ('0' + dayDate.getDate()).slice(-2);
    return year + '/' + month + '/' + day;
  }

  function parseJalaliString(value) {
    if (typeof value !== 'string') return null;
    const trimmed = value.trim();
    if (!trimmed) return null;
    const parts = trimmed.split('/');
    if (parts.length !== 3) return null;
    const jy = parseInt(parts[0], 10);
    const jm = parseInt(parts[1], 10);
    const jd = parseInt(parts[2], 10);
    if ([jy, jm, jd].some(function(num){ return isNaN(num); })) return null;
    try {
      const gParts = jalaali.toGregorian(jy, jm, jd);
      const date = new Date(gParts[0], gParts[1] - 1, gParts[2]);
      date.setHours(0, 0, 0, 0);
      return date;
    } catch (err) {
      return null;
    }
  }

  function getJalaliMonthContext(baseDate){
    const safeDate = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
    safeDate.setHours(0,0,0,0);
    const headerParts = jalaali.fromDate(safeDate);
    const shYear = headerParts[0];
    const shMonthIndex = headerParts[1];
    const shMonthName = jalaali.monthNames[shMonthIndex - 1] || '';

    const monthStartParts = jalaali.toGregorian(shYear, shMonthIndex, 1);
    const monthStart = new Date(monthStartParts[0], monthStartParts[1] - 1, monthStartParts[2]);
    monthStart.setHours(0,0,0,0);

    const nextMonthParts = (shMonthIndex === 12)
      ? jalaali.toGregorian(shYear + 1, 1, 1)
      : jalaali.toGregorian(shYear, shMonthIndex + 1, 1);
    const nextMonthDate = new Date(nextMonthParts[0], nextMonthParts[1] - 1, nextMonthParts[2]);
    nextMonthDate.setHours(0,0,0,0);

    const days = [];
    let iterator = new Date(monthStart.getTime());
    while (iterator.getTime() < nextMonthDate.getTime()) {
      const currentDay = new Date(iterator.getTime());
      const parts = jalaali.fromDate(currentDay);
      days.push({
        gregorian: currentDay,
        jalaliYear: parts[0],
        jalaliMonth: parts[1],
        jalaliDay: parts[2]
      });
      iterator.setDate(iterator.getDate() + 1);
      iterator.setHours(0,0,0,0);
    }

    const prevMonthDate = new Date(monthStart.getTime());
    prevMonthDate.setDate(prevMonthDate.getDate() - 1);
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

    document.querySelectorAll('.shamsi-calendar-popup').forEach(other => {
      if (other !== popup) {
        other.style.display = 'none';
      }
    });

    const storedJalaliValue = (inputField.getAttribute('data-jalali') || '').trim();
    const viewDate = (date instanceof Date && !isNaN(date.getTime())) ? new Date(date.getTime()) : new Date();
    viewDate.setHours(0,0,0,0);
    const monthContext = getJalaliMonthContext(viewDate);
    const { shYear, shMonthIndex, shMonthName, monthStart, days, prevMonthDate, nextMonthDate } = monthContext;
    const firstDayOffset = (monthStart.getDay() + 1) % 7; // Saturday=0
    const todayGregorian = new Date();
    const todayAtMidnight = new Date(todayGregorian.getFullYear(), todayGregorian.getMonth(), todayGregorian.getDate());
    todayAtMidnight.setHours(0,0,0,0);

    let body = '<div class="shamsi-calendar-body">';
    weekDays.forEach(d => { body += "<div class='shamsi-calendar-day-name'>"+d+"</div>"; });
    for (let i=0;i<firstDayOffset;i++) body += '<div></div>';

    days.forEach(dayEntry => {
      if (dayEntry.jalaliMonth !== shMonthIndex) {
        return;
      }
      const dayDate = dayEntry.gregorian;
      const isToday = dayDate.toDateString() === todayGregorian.toDateString();
      const midnightDate = new Date(dayDate.getFullYear(), dayDate.getMonth(), dayDate.getDate());
      midnightDate.setHours(0,0,0,0);
      const isSelectable = midnightDate.getTime() >= todayAtMidnight.getTime();
      let classes = 'shamsi-calendar-day';
      if (isToday) classes += ' is-today';
      if (isSelectable) classes += ' is-selectable';
      const displayValue = formatJalaaliDate(dayEntry.jalaliYear, dayEntry.jalaliMonth, dayEntry.jalaliDay);
      if (storedJalaliValue && storedJalaliValue === displayValue) {
        classes += ' is-selected';
      }
      body += '<div class="'+classes+'" data-date="'+displayValue+'" data-gregorian="'+dayDate.toISOString()+'">'+dayEntry.jalaliDay+'</div>';
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

    popup.querySelectorAll('.shamsi-calendar-day.is-selectable').forEach(el => {
      el.addEventListener('click', function(){
        const displayValue = this.getAttribute('data-date') || '';
        const gregorianValue = this.getAttribute('data-gregorian') || '';
        inputField.value = displayValue;
        inputField.setAttribute('data-gregorian', gregorianValue);
        inputField.setAttribute('data-jalali', displayValue);
        try {
          inputField.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
          var ev = document.createEvent('Event');
          ev.initEvent('change', true, false);
          inputField.dispatchEvent(ev);
        }
        // Re-assert the Jalali value after change listeners have run (some convert it back to Gregorian)
        setTimeout(function(){ inputField.value = displayValue; }, 0);
        popup.style.display = 'none';
      });
    });

    const prevNav = popup.querySelector('.shamsi-nav-prev');
    const nextNav = popup.querySelector('.shamsi-nav-next');

    if (prevNav) {
      prevNav.addEventListener('click', () => {
        renderShamsiCalendar(targetId, prevMonthDate);
      });
    }

    if (nextNav) {
      nextNav.addEventListener('click', () => {
        renderShamsiCalendar(targetId, nextMonthDate);
      });
    }
  }

  window.shamsiDatePickerInit = function(){
    document.querySelectorAll('.shamsi-datepicker-field').forEach(field => {
      field.setAttribute('readonly','readonly');
      var storedJalali = field.getAttribute('data-jalali');
      if (storedJalali) {
        field.value = storedJalali;
      }
      field.addEventListener('click', function(){
        const storedGregorian = this.getAttribute('data-gregorian');
        const storedJalaliValue = this.getAttribute('data-jalali');
        let baseDate = storedGregorian ? new Date(storedGregorian) : null;
        if (!(baseDate instanceof Date) || isNaN(baseDate.getTime())) {
          baseDate = parseJalaliString(storedJalaliValue);
        }
        if (!(baseDate instanceof Date) || isNaN(baseDate.getTime())) {
          baseDate = new Date();
        }
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
    document.querySelectorAll('.shamsi-calendar-popup').forEach(popup => {
      popup.style.display = 'none';
    });
  });

  document.addEventListener('DOMContentLoaded', function(){
    if (typeof window.shamsiDatePickerInit === 'function') window.shamsiDatePickerInit();
  });
})();

