let adminUsers = [];

function roleLabel(role) {
  return role === "superadmin" ? "Superadmin" : role === "admin" ? "Admin" : "Staff";
}

function rolePillClass(role) {
  return role === "superadmin" ? "pill-maroon" : role === "admin" ? "pill-info" : "pill-slate";
}

async function renderUsersAdmin() {
  const container = document.getElementById("users-list");
  try {
    adminUsers = await window.API.get("api/users/list.php");
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load: ${err.message}</p>`;
    return;
  }

  container.innerHTML = adminUsers.map(u => `
    <div class="dossier-card p-4 flex items-start justify-between flex-wrap gap-3 ${u.is_active ? "" : "opacity-60"}">
      <div class="min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="font-semibold text-sm text-ink-900">${u.full_name}</span>
          <span class="pill ${rolePillClass(u.role)}">${roleLabel(u.role)}</span>
          ${!u.is_active ? `<span class="pill pill-slate">Deactivated</span>` : ""}
          ${u.is_locked ? `<span class="pill pill-brass"><i class="fa-solid fa-lock text-[10px]"></i>Locked out</span>` : ""}
          ${u.mfa_enabled ? `<span class="pill pill-forest"><i class="fa-solid fa-shield-halved text-[10px]"></i>Sign-in code</span>` : ""}
        </div>
        <p class="text-xs text-slate-500 font-mono mt-0.5">${u.username}${u.email ? ` &middot; ${u.email}` : ""}</p>
        ${u.is_locked ? `<p class="text-[11px] text-brass-700 mt-1">Locked after repeated failed sign-ins until ${new Date(u.locked_until).toLocaleTimeString()} — clear it below if this is the account holder asking.</p>` : ""}
      </div>
      <div class="flex gap-2 shrink-0 flex-wrap justify-end">
        <button data-edit-user="${u.id}" class="btn-outline text-xs !py-1.5"><i class="fa-solid fa-pen mr-1"></i>Edit</button>
        ${u.is_locked ? `<button data-unlock="${u.id}" class="btn-outline text-xs !py-1.5"><i class="fa-solid fa-lock-open mr-1"></i>Unlock</button>` : ""}
        ${u.is_active
          ? (u.id !== window.CURRENT_USER.id
              ? `<button data-set-active="${u.id}" data-value="0" class="btn-outline text-xs !py-1.5 !border-maroon-600 !text-maroon-700">Deactivate</button>`
              : `<span class="text-[11px] text-slate-400 self-center">This is you</span>`)
          : `<button data-set-active="${u.id}" data-value="1" class="btn-outline text-xs !py-1.5 !border-forest-600 !text-forest-700"><i class="fa-solid fa-rotate-left mr-1"></i>Reactivate</button>`}
      </div>
    </div>
  `).join("") || `<p class="text-sm text-slate-400 text-center py-10">No accounts yet.</p>`;

  document.querySelectorAll("[data-edit-user]").forEach(btn =>
    btn.addEventListener("click", () => openUserEditModal(parseInt(btn.dataset.editUser, 10)))
  );

  document.querySelectorAll("[data-unlock]").forEach(btn =>
    btn.addEventListener("click", async () => {
      try {
        await window.API.post("api/users/update.php", { id: parseInt(btn.dataset.unlock, 10), unlock: true });
        renderUsersAdmin();
      } catch (err) {
        alert(`Could not unlock: ${err.message}`);
      }
    })
  );

  document.querySelectorAll("[data-set-active]").forEach(btn =>
    btn.addEventListener("click", async () => {
      const activating = btn.dataset.value === "1";
      const msg = activating
        ? "Reactivate this account? They will be able to sign in again."
        : "Deactivate this account? They will no longer be able to sign in, but their audit history is kept.";
      if (!confirm(msg)) return;
      try {
        await window.API.post("api/users/update.php", {
          id: parseInt(btn.dataset.setActive, 10),
          is_active: activating ? 1 : 0,
        });
        renderUsersAdmin();
      } catch (err) {
        alert(`Could not save: ${err.message}`);
      }
    })
  );
}

function openUserEditModal(userId) {
  const u = adminUsers.find(x => x.id === userId);
  if (!u) return;
  const esc = (s) => (s || "").replace(/"/g, "&quot;");
  const mfaIsAvailable = u.mfa_enabled !== null && u.mfa_enabled !== undefined;
  const mfaIsEnabled = Number(u.mfa_enabled) === 1;

  document.getElementById("edit-user-modal-root").innerHTML = `
    <div id="edit-user-backdrop" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(22,36,61,0.55)">
      <div class="dossier-card w-full max-w-lg p-6 max-h-[88vh] overflow-y-auto" style="background:#FBFAF5" onclick="event.stopPropagation()">
        <div class="flex items-start justify-between gap-3 mb-4">
          <div>
            <h3 class="font-display text-base text-ink-900">Edit account</h3>
            <p class="text-xs text-slate-500 mt-0.5">Every change here is written to the audit trail.</p>
          </div>
          <button id="edit-user-close" class="text-slate-400 hover:text-ink-800"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form id="edit-user-form" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Username</label>
              <input name="username" value="${esc(u.username)}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Role</label>
              <select name="role" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm">
                <option value="staff" ${u.role === "staff" ? "selected" : ""}>Staff (councilor/legislative staff)</option>
                <option value="admin" ${u.role === "admin" ? "selected" : ""}>Admin</option>
                ${window.CURRENT_USER?.role === "superadmin" ? `<option value="superadmin" ${u.role === "superadmin" ? "selected" : ""}>Superadmin</option>` : ""}
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Full name</label>
            <input name="full_name" value="${esc(u.full_name)}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Email</label>
            <input name="email" type="email" value="${esc(u.email)}" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            <p class="text-[11px] text-slate-400 mt-1">Reset codes, sign-in codes, and meeting notices all go here. It has to be a mailbox this person can open.</p>
          </div>

          <div class="border-t border-[--line-200] pt-3">
            <label class="flex items-center gap-2 text-sm text-ink-800">
              <input type="checkbox" name="mfa_enabled" ${mfaIsEnabled ? "checked" : ""} ${mfaIsAvailable ? "" : "disabled"} class="rounded border-[--line-200]" />
              Require an emailed sign-in code (second factor)
            </label>
            <p class="text-[11px] text-slate-400 mt-1">Admin accounts always require one. Turning it on for staff is recommended for anyone who can confirm priorities.${mfaIsAvailable ? "" : " MFA controls are unavailable until the round-8 database migration is applied."}</p>
          </div>

          <div class="border-t border-[--line-200] pt-3">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Set a new password <span class="font-normal text-slate-400">(leave blank to keep the current one)</span></label>
            <input name="password" type="password" autocomplete="new-password" class="w-full border border-[--line-200] rounded-lg px-3 py-2 text-sm" />
            <p class="text-[11px] text-slate-400 mt-1">At least 8 characters with a letter and a number. Tell the holder to change it themselves afterwards — you will know this password until they do.</p>
          </div>

          <p id="edit-user-error" class="hidden text-xs text-maroon-700"></p>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" id="edit-user-cancel" class="btn-outline text-xs">Cancel</button>
            <button type="submit" class="btn-primary text-xs">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  `;

  const close = () => { document.getElementById("edit-user-modal-root").innerHTML = ""; };
  document.getElementById("edit-user-backdrop").addEventListener("click", close);
  document.getElementById("edit-user-close").addEventListener("click", close);
  document.getElementById("edit-user-cancel").addEventListener("click", close);

  document.getElementById("edit-user-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const errorEl = document.getElementById("edit-user-error");
    errorEl.classList.add("hidden");

    const payload = {
      id: u.id,
      username: fd.get("username"),
      full_name: fd.get("full_name"),
      email: fd.get("email"),
      role: fd.get("role"),
    };
    // Older databases do not have the round-8 MFA column. Also avoid sending
    // an unchanged MFA value: the server quite correctly rejects turning MFA
    // off for an admin, but that should not block an unrelated name/email edit.
    const mfaIsAvailable = u.mfa_enabled !== null && u.mfa_enabled !== undefined;
    const mfaValue = fd.get("mfa_enabled") ? 1 : 0;
    if (mfaIsAvailable && mfaValue !== (Number(u.mfa_enabled) === 1 ? 1 : 0)) {
      payload.mfa_enabled = mfaValue;
    }
    if (fd.get("password")) payload.password = fd.get("password");

    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = "Saving…";

    try {
      await window.API.post("api/users/update.php", payload);
      close();
      renderUsersAdmin();
    } catch (err) {
      submitBtn.disabled = false;
      submitBtn.textContent = "Save changes";
      errorEl.textContent = err.message;
      errorEl.classList.remove("hidden");
    }
  });
}

document.getElementById("new-user-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const payload = Object.fromEntries(fd);
  payload.mfa_enabled = fd.get("mfa_enabled") ? 1 : 0;
  const errorEl = document.getElementById("new-user-error");
  errorEl.classList.add("hidden");
  try {
    await window.API.post("api/users/create.php", payload);
    e.target.reset();
    document.getElementById("new-user-panel").classList.add("hidden");
    renderUsersAdmin();
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
});

document.addEventListener("DOMContentLoaded", renderUsersAdmin);
