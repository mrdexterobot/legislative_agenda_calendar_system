async function loadSchedulingEvents() {
  const el = document.getElementById("hub-scheduling-events");
  try {
    const events = await window.API.get("api/integration/events.php");
    const relevant = events.filter(e => e.event_type === "proposed_schedule_sent" || e.event_type === "confirmation_received");
    el.innerHTML = relevant.length
      ? relevant.map(renderEventRow).join("")
      : `<p class="text-xs text-slate-400">No exchanges yet — schedule a session in Calendar Scheduling to see one appear here.</p>`;
  } catch (err) {
    el.innerHTML = `<p class="text-xs text-maroon-700">Could not load: ${err.message}</p>`;
  }
}

async function loadAgendaPrepPreview() {
  const el = document.getElementById("hub-agenda-prep");
  const pushBtn = document.getElementById("hub-push-agenda-btn");
  try {
    const items = await window.API.get("api/agenda-items/list.php");
    // Only items that are actually eligible to push are shown: confirmed,
    // not transmitted, not past 3rd reading, and not already on an active
    // schedule. Items from a finished schedule may be pushed again.
    const eligible = items.filter(i => {
      const passedThirdReading = (i.readings || []).some(r => r.stage === "3rd Reading — Passed");
      const wasPreviouslyScheduled = Number(i.has_finished_schedule) === 1;
      const hasActiveSchedule = Number(i.has_active_schedule) === 1;
      return i.confirmed_priority
        && !i.transmitted_to_mayor_date
        && !i.is_archived
        && !passedThirdReading
        && !hasActiveSchedule
        && (!i.ready_for_scheduling || wasPreviouslyScheduled);
    });

    if (!eligible.length) {
      el.innerHTML = `<p class="text-xs text-slate-400">Nothing eligible to push right now — confirm a priority in Priority Setting first.</p>`;
      pushBtn.disabled = true;
      return;
    }

    el.innerHTML = eligible.map(item => `
      <label class="flex items-center gap-2 text-xs border-b border-[--line-200] last:border-0 pb-2 last:pb-0">
        <input type="checkbox" class="hub-agenda-item-check rounded border-[--line-200]" value="${item.id}" />
        <span class="font-mono text-slate-500">${item.id}</span>
        <span class="flex-1">${item.title}</span>
        ${priorityPill(item.confirmed_priority)}
      </label>
    `).join("");

    function updatePushButtonState() {
      const anyChecked = document.querySelectorAll(".hub-agenda-item-check:checked").length > 0;
      pushBtn.disabled = !anyChecked;
    }
    document.querySelectorAll(".hub-agenda-item-check").forEach(cb => cb.addEventListener("change", updatePushButtonState));
    updatePushButtonState();
  } catch (err) {
    el.innerHTML = `<p class="text-xs text-maroon-700">Could not load: ${err.message}</p>`;
  }
}

document.getElementById("hub-select-all-btn").addEventListener("click", () => {
  const boxes = document.querySelectorAll(".hub-agenda-item-check");
  const allChecked = [...boxes].every(cb => cb.checked);
  boxes.forEach(cb => { cb.checked = !allChecked; });
  document.getElementById("hub-push-agenda-btn").disabled = ![...boxes].some(cb => cb.checked);
});

async function loadItemOptions() {
  const select = document.getElementById("hub-item-select");
  try {
    const items = await window.API.get("api/agenda-items/list.php");
    select.innerHTML = items.map(i => `<option value="${i.id}">${i.id} — ${i.title}</option>`).join("")
      || `<option value="">No agenda items available</option>`;
  } catch (err) {
    select.innerHTML = `<option value="">Could not load items</option>`;
  }
}

async function loadAssigneeOptions() {
  const select = document.getElementById("hub-assignee-select");
  try {
    const users = await window.API.get("api/users/list-active.php");
    select.innerHTML = `<option value="">Unassigned — anyone with access can complete it</option>`
      + users.map(u => `<option value="${u.id}">${u.full_name}</option>`).join("");
  } catch (err) {
    // Non-fatal — the form still works unassigned if this fails to load.
    select.innerHTML = `<option value="">Unassigned — anyone with access can complete it</option>`;
  }
}

document.getElementById("hub-deadline-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = Object.fromEntries(new FormData(e.target));
  const errorEl = document.getElementById("hub-deadline-error");
  const resultEl = document.getElementById("hub-deadline-result");
  errorEl.classList.add("hidden");
  resultEl.classList.add("hidden");

  try {
    const result = await window.API.post("api/integration/hub-inject-deadline.php", fd);
    resultEl.innerHTML = `<i class="fa-solid fa-circle-check mr-1"></i>Simulated request sent — deadline <strong>${result.id}</strong> now appears in Deadline Tracking, tagged with its source`
      + (result.assigned_to_name ? ` and assigned to <strong>${result.assigned_to_name}</strong>.` : `, unassigned.`);
    resultEl.classList.remove("hidden");
    e.target.reset();
    loadSchedulingEvents(); // the injection also logs an integration event
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.classList.remove("hidden");
  }
});

document.getElementById("hub-push-agenda-btn").addEventListener("click", async () => {
  const btn = document.getElementById("hub-push-agenda-btn");
  const resultEl = document.getElementById("hub-push-result");
  const itemIds = [...document.querySelectorAll(".hub-agenda-item-check:checked")].map(cb => cb.value);
  if (!itemIds.length) return;

  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-1"></i>Sending…`;
  try {
    const result = await window.API.post("api/integration/hub-push-agenda.php", { item_ids: itemIds });
    resultEl.classList.remove("hidden");
    resultEl.innerHTML = result.pushed.length
      ? `<i class="fa-solid fa-circle-check mr-1"></i>Sent ${result.pushed.length} item(s): ${result.pushed.map(i => i.id).join(", ")}. They now appear in Calendar Scheduling's checklist.`
      : `<i class="fa-solid fa-circle-info mr-1"></i>${result.message}`;
    loadSchedulingEvents();
    loadAgendaPrepPreview();
  } catch (err) {
    resultEl.classList.remove("hidden");
    resultEl.innerHTML = `<span class="text-maroon-700">Could not push: ${err.message}</span>`;
  } finally {
    btn.disabled = false;
    btn.innerHTML = `<i class="fa-solid fa-paper-plane mr-1"></i>Push selected to Calendar Scheduling`;
  }
});

document.addEventListener("DOMContentLoaded", () => {
  loadSchedulingEvents();
  loadAgendaPrepPreview();
  loadItemOptions();
  loadAssigneeOptions();
});
