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
  const chartMonthlyRating = document.getElementById("chart-monthly-rating");
  const chartWeeklyEntries = document.getElementById("chart-weekly-entries");
  const chartTopMedicines = document.getElementById("chart-top-medicines");
  const chartWeekdayPattern = document.getElementById("chart-weekday-pattern");

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

  function formatCount(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return "0";
    }

    return String(Math.round(numeric));
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function shortenLabel(value, maxLength = 12) {
    const text = String(value);
    if (text.length <= maxLength) {
      return text;
    }

    return `${text.slice(0, maxLength - 1)}...`;
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

  function clearChart(chartElement, message) {
    if (!chartElement) {
      return;
    }

    chartElement.innerHTML = `<p class="chart-empty">${escapeHtml(message)}</p>`;
  }

  function renderLineChart(chartElement, points, options = {}) {
    if (!chartElement) {
      return;
    }

    const validPoints = points.filter((point) => Number.isFinite(point.value));
    if (validPoints.length === 0) {
      clearChart(chartElement, "No chart data yet.");
      return;
    }

    const width = 700;
    const height = 280;
    const padding = {
      top: 22,
      right: 16,
      bottom: 48,
      left: 48,
    };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;

    const values = validPoints.map((point) => point.value);
    let yMin = Number.isFinite(options.yMin) ? options.yMin : Math.min(...values);
    let yMax = Number.isFinite(options.yMax) ? options.yMax : Math.max(...values);
    if (options.startAtZero) {
      yMin = Math.min(0, yMin);
    }
    if (yMin === yMax) {
      yMax = yMin + 1;
    }

    const xAt = (index) => {
      if (validPoints.length === 1) {
        return padding.left + plotWidth / 2;
      }
      return padding.left + (index / (validPoints.length - 1)) * plotWidth;
    };
    const yAt = (value) =>
      padding.top + ((yMax - value) / (yMax - yMin)) * plotHeight;

    const tickCount = 4;
    const gridLines = [];
    const yLabels = [];
    for (let tickIndex = 0; tickIndex <= tickCount; tickIndex += 1) {
      const value = yMax - ((yMax - yMin) * tickIndex) / tickCount;
      const y = yAt(value);
      const tickLabel =
        typeof options.tickFormatter === "function"
          ? options.tickFormatter(value)
          : value.toFixed(1);
      gridLines.push(
        `<line class="chart-grid-line" x1="${padding.left}" y1="${y.toFixed(
          2
        )}" x2="${width - padding.right}" y2="${y.toFixed(2)}"></line>`
      );
      yLabels.push(
        `<text class="chart-axis-label" x="${padding.left - 8}" y="${(
          y + 4
        ).toFixed(2)}" text-anchor="end">${escapeHtml(tickLabel)}</text>`
      );
    }

    const pathData = validPoints
      .map((point, index) => {
        const command = index === 0 ? "M" : "L";
        return `${command}${xAt(index).toFixed(2)} ${yAt(point.value).toFixed(
          2
        )}`;
      })
      .join(" ");

    const xLabels = [];
    const circles = [];
    const labelStep = validPoints.length > 8 ? Math.ceil(validPoints.length / 6) : 1;
    validPoints.forEach((point, index) => {
      const x = xAt(index);
      const y = yAt(point.value);
      const valueLabel =
        typeof options.valueFormatter === "function"
          ? options.valueFormatter(point.value)
          : String(point.value);
      circles.push(
        `<circle class="chart-point" cx="${x.toFixed(2)}" cy="${y.toFixed(
          2
        )}" r="3.8"><title>${escapeHtml(
          `${point.label}: ${valueLabel}`
        )}</title></circle>`
      );

      if (index % labelStep === 0 || index === validPoints.length - 1) {
        const renderedLabel =
          typeof options.labelFormatter === "function"
            ? options.labelFormatter(point.label)
            : shortenLabel(point.label, 10);
        xLabels.push(
          `<text class="chart-axis-label" x="${x.toFixed(2)}" y="${
            height - padding.bottom + 18
          }" text-anchor="middle">${escapeHtml(renderedLabel)}</text>`
        );
      }
    });

    chartElement.innerHTML = `
      <svg class="chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(
      options.ariaLabel || "Line chart"
    )}">
        <line class="chart-axis-line" x1="${padding.left}" y1="${padding.top}" x2="${padding.left}" y2="${
      height - padding.bottom
    }"></line>
        <line class="chart-axis-line" x1="${padding.left}" y1="${
      height - padding.bottom
    }" x2="${width - padding.right}" y2="${height - padding.bottom}"></line>
        ${gridLines.join("")}
        ${yLabels.join("")}
        <path class="chart-line" d="${pathData}"></path>
        ${circles.join("")}
        ${xLabels.join("")}
      </svg>
    `;
  }

  function renderBarChart(chartElement, points, options = {}) {
    if (!chartElement) {
      return;
    }

    const validPoints = points.filter((point) => Number.isFinite(point.value));
    if (validPoints.length === 0) {
      clearChart(chartElement, "No chart data yet.");
      return;
    }

    const width = 700;
    const height = 280;
    const padding = {
      top: 22,
      right: 16,
      bottom: 52,
      left: 44,
    };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;
    const values = validPoints.map((point) => point.value);
    const rawMax = Math.max(
      1,
      Number.isFinite(options.yMax) ? options.yMax : Math.max(...values)
    );
    const useIntegerTicks = options.integerTicks === true;
    const tickCount = 4;
    const tickValues = [];

    let yMax = rawMax;
    if (useIntegerTicks) {
      const tickStep = Math.max(1, Math.ceil(rawMax / tickCount));
      yMax = Math.max(tickStep, Math.ceil(rawMax / tickStep) * tickStep);
      for (let value = yMax; value >= 0; value -= tickStep) {
        tickValues.push(value);
      }
      if (tickValues[tickValues.length - 1] !== 0) {
        tickValues.push(0);
      }
    } else {
      for (let tickIndex = 0; tickIndex <= tickCount; tickIndex += 1) {
        tickValues.push(yMax - (yMax * tickIndex) / tickCount);
      }
    }

    const yAt = (value) =>
      padding.top + ((yMax - value) / yMax) * plotHeight;

    const gridLines = [];
    const yLabels = [];
    tickValues.forEach((value) => {
      const y = yAt(value);
      const tickLabel =
        typeof options.tickFormatter === "function"
          ? options.tickFormatter(value)
          : String(Math.round(value));
      gridLines.push(
        `<line class="chart-grid-line" x1="${padding.left}" y1="${y.toFixed(
          2
        )}" x2="${width - padding.right}" y2="${y.toFixed(2)}"></line>`
      );
      yLabels.push(
        `<text class="chart-axis-label" x="${padding.left - 8}" y="${(
          y + 4
        ).toFixed(2)}" text-anchor="end">${escapeHtml(tickLabel)}</text>`
      );
    });

    const bars = [];
    const xLabels = [];
    const slotWidth = plotWidth / validPoints.length;
    const barWidth = Math.max(10, slotWidth * 0.64);
    const labelStep = validPoints.length > 10 ? Math.ceil(validPoints.length / 8) : 1;

    validPoints.forEach((point, index) => {
      const x = padding.left + index * slotWidth + (slotWidth - barWidth) / 2;
      const barHeight = (Math.max(0, point.value) / yMax) * plotHeight;
      const y = padding.top + plotHeight - barHeight;
      const valueLabel =
        typeof options.valueFormatter === "function"
          ? options.valueFormatter(point.value)
          : String(point.value);

      bars.push(
        `<rect class="chart-bar" x="${x.toFixed(2)}" y="${y.toFixed(
          2
        )}" width="${barWidth.toFixed(2)}" height="${barHeight.toFixed(
          2
        )}"><title>${escapeHtml(`${point.label}: ${valueLabel}`)}</title></rect>`
      );

      if (index % labelStep === 0 || index === validPoints.length - 1) {
        const renderedLabel =
          typeof options.labelFormatter === "function"
            ? options.labelFormatter(point.label)
            : shortenLabel(point.label, 10);
        xLabels.push(
          `<text class="chart-axis-label" x="${(x + barWidth / 2).toFixed(2)}" y="${
            height - padding.bottom + 18
          }" text-anchor="middle">${escapeHtml(renderedLabel)}</text>`
        );
      }
    });

    chartElement.innerHTML = `
      <svg class="chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(
      options.ariaLabel || "Bar chart"
    )}">
        <line class="chart-axis-line" x1="${padding.left}" y1="${padding.top}" x2="${padding.left}" y2="${
      height - padding.bottom
    }"></line>
        <line class="chart-axis-line" x1="${padding.left}" y1="${
      height - padding.bottom
    }" x2="${width - padding.right}" y2="${height - padding.bottom}"></line>
        ${gridLines.join("")}
        ${yLabels.join("")}
        ${bars.join("")}
        ${xLabels.join("")}
      </svg>
    `;
  }

  function renderHorizontalBarChart(chartElement, points) {
    if (!chartElement) {
      return;
    }

    const validPoints = points.filter((point) => Number.isFinite(point.value));
    if (validPoints.length === 0) {
      clearChart(chartElement, "No chart data yet.");
      return;
    }

    const maxValue = Math.max(...validPoints.map((point) => point.value), 1);
    const rows = validPoints.map((point) => {
      const percent = (Math.max(0, point.value) / maxValue) * 100;
      return `
        <div class="bar-row">
          <div class="bar-meta">
            <span class="bar-label">${escapeHtml(point.label)}</span>
            <span class="bar-value">${escapeHtml(formatCount(point.value))}</span>
          </div>
          <div class="bar-track" aria-hidden="true">
            <span class="bar-fill" style="width: ${percent.toFixed(2)}%"></span>
          </div>
        </div>
      `;
    });

    chartElement.innerHTML = `<div class="bar-list">${rows.join("")}</div>`;
  }

  function clearAllCharts(message) {
    clearChart(chartMonthlyRating, message);
    clearChart(chartWeeklyEntries, message);
    clearChart(chartTopMedicines, message);
    clearChart(chartWeekdayPattern, message);
  }

  function renderTrends(trends) {
    const monthlyRows = Array.isArray(trends.monthly_average_rating)
      ? trends.monthly_average_rating
      : [];
    const weeklyRows = Array.isArray(trends.weekly_entries)
      ? trends.weekly_entries
      : [];
    const topMedicineRows = Array.isArray(trends.top_medicines_90_days)
      ? trends.top_medicines_90_days
      : [];
    const weekdayRows = Array.isArray(trends.weekday_patterns_90_days)
      ? trends.weekday_patterns_90_days
      : [];

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

    renderTrendRows(monthlyBody, monthlyRows, 3, (row) => [
      row.label || "-",
      formatRating(row.avg_rating),
      row.entries ?? 0,
    ]);

    renderTrendRows(weeklyBody, weeklyRows, 3, (row) => [
      row.label || "-",
      row.entries ?? 0,
      formatRating(row.avg_rating),
    ]);

    renderTrendRows(medicinesBody, topMedicineRows, 3, (row) => [
      row.medicine_name || "-",
      row.entries ?? 0,
      formatRating(row.avg_rating),
    ]);

    renderTrendRows(weekdayBody, weekdayRows, 3, (row) => [
      row.label || "-",
      row.entries ?? 0,
      formatRating(row.avg_rating),
    ]);

    const monthlyPoints = monthlyRows
      .map((row) => ({
        label: row.label || "-",
        value: Number(row.avg_rating),
      }))
      .filter((point) => Number.isFinite(point.value));
    renderLineChart(chartMonthlyRating, monthlyPoints, {
      yMin: 0,
      yMax: 5,
      ariaLabel: "Monthly average rating trend",
      tickFormatter: (value) => value.toFixed(1),
      valueFormatter: (value) => `${value.toFixed(2)} / 5`,
      labelFormatter: (label) => shortenLabel(label, 8),
    });

    const weeklyPoints = weeklyRows
      .map((row) => ({
        label: String(row.label || "-").replace(/^Week of\s+/i, ""),
        value: Number(row.entries),
      }))
      .filter((point) => Number.isFinite(point.value));
    renderBarChart(chartWeeklyEntries, weeklyPoints, {
      ariaLabel: "Weekly intake entries trend",
      integerTicks: true,
      tickFormatter: (value) => formatCount(value),
      valueFormatter: (value) => `${formatCount(value)} entries`,
      labelFormatter: (label) => shortenLabel(label, 8),
    });

    const topMedicinePoints = topMedicineRows
      .map((row) => ({
        label: row.medicine_name || "-",
        value: Number(row.entries),
      }))
      .filter((point) => Number.isFinite(point.value));
    renderHorizontalBarChart(chartTopMedicines, topMedicinePoints);

    const weekdayOrder = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
    const weekdayEntriesByLabel = new Map(
      weekdayRows.map((row) => [String(row.label || ""), Number(row.entries)])
    );
    const weekdayPoints = weekdayOrder.map((label) => ({
      label,
      value: Number.isFinite(weekdayEntriesByLabel.get(label))
        ? Number(weekdayEntriesByLabel.get(label))
        : 0,
    }));
    renderBarChart(chartWeekdayPattern, weekdayPoints, {
      ariaLabel: "Weekday intake entry pattern",
      integerTicks: true,
      tickFormatter: (value) => formatCount(value),
      valueFormatter: (value) => `${formatCount(value)} entries`,
      labelFormatter: (label) => label,
    });
  }

  if (!dbReady) {
    showStatus("Database unavailable.", "error");
    clearTable(monthlyBody, "Database unavailable.");
    clearTable(weeklyBody, "Database unavailable.");
    clearTable(medicinesBody, "Database unavailable.");
    clearTable(weekdayBody, "Database unavailable.");
    clearAllCharts("Database unavailable.");
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
      clearAllCharts("Could not load charts.");
    });
});
