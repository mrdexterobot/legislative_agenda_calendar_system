function dateInputValue(date) {
  const year = date.getUTCFullYear();
  const month = String(date.getUTCMonth() + 1).padStart(2, "0");
  const day = String(date.getUTCDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function setDefaultDates() {
  const today = new Date();
  const from = new Date(today.getTime() - 6 * 24 * 60 * 60 * 1000);
  document.getElementById("audit-from").value = dateInputValue(from);
  document.getElementById("audit-to").value = dateInputValue(today);
}

function selectedRange() {
  return {
    from: document.getElementById("audit-from").value,
    to: document.getElementById("audit-to").value,
  };
}

function setStatus(message, className = "text-slate-500") {
  const status = document.getElementById("audit-status");
  status.textContent = message;
  status.className = `text-xs mt-3 ${className}`;
}

function addCell(row, value, className = "px-4 py-3 text-ink-800") {
  const cell = document.createElement("td");
  cell.className = className;
  cell.textContent = value ?? "—";
  row.appendChild(cell);
}

function renderLogs(data) {
  const body = document.getElementById("audit-rows");
  body.replaceChildren();

  if (!data.logs.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 5;
    cell.className = "px-4 py-10 text-center text-slate-400";
    cell.textContent = "No audit activity exists in this date range.";
    row.appendChild(cell);
    body.appendChild(row);
  } else {
    data.logs.forEach((log) => {
      const row = document.createElement("tr");
      addCell(row, log.created_at, "px-4 py-3 text-slate-600 font-mono whitespace-nowrap");
      addCell(row, log.username || "system");
      addCell(row, log.action, "px-4 py-3 text-ink-800 font-semibold");
      addCell(row, log.entity_type);
      addCell(row, log.entity_id);
      body.appendChild(row);
    });
  }

  const count = document.getElementById("audit-count");
  count.textContent = data.has_more
    ? `Showing first ${data.max_view_rows}; narrow the range to see more`
    : `${data.logs.length} record${data.logs.length === 1 ? "" : "s"}`;
}

async function loadLogs() {
  const { from, to } = selectedRange();
  if (!from || !to) {
    setStatus("Choose both dates.", "text-maroon-700");
    return;
  }

  const query = new URLSearchParams({ from, to });
  setStatus("Loading audit activity…");
  try {
    const data = await window.API.get(`api/admin/audit-log.php?${query.toString()}`);
    renderLogs(data);
    setStatus(`Showing safe audit fields from ${data.range.from} through ${data.range.to} UTC.`);
  } catch (err) {
    setStatus(err.message, "text-maroon-700");
  }
}

async function exportLogs() {
  const { from, to } = selectedRange();
  const button = document.getElementById("export-audit-btn");
  if (!from || !to) {
    setStatus("Choose both dates before exporting.", "text-maroon-700");
    return;
  }

  button.disabled = true;
  setStatus("Preparing CSV export…");
  try {
    const response = await fetch(`${window.API_PREFIX || ""}api/admin/export-audit-log.php`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": window.CSRF_TOKEN || "",
      },
      body: JSON.stringify({ from, to }),
    });

    if (response.status === 401) {
      window.location.href = `${window.APP_BASE_PATH || ""}/index.php?reason=session_expired`;
      return;
    }

    if (!response.ok) {
      let message = `Export failed (HTTP ${response.status}).`;
      try {
        const error = await response.json();
        message = error.error || message;
      } catch (e) {
        // Keep the generic message when the server did not return JSON.
      }
      throw new Error(message);
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `audit-log-${from}-to-${to}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    setStatus("CSV export downloaded. The export action was recorded in the audit log.", "text-forest-700");
    await loadLogs();
  } catch (err) {
    setStatus(err.message, "text-maroon-700");
  } finally {
    button.disabled = false;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  setDefaultDates();
  document.getElementById("audit-filter-form").addEventListener("submit", (event) => {
    event.preventDefault();
    loadLogs();
  });
  document.getElementById("export-audit-btn").addEventListener("click", exportLogs);
  loadLogs();
});
