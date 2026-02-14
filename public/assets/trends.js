document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_TRENDS_CONFIG || {};
  const apiPath = config.apiPath || "index.php";

  const body = document.body;
  const dbReady = body.dataset.dbReady === "1";

  const statusBanner = document.getElementById("trends-status");

  const metricAvgWeek = document.getElementById("trend-avg-week");
  const metricAvg90 = document.getElementById("trend-avg-90");
  const metricEntries30 = document.getElementById("trend-entries-30");
  const metricActiveDays30 = document.getElementById("trend-active-days-30");

  const monthlyBody = document.getElementById("trend-monthly-body");
  const weeklyBody = document.getElementById("trend-weekly-body");
  const medicinesBody = document.getElementById("trend-medicines-body");
  const weekdayBody = document.getElementById("trend-weekday-body");

  function buildApiUrl(action) {
    const url = new URL(apiPath, window.location.href);
    url.searchParams.set("api", action);
    return url.toString();
  }

  async function apiRequest(action) {
    const response = await fetch(buildApiUrl(action), {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error("Server returned an unreadable response.");
    }

    if (!response.ok || !payload.ok) {
      const errorMessage =
        payload?.errors?.join(" ") || payload?.error || "Request failed.";
      throw new Error(errorMessage);
    }

    return payload;
  }

  function showStatus(message, tone = "success") {
    if (!statusBanner) {
      return;
    }

    statusBanner.hidden = false;
    statusBanner.textContent = message;
    statusBanner.className =
      tone === "error" ? "alert alert-error" : "alert alert-success";
  }

  function formatRating(value) {
    if (value === null || value === undefined || value === "") {
      return "--";
    }

    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return "--";
    }

    return `${numeric.toFixed(2)} / 5`;
  }

  function clearTable(bodyElement, message, colSpan = 3) {
    if (!bodyElement) {
      return;
    }

    bodyElement.innerHTML = "";
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.className = "empty-cell";
    cell.colSpan = colSpan;
    cell.textContent = message;
    row.appendChild(cell);
    bodyElement.appendChild(row);
  }

  function renderTrendRows(bodyElement, rows, colSpan, createCells) {
    if (!bodyElement) {
      return;
    }

    bodyElement.innerHTML = "";
    if (!Array.isArray(rows) || rows.length === 0) {
      clearTable(bodyElement, "No data yet.", colSpan);
      return;
    }

    rows.forEach((rowData) => {
      const row = document.createElement("tr");
      const cells = createCells(rowData);
      cells.forEach((cellValue) => {
        const cell = document.createElement("td");
        cell.textContent = String(cellValue);
        row.appendChild(cell);
      });
      bodyElement.appendChild(row);
    });
  }

  function renderTrends(trends) {
    const summary = trends.summary || {};
    if (metricAvgWeek) {
      metricAvgWeek.textContent = formatRating(summary.avg_rating_this_week);
    }
    if (metricAvg90) {
      metricAvg90.textContent = formatRating(summary.avg_rating_last_90_days);
    }
    if (metricEntries30) {
      metricEntries30.textContent = String(summary.entries_last_30_days ?? 0);
    }
    if (metricActiveDays30) {
      const days = Number(summary.active_days_last_30_days ?? 0);
      const ratio = Number(summary.active_day_ratio_last_30_days ?? 0);
      metricActiveDays30.textContent = `${days} (${ratio.toFixed(1)}%)`;
    }

    renderTrendRows(monthlyBody, trends.monthly_average_rating, 3, (row) => [
      row.label || "-",
      formatRating(row.avg_rating),
      row.entries ?? 0,
    ]);

    renderTrendRows(weeklyBody, trends.weekly_entries, 3, (row) => [
      row.label || "-",
      row.entries ?? 0,
      formatRating(row.avg_rating),
    ]);

    renderTrendRows(medicinesBody, trends.top_medicines_90_days, 3, (row) => [
      row.medicine_name || "-",
      row.entries ?? 0,
      formatRating(row.avg_rating),
    ]);

    renderTrendRows(weekdayBody, trends.weekday_patterns_90_days, 3, (row) => [
      row.label || "-",
      row.entries ?? 0,
      formatRating(row.avg_rating),
    ]);
  }

  if (!dbReady) {
    showStatus("Database unavailable.", "error");
    clearTable(monthlyBody, "Database unavailable.");
    clearTable(weeklyBody, "Database unavailable.");
    clearTable(medicinesBody, "Database unavailable.");
    clearTable(weekdayBody, "Database unavailable.");
    return;
  }

  apiRequest("trends")
    .then((payload) => {
      renderTrends(payload.trends || {});
    })
    .catch((error) => {
      showStatus(error.message, "error");
      clearTable(monthlyBody, "Could not load trends.");
      clearTable(weeklyBody, "Could not load trends.");
      clearTable(medicinesBody, "Could not load trends.");
      clearTable(weekdayBody, "Could not load trends.");
    });
});
