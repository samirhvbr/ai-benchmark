// CondoDesk page helpers: highlight the notice named in the URL fragment, and show the message a redirect passed along.
(function () {
  "use strict";

  var match = /^#notice-(\d+)$/.exec(window.location.hash);
  if (match) {
    var notice = document.getElementById("notice-" + match[1]);
    if (notice) {
      notice.classList.add("highlight");
      notice.scrollIntoView();
    }
  }

  var message = new URLSearchParams(window.location.search).get("flash");
  var box = document.getElementById("flash");
  if (message && box) {
    box.innerHTML = message;
    box.hidden = false;
  }
})();
