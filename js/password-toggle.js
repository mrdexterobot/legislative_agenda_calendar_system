/* ==========================================================================
   password-toggle.js — adds a show/hide control to every password field.

   Written as one self-attaching script rather than markup repeated on each
   form, so a password field added to any page later gets the control for
   free. A MutationObserver covers fields that appear inside modals rendered
   after load (e.g. the admin "create account" panel).
   ========================================================================== */

(function () {
  function attach(input) {
    if (input.dataset.pwToggle === "1") return;
    input.dataset.pwToggle = "1";

    const wrap = document.createElement("div");
    wrap.style.position = "relative";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.style.paddingRight = "2.6rem";

    const btn = document.createElement("button");
    btn.type = "button";
    btn.tabIndex = 0;
    btn.setAttribute("aria-label", "Show password");
    btn.style.cssText =
      "position:absolute;right:.65rem;top:50%;transform:translateY(-50%);" +
      "background:none;border:0;padding:2px;line-height:1;cursor:pointer;" +
      "color:var(--slate-500,#6B7280);font-size:13px;";
    btn.innerHTML = '<i class="fa-regular fa-eye"></i>';

    btn.addEventListener("click", () => {
      const showing = input.type === "text";
      input.type = showing ? "password" : "text";
      btn.innerHTML = showing
        ? '<i class="fa-regular fa-eye"></i>'
        : '<i class="fa-regular fa-eye-slash"></i>';
      btn.setAttribute("aria-label", showing ? "Show password" : "Hide password");
      input.focus();
    });

    wrap.appendChild(btn);
  }

  function scan(root) {
    (root || document).querySelectorAll('input[type="password"]').forEach(attach);
  }

  document.addEventListener("DOMContentLoaded", () => {
    scan(document);
    new MutationObserver((records) => {
      records.forEach((r) =>
        r.addedNodes.forEach((n) => {
          if (n.nodeType !== 1) return;
          if (n.matches && n.matches('input[type="password"]')) attach(n);
          else scan(n);
        })
      );
    }).observe(document.body, { childList: true, subtree: true });
  });
})();
