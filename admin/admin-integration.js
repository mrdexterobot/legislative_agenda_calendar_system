async function renderTokensList() {
  const container = document.getElementById("tokens-list");
  let tokens;
  try {
    tokens = await window.API.get("api/integration/list-tokens.php");
  } catch (err) {
    container.innerHTML = `<p class="text-sm text-maroon-700">Could not load: ${err.message}</p>`;
    return;
  }

  container.innerHTML = tokens.map(t => `
    <div class="dossier-card p-4 flex items-center justify-between flex-wrap gap-3 ${t.is_active ? "" : "opacity-50"}">
      <div>
        <p class="text-sm font-semibold text-ink-900">${t.label}</p>
        <p class="text-xs text-slate-500">Created ${new Date(t.created_at).toLocaleDateString()} &middot; Last used: ${t.last_used_at ? new Date(t.last_used_at).toLocaleString() : "never"}</p>
      </div>
      ${t.is_active ? `<button data-revoke="${t.id}" class="btn-outline text-xs !py-1.5 !border-maroon-600 !text-maroon-700">Revoke</button>` : `<span class="pill pill-slate">Revoked</span>`}
    </div>
  `).join("") || `<p class="text-sm text-slate-400 text-center py-6">No tokens issued yet.</p>`;

  document.querySelectorAll("[data-revoke]").forEach(btn => btn.addEventListener("click", async () => {
    if (!confirm("Revoke this token? Any system using it will immediately lose access.")) return;
    try {
      await window.API.post("api/integration/revoke-token.php", { id: parseInt(btn.dataset.revoke, 10) });
      renderTokensList();
    } catch (err) {
      alert(`Could not revoke: ${err.message}`);
    }
  }));
}

document.getElementById("new-token-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const label = new FormData(e.target).get("label");
  try {
    const result = await window.API.post("api/integration/create-token.php", { label });
    document.getElementById("new-token-value").textContent = result.token;
    document.getElementById("new-token-result").classList.remove("hidden");
    e.target.reset();
    renderTokensList();
  } catch (err) {
    alert(`Could not create token: ${err.message}`);
  }
});

document.addEventListener("DOMContentLoaded", renderTokensList);
