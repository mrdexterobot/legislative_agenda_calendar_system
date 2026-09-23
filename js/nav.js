/* ==========================================================================
   Shared shell: left sidebar + top bar.
   Each page has an empty <div id="app-sidebar"> and <div id="app-topbar">,
   and sets <body data-page="dashboard|priority|calendar|meeting|deadline|admin">
   so the matching nav link gets the "active" state.

   NOTE: Executive-Legislative Synchronization is intentionally not in this
   list — that module was removed from scope (no automatable data channel
   for executive-side status independent of the Backstopping Committee's
   manual process; see project notes). Deadline Tracking still covers the
   Sec. 54 mayor's-action window on its own.
   ========================================================================== */

const NAV_ITEMS = [
  { key: "dashboard", label: "Dashboard",              icon: "fa-table-columns",     href: "dashboard.php" },
  { key: "priority",  label: "Priority Setting",        icon: "fa-scale-balanced",    href: "priority-setting.php" },
  { key: "calendar",  label: "Calendar Scheduling",     icon: "fa-calendar-days",     href: "calendar-scheduling.php" },
  { key: "meeting",   label: "Meeting Coordination",    icon: "fa-people-group",      href: "meeting-coordination.php" },
  { key: "deadline",  label: "Deadline Tracking",       icon: "fa-hourglass-half",    href: "deadline-tracking.php" },
  { key: "hub",       label: "Integration Hub",         icon: "fa-diagram-project",   href: "integration-hub.php" },
];

function renderShell() {
  const page = document.body.dataset.page || "";
  const sidebarEl = document.getElementById("app-sidebar");
  const topbarEl = document.getElementById("app-topbar");
  const prefix = window.NAV_PREFIX || "";
  const user = window.CURRENT_USER || {};

  if (sidebarEl) {
    sidebarEl.className = "app-sidebar w-64 shrink-0 h-screen sticky top-0 flex flex-col text-sm";
    sidebarEl.innerHTML = `
      <div class="flex items-center gap-3 px-5 pt-6 pb-5">
        <div class="seal-badge"><i class="fa-solid fa-landmark"></i></div>
        <div class="leading-tight">
          <div class="text-white font-semibold text-[13px]">Legislative Services</div>
          <div class="text-[#8C9BB5] text-[11px]">Agenda &amp; Calendar System</div>
        </div>
      </div>
      <nav class="flex-1 px-2 space-y-1 mt-2">
        ${NAV_ITEMS.map(item => `
          <a href="${prefix}${item.href}" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-md ${page === item.key ? "active" : ""}">
            <i class="fa-solid ${item.icon} fa-fw"></i>
            <span>${item.label}</span>
          </a>
        `).join("")}
        ${["admin", "superadmin"].includes(user.role) ? `
          <a href="${prefix}admin/index.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-md ${page === "admin" ? "active" : ""}">
            <i class="fa-solid fa-shield-halved fa-fw"></i>
            <span>Admin</span>
          </a>
        ` : ""}
        <a href="${prefix}profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-md ${page === "profile" ? "active" : ""}">
          <i class="fa-solid fa-user fa-fw"></i>
          <span>My Profile</span>
        </a>
      </nav>
      <div class="px-5 py-4 border-t border-white/10">
        <button id="logout-btn" class="w-full text-left text-[13px] text-[#C6CEDD] hover:text-white flex items-center gap-2">
          <i class="fa-solid fa-arrow-right-from-bracket fa-fw"></i> Sign out
        </button>
      </div>
      <div class="px-5 py-4 border-t border-white/10 text-[11px] text-[#8C9BB5]">
        <div class="mb-1"><i class="fa-solid fa-circle-info mr-1.5"></i>Capstone build</div>
        <div>Sample data — pending client confirmation.</div>
      </div>
    `;

    document.getElementById("logout-btn").addEventListener("click", async () => {
      try {
        await window.API.post("api/auth/logout.php");
      } catch (e) {
        // even if the request fails, still send the user to the login page
      }
      window.location.href = prefix + "index.php";
    });
  }

  if (topbarEl) {
    const pageTitles = {
      dashboard: "Today's Docket",
      priority: "Priority Setting",
      calendar: "Calendar Scheduling",
      meeting: "Meeting Coordination",
      deadline: "Deadline Tracking",
      hub: "Integration Hub",
      admin: "Admin",
      profile: "My Profile",
    };
    topbarEl.className = "flex items-center justify-between border-b border-[--line-200] bg-[#FBFAF5] px-8 py-4";
    topbarEl.innerHTML = `
      <div>
        <h1 class="font-display text-2xl text-ink-900">${pageTitles[page] || ""}</h1>
      </div>
      <div class="flex items-center gap-4">
        <div class="flex items-center gap-2 pl-4 border-l border-[--line-200]">
          <div class="w-8 h-8 rounded-full bg-ink-700 text-white flex items-center justify-center text-xs font-semibold">
            ${(user.full_name || "U").split(" ").map(n => n[0]).slice(0,2).join("")}
          </div>
          <div class="leading-tight">
            <div class="text-xs font-semibold text-ink-900">${user.full_name || "Staff"}</div>
            <div class="text-[11px] text-slate-500 capitalize">${user.role || ""}</div>
          </div>
        </div>
      </div>
    `;
  }
}

document.addEventListener("DOMContentLoaded", renderShell);
