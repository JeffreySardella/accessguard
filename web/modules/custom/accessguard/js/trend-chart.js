/**
 * @file
 * Pointer-hover tooltips for the AccessGuard Trends SVG chart.
 *
 * Enhancement only: the chart's role=img title/desc and the data table below
 * carry the same information for keyboard and assistive-tech users.
 */
(function (Drupal, once) {
  Drupal.behaviors.accessguardTrendChart = {
    attach: function (context) {
      once('ag-trend-chart', '.ag-trend-chart', context).forEach(function (svg) {
        var wrapper = svg.parentNode;
        var tip = document.createElement('div');
        tip.className = 'ag-trend-tooltip';
        tip.hidden = true;
        wrapper.appendChild(tip);

        svg.querySelectorAll('.ag-trend-point').forEach(function (point) {
          point.addEventListener('mouseenter', function () {
            tip.textContent =
              point.getAttribute('data-date') + ': ' +
              point.getAttribute('data-series') + ' ' +
              point.getAttribute('data-count');
            var mark = point.getBoundingClientRect();
            var box = wrapper.getBoundingClientRect();
            tip.style.left = (mark.left - box.left + mark.width / 2) + 'px';
            tip.style.top = (mark.top - box.top) + 'px';
            tip.hidden = false;
          });
          point.addEventListener('mouseleave', function () {
            tip.hidden = true;
          });
        });
      });
    }
  };
})(Drupal, once);
