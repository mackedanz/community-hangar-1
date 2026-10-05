/* Gemeinsames Verhalten aller Seiten: Hell/Dunkel-Umschalter und das Orga-Menü. */
(function () {
  function theme() {
    return document.documentElement.dataset.theme === "light" ? "light" : "dark";
  }

  function paintToggles() {
    var t = theme();
    document.querySelectorAll("[data-theme-toggle]").forEach(function (b) {
      b.textContent = t === "dark" ? "☀ Hell" : "☾ Dunkel";
      var label = t === "dark" ? "Zum hellen Modus wechseln" : "Zum dunklen Modus wechseln";
      b.title = label;
      b.setAttribute("aria-label", label);
    });
  }

  document.addEventListener("click", function (e) {
    var toggle = e.target.closest && e.target.closest("[data-theme-toggle]");
    if (toggle) {
      var next = theme() === "dark" ? "light" : "dark";
      document.documentElement.dataset.theme = next;
      try {
        localStorage.setItem("theme", next);
      } catch (err) {
        /* Speichern nicht möglich (z. B. privater Modus): Wahl gilt nur für diese Seite. */
      }
      paintToggles();
    }
  });

  // Orga-Menü: schließt nach der Auswahl und beim Klick daneben
  document.addEventListener("mousedown", function (e) {
    document.querySelectorAll("details[data-org-menu][open]").forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute("open");
    });
  });
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("details[data-org-menu] a");
    if (a) a.closest("details").removeAttribute("open");
  });

  // Einklappbare Bereiche: <button data-collapse="#id"> blendet das Element mit dieser ID ein/aus
  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest("[data-collapse]");
    if (!btn) return;
    var target = document.querySelector(btn.getAttribute("data-collapse"));
    if (!target) return;
    var open = target.classList.toggle("hidden") === false;
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    var arrow = btn.querySelector("[data-arrow]");
    if (arrow) arrow.textContent = open ? "▾" : "▸";
  });

  // In die Zwischenablage kopieren: <button data-copy="#id">
  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest("[data-copy]");
    if (!btn) return;
    var src = document.querySelector(btn.getAttribute("data-copy"));
    if (!src || !navigator.clipboard) return;
    var original = btn.getAttribute("data-label") || btn.textContent;
    btn.setAttribute("data-label", original);
    navigator.clipboard.writeText(src.textContent.trim()).then(function () {
      btn.textContent = "Kopiert ✓";
      setTimeout(function () { btn.textContent = original; }, 2000);
    }, function () { /* Zugriff verweigert: nichts tun */ });
  });

  // Das Lesezeichen soll gezogen, nicht hier angeklickt werden
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("[data-bookmarklet]");
    if (!a) return;
    e.preventDefault();
    alert("Nicht hier klicken, sondern mit gedrückter Maustaste in die Lesezeichenleiste ziehen. Danach klickst du das Lesezeichen auf deiner RSI-Pledge-Seite an.");
  });

  // Rückfrage vor dem Absenden: <form data-confirm="Wirklich …?">
  document.addEventListener("submit", function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute("data-confirm");
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  paintToggles();
})();
