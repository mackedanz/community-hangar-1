/* Empfängt die Pledges vom Lesezeichen auf der RSI-Seite (postMessage) und zeigt die Vorschau. */
(function () {
  "use strict";

  var RSI_ORIGIN = "https://robertsspaceindustries.com";
  var root = document.getElementById("receive");
  if (!root) return;
  var kindLabels = {};
  try {
    kindLabels = JSON.parse(root.getAttribute("data-kind-labels") || "{}");
  } catch (e) {
    kindLabels = {};
  }
  var exportData = null;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function show() {
    root.replaceChildren.apply(root, arguments);
  }

  function message(text, cls) {
    show(el("p", cls || "text-zinc-300", text));
  }

  function sendImport(data, dryRun) {
    return fetch("/api/v1/import/hangar" + (dryRun ? "?dryRun=1" : ""), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(data),
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (body) {
        if (!res.ok) throw new Error((body && body.error) || "Fehler " + res.status);
        return body.plan;
      });
    });
  }

  function done(plan) {
    var total = plan.matched.length + plan.unmatched.length + plan.others.length;
    var a = el("a", "inline-block rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500", "Zu meinem Hangar");
    a.href = "/hangar";
    var wrap = el("div", "space-y-3");
    wrap.appendChild(el("p", "text-green-400", "Fertig: " + total + " Einträge in deinem Hangar."));
    wrap.appendChild(a);
    show(wrap);
  }

  function preview(plan) {
    var wrap = el("div", "space-y-4");
    var p = el("p");
    var b = function (n) { var x = document.createElement("b"); x.textContent = String(n); return x; };
    p.appendChild(b(plan.matched.length));
    p.appendChild(document.createTextNode(" Einträge erkannt"));
    if (plan.unmatched.length > 0) {
      p.appendChild(document.createTextNode(", "));
      p.appendChild(b(plan.unmatched.length));
      p.appendChild(document.createTextNode(" Schiffe nicht im Katalog (werden mit ihrem Namen übernommen)"));
    }
    if (plan.others.length > 0) {
      p.appendChild(document.createTextNode(", dazu "));
      p.appendChild(b(plan.others.length));
      p.appendChild(document.createTextNode(" Upgrades, Paints und sonstige Gegenstände"));
    }
    p.appendChild(document.createTextNode("."));
    if (plan.replaced > 0) {
      p.appendChild(document.createTextNode(
        " Deine " + plan.replaced + " bisher von RSI übernommenen Einträge werden ersetzt; manuell hinzugefügte bleiben erhalten."
      ));
    }
    wrap.appendChild(p);

    var ul = el("ul", "max-h-72 divide-y divide-zinc-800 overflow-y-auto rounded border border-zinc-800 text-sm");
    plan.matched.concat(plan.unmatched).forEach(function (e) {
      var li = el("li", "flex justify-between p-2");
      li.appendChild(el("span", "", e.quantity + "× " + e.name));
      li.appendChild(el("span", "text-zinc-400", e.lti ? "LTI" : ""));
      ul.appendChild(li);
    });
    plan.others.forEach(function (o) {
      var li = el("li", "flex justify-between p-2");
      li.appendChild(el("span", "", o.quantity + "× " + o.name));
      li.appendChild(el("span", "text-zinc-400", kindLabels[o.kind] || o.kind));
      ul.appendChild(li);
    });
    wrap.appendChild(ul);

    var buttons = el("div", "flex gap-3");
    var apply = el("button", "rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500 disabled:opacity-40", "In meinen Hangar übernehmen");
    apply.type = "button";
    apply.addEventListener("click", function () {
      apply.disabled = true;
      apply.textContent = "Übernehme …";
      sendImport(exportData, false).then(function () { done(plan); }, function (err) {
        message(err.message, "text-red-400");
      });
    });
    var cancel = el("button", "rounded bg-zinc-800 px-4 py-2 hover:bg-zinc-700", "Abbrechen");
    cancel.type = "button";
    cancel.addEventListener("click", function () { window.close(); });
    buttons.appendChild(apply);
    buttons.appendChild(cancel);
    wrap.appendChild(buttons);
    show(wrap);
  }

  var opener = window.opener;
  if (!opener) {
    message("Diese Seite wird vom Lesezeichen „Hangar-Sync“ auf deiner RSI-Pledge-Seite geöffnet. Starte den Sync bitte dort.", "text-red-400");
    return;
  }

  message("Warte auf die Daten von der RSI-Seite … Das Lesezeichen liest gerade deine Pledges (unten rechts auf der RSI-Seite siehst du den Fortschritt).");

  /* "Bereit" melden, bis die Daten da sind (das Lesezeichen liest die Seiten erst noch). */
  var ping = setInterval(function () {
    try { opener.postMessage({ type: "ch-ready" }, RSI_ORIGIN); } catch (e) { /* Opener weg */ }
  }, 500);

  window.addEventListener("message", function (event) {
    if (event.origin !== RSI_ORIGIN || event.source !== opener) return;
    if (!event.data || event.data.type !== "ch-export" || exportData) return;
    clearInterval(ping);
    exportData = event.data.data;
    opener.postMessage({ type: "ch-received" }, RSI_ORIGIN);

    message("Daten erhalten, gleiche mit dem Katalog ab …");
    sendImport(exportData, true).then(preview, function (err) {
      message(err.message, "text-red-400");
    });
  });
})();
