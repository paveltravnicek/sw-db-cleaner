document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.swdc-details').forEach(function (item) {
    item.addEventListener('toggle', function () {
      if (item.open) {
        item.classList.add('is-open');
      } else {
        item.classList.remove('is-open');
      }

      if (!item.open) {
        return;
      }

      var parentAccordion = item.closest('[data-accordion="true"]');
      if (!parentAccordion) {
        return;
      }

      parentAccordion.querySelectorAll('.swdc-accordion-item[open]').forEach(function (openItem) {
        if (openItem !== item) {
          openItem.open = false;
        }
      });
    });
  });
});
