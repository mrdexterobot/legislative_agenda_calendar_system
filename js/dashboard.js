async function renderDashboard() {
  let payload;
  try {
    payload = await window.API.get("api/dashboard/stats.php");
  } catch (err) {
    document.getElementById("stats").innerHTML = `<p class="col-span-full text-sm text-maroon-700">Could not load dashboard data: ${err.message}</p>`;
    return;
  }

  const { stats, upcoming_sessions, recent_activity } = payload;

  document.getElementById("stats").innerHTML = `
    ${statCard("fa-scale-balanced", "brass", stats.pending_priority, "Agenda items awaiting priority confirmation")}
    ${statCard("fa-calendar-days", "info", stats.upcoming_7_days, "Sessions & hearings in the next 7 days")}
    ${statCard("fa-hourglass-half", "maroon", stats.deadlines_7_days, "Deadlines due within 7 days")}
    ${statCard("fa-stamp", "forest", stats.awaiting_mayor, "Ordinances currently with the Mayor's office")}
  `;

  document.getElementById("upcoming-agenda").innerHTML = upcoming_sessions.length
    ? upcoming_sessions.map(s => {
        const daysOut = daysBetween(todayIso(), s.session_date);
        return `
          <div class="dossier-card accent-info p-4 flex gap-4">
            <div class="w-16 shrink-0 text-center">
              <div class="font-mono text-[11px] text-slate-500">${daysOut === 0 ? "TODAY" : daysOut === 1 ? "TOMORROW" : daysOut + "d"}</div>
              <div class="font-display text-xl text-ink-900 leading-none mt-1">${new Date(s.session_date + "T00:00:00").getDate()}</div>
              <div class="text-[11px] text-slate-500">${new Date(s.session_date + "T00:00:00").toLocaleDateString("en-US", { month: "short" })}</div>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between">
                <h3 class="font-semibold text-sm text-ink-900">${s.session_type}</h3>
                <span class="text-xs text-slate-500">${s.session_time}</span>
              </div>
              <p class="text-xs text-slate-500 mt-0.5"><i class="fa-solid fa-location-dot mr-1"></i>${s.venue} &middot; ${s.presiding_officer}</p>
              <div class="mt-2 flex flex-wrap gap-1.5">
                ${s.agenda_items.length ? s.agenda_items.map(item => `<span class="pill pill-slate font-mono" title="${item.title}">${item.id}</span>${priorityPill(item.confirmed_priority)}`).join("") : `<span class="text-xs text-slate-400">No items linked yet</span>`}
              </div>
            </div>
          </div>
        `;
      }).join("")
    : `<p class="text-sm text-slate-400">Nothing scheduled yet.</p>`;

  document.getElementById("activity-feed").innerHTML = recent_activity.length
    ? recent_activity.map(a => `
        <div class="flex items-start gap-3 py-3">
          <div class="w-7 h-7 rounded-full bg-paper-100 flex items-center justify-center text-ink-700 text-xs shrink-0"><i class="fa-solid ${activityIcon(a.action)}"></i></div>
          <div class="flex-1 min-w-0 overflow-hidden">
            <p class="text-sm text-ink-900 break-words overflow-wrap-anywhere">${activitySummary(a)}</p>
            <p class="text-[11px] text-slate-500">${new Date(a.created_at).toLocaleString("en-US", { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" })}</p>
          </div>
        </div>
      `).join("")
    : `<p class="text-sm text-slate-400 p-4">No activity recorded yet.</p>`;
}

function activitySummary(a) {
  const actor = a.username ? `by ${a.username}` : "";
  const entityLabel = a.entity_id || "";

  // If details is raw JSON, parse it and summarize the changed fields instead
  // of dumping the whole raw blob into the card.
  if (a.details && a.details.startsWith("{")) {
    try {
      const parsed = JSON.parse(a.details);
      const keys = Object.keys(parsed).filter(k => k !== "item_id" && k !== "id");
      const changedFields = keys.length ? keys.join(", ").replace(/_/g, " ") : "";
      const refId = parsed.item_id || parsed.id || entityLabel;
      const actionLabel = activityActionLabel(a.action);
      if (changedFields) {
        return `${actionLabel} ${actor}: ${refId} — ${changedFields}`;
      }
      return `${actionLabel} ${actor}: ${refId}`;
    } catch (e) {
      // Fall through to text truncation
    }
  }

  if (a.details) {
    const maxLen = 120;
    return a.details.length > maxLen ? a.details.substring(0, maxLen) + "…" : a.details;
  }

  return `${activityActionLabel(a.action)} ${actor} — ${a.entity_type} ${entityLabel}`.trim();
}

function activityActionLabel(action) {
  const labels = {
    confirm_priority: "Priority confirmed",
    create: "Created",
    update: "Updated",
    archive: "Archived",
    send_notifications: "Notifications sent",
    update_mayor_status: "Mayor status updated",
    login: "Signed in",
    logout: "Signed out",
    delete: "Deleted",
    restore: "Restored",
  };
  return labels[action] || action.replace(/_/g, " ");
}

function activityIcon(action) {
  const map = {
    confirm_priority: "fa-scale-balanced",
    create: "fa-plus",
    update: "fa-pen",
    archive: "fa-box-archive",
    send_notifications: "fa-envelope",
    update_mayor_status: "fa-stamp",
  };
  return map[action] || "fa-circle-info";
}

function statCard(icon, tone, value, label) {
  return `
    <div class="dossier-card accent-${tone === "brass" ? "brass" : tone === "info" ? "info" : tone === "maroon" ? "maroon" : "forest"} p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="w-8 h-8 rounded-lg bg-paper-100 flex items-center justify-center text-ink-700"><i class="fa-solid ${icon} text-sm"></i></div>
        <span class="font-display text-2xl text-ink-900">${value}</span>
      </div>
      <p class="text-xs text-slate-500 leading-snug">${label}</p>
    </div>
  `;
}

document.addEventListener("DOMContentLoaded", renderDashboard);
