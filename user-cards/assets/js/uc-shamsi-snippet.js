// Port of user-provided Shamsi datepicker snippet as a plugin asset
;(function(){
  if (window.shamsiDatePickerInit) return;

  const weekDays = ["ش","ی","د","س","چ","پ","ج"];

  // Very simplified mapping, for proper Jalali use a dedicated lib
  const jalaali = {
    toJalaali: function(gy, gm, gd){
      const names = ["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"];
      const today = new Date();
      const year = today.getFullYear() - 621;
      const monthIndex = (today.getMonth() + 10) % 12;
      return [year, monthIndex + 1, gd, names[monthIndex]];
    }
  };

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

    const [shYear, shMonthIndex, shDay, shMonthName] = jalaali.toJalaali(date.getFullYear(), date.getMonth()+1, date.getDate());
    const daysInMonth = new Date(date.getFullYear(), date.getMonth()+1, 0).getDate();
    const firstDayOfMonth = new Date(date.getFullYear(), date.getMonth(), 1).getDay();
    const firstDayOffset = (firstDayOfMonth + 1) % 7; // Saturday=0
    const todayGregorian = new Date();
    const todayAtMidnight = new Date(todayGregorian.getFullYear(), todayGregorian.getMonth(), todayGregorian.getDate());

    let body = '<div class="shamsi-calendar-body">';
    weekDays.forEach(d => { body += "<div class='shamsi-calendar-day-name'>"+d+"</div>"; });
    for (let i=0;i<firstDayOffset;i++) body += '<div></div>';

    for (let day=1; day<=daysInMonth; day++){
      const dayDate = new Date(date.getFullYear(), date.getMonth(), day);
      const isToday = dayDate.toDateString() === todayGregorian.toDateString();
      const dayAtMidnight = new Date(date.getFullYear(), date.getMonth(), day).setHours(0,0,0,0);
      const isSelectable = dayAtMidnight >= todayAtMidnight.getTime();
      let classes = 'shamsi-calendar-day';
      if (isToday) classes += ' is-today';
      if (isSelectable) classes += ' is-selectable';
      const displayValue = formatDisplayDate(dayDate);
      body += '<div class="'+classes+'" data-date="'+displayValue+'" data-gregorian="'+dayDate.toISOString()+'">'+day+'</div>';
    }
    body += '</div>';

    popup.innerHTML = `
      <div class="shamsi-calendar-header">
        <button type="button" class="shamsi-nav-next" disabled>ماه بعد &raquo;</button>
        <span class="shamsi-current-month"> ${shMonthName} ${shYear} </span>
        <button type="button" class="shamsi-nav-prev" disabled>&laquo; ماه قبل</button>
      </div>
      ${body}
    `;
    popup.style.display = 'block';

    popup.querySelectorAll('.shamsi-calendar-day.is-selectable').forEach(el => {
      el.addEventListener('click', function(){
        const displayValue = this.getAttribute('data-date') || '';
        inputField.value = displayValue;
        inputField.setAttribute('data-gregorian', this.getAttribute('data-gregorian'));
        try { inputField.dispatchEvent(new Event('change', { bubbles: true })); } catch(e) { var ev = document.createEvent('Event'); ev.initEvent('change', true, false); inputField.dispatchEvent(ev); }
        popup.style.display = 'none';
      });
    });

    popup.querySelector('.shamsi-nav-prev').addEventListener('click', () => console.warn('Nav disabled in demo'));
    popup.querySelector('.shamsi-nav-next').addEventListener('click', () => console.warn('Nav disabled in demo'));
  }

  window.shamsiDatePickerInit = function(){
    document.querySelectorAll('.shamsi-datepicker-field').forEach(field => {
      field.setAttribute('readonly','readonly');
      field.addEventListener('click', function(){
        renderShamsiCalendar(this.id, new Date());
      });
    });
  };

  document.addEventListener('DOMContentLoaded', function(){
    if (typeof window.shamsiDatePickerInit === 'function') window.shamsiDatePickerInit();
  });
})();

