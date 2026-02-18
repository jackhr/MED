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
  const metricDoseGapWeek = document.getElementById("trend-dose-gap-week");
  const metricDoseGap7d = document.getElementById("trend-dose-gap-7d");

  const monthlyBody = document.getElementById("trend-monthly-body");
  const weeklyBody = document.getElementById("trend-weekly-body");
  const medicinesBody = document.getElementById("trend-medicines-body");
  const weekdayBody = document.getElementById("trend-weekday-body");
  const doseOrderBody = document.getElementById("trend-dose-order-body");
  const chartMonthlyRating = document.getElementById("chart-monthly-rating");
  const chartWeeklyEntries = document.getElementById("chart-weekly-entries");
  const chartTopMedicines = document.getElementById("chart-top-medicines");
  const chartWeekdayPattern = document.getElementById("chart-weekday-pattern");
  const chartDoseOrder = document.getElementById("chart-dose-order");
  const chartDoseOrderMeta = document.getElementById("chart-dose-order-meta");
  const chartDoseInterval = document.getElementById("chart-dose-interval");
  const chartDoseIntervalMeta = document.getElementById(
    "chart-dose-interval-meta"
  );
  const chartDoseIntervalRolling = document.getElementById(
    "chart-dose-interval-rolling"
  );
  const chartDoseIntervalRollingMeta = document.getElementById(
    "chart-dose-interval-rolling-meta"
  );
  const doseViewControls = document.getElementById("dose-view-controls");
  const doseOrderControls = document.getElementById("dose-order-controls");
  const doseMedicineControls = document.getElementById("dose-medicine-controls");

  let latestTrends = null;
  let selectedDoseView = "single";
  let selectedDoseOrder = null;
  let selectedDoseMedicine = "all";

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

  function formatMinutesAsTime(value, compact = false) {
    if (value === null || value === undefined || value === "") {
      return "--";
    }

    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return "--";
    }

    const rounded = Math.round(numeric);
    if (rounded > 1440) {
      return compact ? "12 AM+" : "12:00 AM (+1d)";
    }
    if (rounded === 1440) {
      return compact ? "12AM" : "12:00 AM";
    }

    const safeMinutes = Math.max(0, rounded);
    const hours24 = Math.floor(safeMinutes / 60);
    const minutes = safeMinutes % 60;
    const period = hours24 >= 12 ? "PM" : "AM";
    const hours12 = hours24 % 12 === 0 ? 12 : hours24 % 12;

    if (compact && minutes === 0) {
      return `${hours12}${period}`;
    }

    return `${hours12}:${String(minutes).padStart(2, "0")} ${period}`;
  }

  function formatMinutesAsDuration(value, compact = false) {
    if (value === null || value === undefined || value === "") {
      return "--";
    }

    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return "--";
    }

    const totalMinutes = Math.max(0, Math.round(numeric));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours <= 0) {
      return `${minutes}m`;
    }
    if (minutes === 0) {
      return compact ? `${hours}h` : `${hours}h 0m`;
    }

    return `${hours}h ${minutes}m`;
  }

  function formatDosageValue(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return "--";
    }

    if (Math.abs(numeric - Math.round(numeric)) < 0.001) {
      return String(Math.round(numeric));
    }

    return numeric.toFixed(2).replace(/\.?0+$/, "");
  }

  function normalizeDoseOrder(value) {
    const numeric = Number(value);
    if (!Number.isInteger(numeric) || numeric <= 0) {
      return null;
    }

    return numeric;
  }

  function normalizeDoseMedicine(value) {
    if (value === "all") {
      return "all";
    }

    const numeric = Number(value);
    if (!Number.isInteger(numeric) || numeric <= 0) {
      return null;
    }

    return numeric;
  }

  function formatOrdinal(value) {
    const safeValue = normalizeDoseOrder(value);
    if (safeValue === null) {
      return String(value);
    }

    const mod100 = safeValue % 100;
    if (mod100 >= 11 && mod100 <= 13) {
      return `${safeValue}th`;
    }

    switch (safeValue % 10) {
      case 1:
        return `${safeValue}st`;
      case 2:
        return `${safeValue}nd`;
      case 3:
        return `${safeValue}rd`;
      default:
        return `${safeValue}th`;
    }
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

  function attachChartHoverTooltip(chartElement, selector) {
    if (!chartElement) {
      return;
    }

    const targetElements = chartElement.querySelectorAll(selector);
    if (targetElements.length === 0) {
      return;
    }

    const tooltip = document.createElement("div");
    tooltip.className = "chart-tooltip";
    tooltip.hidden = true;
    chartElement.appendChild(tooltip);

    const positionTooltip = (clientX, clientY) => {
      const shellRect = chartElement.getBoundingClientRect();
      const tooltipWidth = tooltip.offsetWidth;
      const tooltipHeight = tooltip.offsetHeight;
      const baseX = clientX - shellRect.left + 12;
      const baseY = clientY - shellRect.top - tooltipHeight - 10;
      const clampedX = Math.min(
        Math.max(8, baseX),
        Math.max(8, shellRect.width - tooltipWidth - 8)
      );
      const clampedY = Math.min(
        Math.max(8, baseY),
        Math.max(8, shellRect.height - tooltipHeight - 8)
      );
      tooltip.style.left = `${clampedX}px`;
      tooltip.style.top = `${clampedY}px`;
    };

    const hideTooltip = () => {
      tooltip.hidden = true;
    };

    targetElements.forEach((targetElement) => {
      targetElement.addEventListener("mouseenter", (event) => {
        const encoded = targetElement.getAttribute("data-tooltip") || "";
        let decoded = "";
        try {
          decoded = decodeURIComponent(encoded);
        } catch (error) {
          decoded = "";
        }

        if (decoded === "") {
          hideTooltip();
          return;
        }

        tooltip.textContent = decoded;
        tooltip.hidden = false;
        positionTooltip(event.clientX, event.clientY);
      });

      targetElement.addEventListener("mousemove", (event) => {
        if (tooltip.hidden) {
          return;
        }

        positionTooltip(event.clientX, event.clientY);
      });

      targetElement.addEventListener("mouseleave", hideTooltip);
    });
  }

  function renderBarChart(chartElement, points, options = {}) {
    if (!chartElement) {
      return;
    }

    const sourcePoints = Array.isArray(points) ? points : [];
    const validPoints = sourcePoints.filter((point) => Number.isFinite(point.value));
    const plottedPoints =
      options.keepAllPoints === true ? sourcePoints : validPoints;

    if (plottedPoints.length === 0 || validPoints.length === 0) {
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

    let referenceLine = "";
    let referenceLabel = "";
    if (Number.isFinite(options.referenceLineValue)) {
      const referenceRawValue = Number(options.referenceLineValue);
      const referenceValue = Math.max(0, Math.min(yMax, referenceRawValue));
      const referenceY = yAt(referenceValue);
      const label = options.referenceLineLabel || "Average";
      const valueLabel =
        typeof options.referenceValueFormatter === "function"
          ? options.referenceValueFormatter(referenceRawValue)
          : String(referenceRawValue);
      const textY = Math.max(padding.top + 12, referenceY - 6);

      referenceLine = `<line class="chart-reference-line" x1="${padding.left}" y1="${referenceY.toFixed(
        2
      )}" x2="${width - padding.right}" y2="${referenceY.toFixed(
        2
      )}"><title>${escapeHtml(
        `${label}: ${valueLabel}`
      )}</title></line>`;
      referenceLabel = `<text class="chart-reference-label" x="${(
        width - padding.right - 4
      ).toFixed(2)}" y="${textY.toFixed(2)}" text-anchor="end">${escapeHtml(
        `${label}: ${valueLabel}`
      )}</text>`;
    }

    const bars = [];
    const xLabels = [];
    const slotWidth = plotWidth / plottedPoints.length;
    const barWidth = Math.max(10, slotWidth * 0.64);
    const labelStep = plottedPoints.length > 10 ? Math.ceil(plottedPoints.length / 8) : 1;

    plottedPoints.forEach((point, index) => {
      const x = padding.left + index * slotWidth + (slotWidth - barWidth) / 2;
      const hasValue = Number.isFinite(point.value);
      const barHeight = hasValue ? (Math.max(0, point.value) / yMax) * plotHeight : 0;
      const y = padding.top + plotHeight - barHeight;
      const valueLabel =
        typeof options.valueFormatter === "function"
          ? options.valueFormatter(point.value)
          : String(point.value);

      if (hasValue) {
        const tooltipText = `${point.label}: ${valueLabel}`;
        const encodedTooltipText = encodeURIComponent(tooltipText);
        bars.push(
          `<rect class="chart-bar" x="${x.toFixed(2)}" y="${y.toFixed(
            2
          )}" width="${barWidth.toFixed(2)}" height="${barHeight.toFixed(
            2
          )}" data-tooltip="${encodedTooltipText}"></rect>`
        );
      }

      if (index % labelStep === 0 || index === plottedPoints.length - 1) {
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
        ${referenceLine}
        ${referenceLabel}
        ${xLabels.join("")}
      </svg>
    `;

    if (options.enableHoverTooltip === true) {
      attachChartHoverTooltip(chartElement, ".chart-bar[data-tooltip]");
    }
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
    clearChart(chartDoseOrder, message);
    clearChart(chartDoseInterval, message);
    clearChart(chartDoseIntervalRolling, message);
  }

  function buildDoseWeekdaySeries(rows, doseOrder, selectedMedicine) {
    const weekdayOrder = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
    const rowsByWeekday = new Map();
    const normalizedMedicine = normalizeDoseMedicine(selectedMedicine);
    const targetMedicine =
      normalizedMedicine === null ? "all" : normalizedMedicine;

    rows.forEach((row) => {
      const rowDoseOrder = normalizeDoseOrder(row.dose_order);
      const rowMedicine =
        row.medicine_id === null ||
        row.medicine_id === undefined ||
        row.medicine_id === ""
          ? "all"
          : normalizeDoseMedicine(row.medicine_id);
      const weekdayIndex = Number(row.weekday_index);
      if (
        rowDoseOrder !== doseOrder ||
        rowMedicine === null ||
        rowMedicine !== targetMedicine ||
        !Number.isInteger(weekdayIndex) ||
        weekdayIndex < 0 ||
        weekdayIndex > 6
      ) {
        return;
      }

      const averageMinute = Number(row.avg_minute_of_day);
      const samplesRaw = Number(row.samples);
      rowsByWeekday.set(weekdayIndex, {
        weekday_index: weekdayIndex,
        weekday_label: weekdayOrder[weekdayIndex],
        avg_minute_of_day: Number.isFinite(averageMinute) ? averageMinute : null,
        samples:
          Number.isFinite(samplesRaw) && samplesRaw > 0
            ? Math.round(samplesRaw)
            : 0,
      });
    });

    return weekdayOrder.map((weekdayLabel, weekdayIndex) => {
      if (rowsByWeekday.has(weekdayIndex)) {
        return rowsByWeekday.get(weekdayIndex);
      }

      return {
        weekday_index: weekdayIndex,
        weekday_label: weekdayLabel,
        avg_minute_of_day: null,
        samples: 0,
      };
    });
  }

  function buildDoseDosageAveragesByOrder(rows, availableOrders, selectedMedicine) {
    const normalizedMedicine = normalizeDoseMedicine(selectedMedicine);
    const targetMedicine =
      normalizedMedicine === null ? "all" : normalizedMedicine;
    const validOrders = new Set(
      (Array.isArray(availableOrders) ? availableOrders : [])
        .map((order) => normalizeDoseOrder(order))
        .filter((order) => order !== null)
    );
    const valuesByOrder = new Map();

    (Array.isArray(rows) ? rows : []).forEach((row) => {
      const rowDoseOrder = normalizeDoseOrder(row.dose_order);
      const rowMedicine =
        row.medicine_id === null ||
        row.medicine_id === undefined ||
        row.medicine_id === ""
          ? "all"
          : normalizeDoseMedicine(row.medicine_id);
      const dosageUnit = String(row.dosage_unit || "").trim();
      const avgDosageValue = Number(row.avg_dosage_value);
      const samples = Number(row.samples);
      if (
        rowDoseOrder === null ||
        !validOrders.has(rowDoseOrder) ||
        rowMedicine === null ||
        rowMedicine !== targetMedicine ||
        dosageUnit === "" ||
        !Number.isFinite(avgDosageValue) ||
        !Number.isFinite(samples) ||
        samples <= 0
      ) {
        return;
      }

      if (!valuesByOrder.has(rowDoseOrder)) {
        valuesByOrder.set(rowDoseOrder, []);
      }

      valuesByOrder.get(rowDoseOrder).push({
        dosage_unit: dosageUnit,
        avg_dosage_value: avgDosageValue,
        samples: Math.round(samples),
      });
    });

    return (Array.isArray(availableOrders) ? availableOrders : []).map((order) => {
      const normalizedOrder = normalizeDoseOrder(order);
      const rowsForOrder =
        normalizedOrder !== null && valuesByOrder.has(normalizedOrder)
          ? valuesByOrder.get(normalizedOrder)
          : [];
      const dosageText = rowsForOrder
        .map(
          (row) =>
            `${formatDosageValue(row.avg_dosage_value)} ${String(
              row.dosage_unit || ""
            ).trim()}`
        )
        .filter((value) => value !== "")
        .join(" / ");
      const totalSamples = rowsForOrder.reduce(
        (sum, row) => sum + Math.max(0, Number(row.samples) || 0),
        0
      );

      return {
        dose_order: normalizedOrder,
        dosage_text: dosageText === "" ? "--" : dosageText,
        samples: totalSamples,
      };
    });
  }

  function buildDoseWeekdayCombinedSeries(rows, availableOrders, selectedMedicine) {
    const weekdayOrder = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
    const validOrders = new Set(
      (Array.isArray(availableOrders) ? availableOrders : [])
        .map((order) => normalizeDoseOrder(order))
        .filter((order) => order !== null)
    );
    const rowsByWeekday = new Map();
    const normalizedMedicine = normalizeDoseMedicine(selectedMedicine);
    const targetMedicine =
      normalizedMedicine === null ? "all" : normalizedMedicine;

    rows.forEach((row) => {
      const rowDoseOrder = normalizeDoseOrder(row.dose_order);
      const rowMedicine =
        row.medicine_id === null ||
        row.medicine_id === undefined ||
        row.medicine_id === ""
          ? "all"
          : normalizeDoseMedicine(row.medicine_id);
      const weekdayIndex = Number(row.weekday_index);
      if (
        rowDoseOrder === null ||
        !validOrders.has(rowDoseOrder) ||
        rowMedicine === null ||
        rowMedicine !== targetMedicine ||
        !Number.isInteger(weekdayIndex) ||
        weekdayIndex < 0 ||
        weekdayIndex > 6
      ) {
        return;
      }

      const averageMinute = Number(row.avg_minute_of_day);
      const samplesRaw = Number(row.samples);
      const normalizedRow = {
        dose_order: rowDoseOrder,
        avg_minute_of_day: Number.isFinite(averageMinute) ? averageMinute : null,
        samples:
          Number.isFinite(samplesRaw) && samplesRaw > 0
            ? Math.round(samplesRaw)
            : 0,
      };

      if (!rowsByWeekday.has(weekdayIndex)) {
        rowsByWeekday.set(weekdayIndex, new Map());
      }
      rowsByWeekday.get(weekdayIndex).set(rowDoseOrder, normalizedRow);
    });

    return weekdayOrder.map((weekdayLabel, weekdayIndex) => {
      const doseRows = rowsByWeekday.get(weekdayIndex) || new Map();
      const doses = (Array.isArray(availableOrders) ? availableOrders : []).map(
        (doseOrder) => {
          const normalizedDoseOrder = normalizeDoseOrder(doseOrder);
          const existingRow =
            normalizedDoseOrder !== null
              ? doseRows.get(normalizedDoseOrder)
              : null;
          return (
            existingRow || {
              dose_order: normalizedDoseOrder,
              avg_minute_of_day: null,
              samples: 0,
            }
          );
        }
      );

      return {
        weekday_index: weekdayIndex,
        weekday_label: weekdayLabel,
        doses,
      };
    });
  }

  function averageMinuteFromSeries(series) {
    let weightedSum = 0;
    let totalSamples = 0;

    series.forEach((row) => {
      const averageMinute = Number(row.avg_minute_of_day);
      const samples = Number(row.samples);
      if (
        Number.isFinite(averageMinute) &&
        Number.isFinite(samples) &&
        samples > 0
      ) {
        weightedSum += averageMinute * samples;
        totalSamples += samples;
      }
    });

    return totalSamples > 0 ? weightedSum / totalSamples : null;
  }

  function medicinesForDoseOrder(rows, availableMedicines, doseOrder) {
    const medicineNames = new Map();
    if (Array.isArray(availableMedicines)) {
      availableMedicines.forEach((medicine) => {
        const medicineId = normalizeDoseMedicine(medicine?.id);
        const medicineName = String(medicine?.name || "").trim();
        if (medicineId !== null && medicineId !== "all" && medicineName !== "") {
          medicineNames.set(medicineId, medicineName);
        }
      });
    }

    const medicinesByOrder = new Map();
    rows.forEach((row) => {
      const rowDoseOrder = normalizeDoseOrder(row.dose_order);
      const rowMedicineId = normalizeDoseMedicine(row.medicine_id);
      const samples = Number(row.samples);
      if (
        rowDoseOrder !== doseOrder ||
        rowMedicineId === null ||
        rowMedicineId === "all" ||
        !Number.isFinite(samples) ||
        samples <= 0
      ) {
        return;
      }

      const rowMedicineName = String(row.medicine_name || "").trim();
      medicinesByOrder.set(
        rowMedicineId,
        rowMedicineName !== ""
          ? rowMedicineName
          : medicineNames.get(rowMedicineId) || `Medicine ${rowMedicineId}`
      );
    });

    return Array.from(medicinesByOrder.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((left, right) => left.name.localeCompare(right.name));
  }

  function medicinesForCombined(rows, availableMedicines, availableOrders) {
    const medicineNames = new Map();
    if (Array.isArray(availableMedicines)) {
      availableMedicines.forEach((medicine) => {
        const medicineId = normalizeDoseMedicine(medicine?.id);
        const medicineName = String(medicine?.name || "").trim();
        if (medicineId !== null && medicineId !== "all" && medicineName !== "") {
          medicineNames.set(medicineId, medicineName);
        }
      });
    }

    const validOrders = new Set(
      (Array.isArray(availableOrders) ? availableOrders : [])
        .map((value) => normalizeDoseOrder(value))
        .filter((value) => value !== null)
    );
    const medicinesByOrder = new Map();

    rows.forEach((row) => {
      const rowDoseOrder = normalizeDoseOrder(row.dose_order);
      const rowMedicineId = normalizeDoseMedicine(row.medicine_id);
      const samples = Number(row.samples);
      if (
        rowDoseOrder === null ||
        !validOrders.has(rowDoseOrder) ||
        rowMedicineId === null ||
        rowMedicineId === "all" ||
        !Number.isFinite(samples) ||
        samples <= 0
      ) {
        return;
      }

      const rowMedicineName = String(row.medicine_name || "").trim();
      medicinesByOrder.set(
        rowMedicineId,
        rowMedicineName !== ""
          ? rowMedicineName
          : medicineNames.get(rowMedicineId) || `Medicine ${rowMedicineId}`
      );
    });

    return Array.from(medicinesByOrder.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((left, right) => left.name.localeCompare(right.name));
  }

  function renderDoseViewControls() {
    if (!doseViewControls) {
      return;
    }

    const viewModes = [
      {
        id: "single",
        label: "Single dose",
      },
      {
        id: "combined",
        label: "Combined day",
      },
    ];

    doseViewControls.innerHTML = "";
    viewModes.forEach((viewMode) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "ghost-btn dose-order-btn";
      button.textContent = viewMode.label;
      if (selectedDoseView === viewMode.id) {
        button.classList.add("is-active");
      }
      button.addEventListener("click", () => {
        if (selectedDoseView === viewMode.id) {
          return;
        }

        selectedDoseView = viewMode.id;
        if (latestTrends) {
          renderDoseOrderTrend(latestTrends);
        }
      });
      doseViewControls.appendChild(button);
    });
  }

  function doseLayerColor(orderIndex, totalOrders) {
    const safeIndex = Math.max(0, Number(orderIndex) || 0);
    const safeTotal = Math.max(1, Number(totalOrders) || 1);
    const ratio =
      safeTotal <= 1 ? 0 : Math.max(0, Math.min(1, safeIndex / (safeTotal - 1)));
    const lightness = 45 - ratio * 20;
    const alpha = 0.96 - ratio * 0.24;
    return `hsla(172, 72%, ${lightness.toFixed(1)}%, ${alpha.toFixed(3)})`;
  }

  function doseLayerLineColor(orderIndex, totalOrders) {
    const safeIndex = Math.max(0, Number(orderIndex) || 0);
    const safeTotal = Math.max(1, Number(totalOrders) || 1);
    const ratio =
      safeTotal <= 1 ? 0 : Math.max(0, Math.min(1, safeIndex / (safeTotal - 1)));
    const lightness = 34 - ratio * 14;
    return `hsla(172, 72%, ${lightness.toFixed(1)}%, 0.95)`;
  }

  function renderCombinedDoseOrderChart(chartElement, weekdays, doseOrders, options) {
    if (!chartElement) {
      return;
    }

    const sourceWeekdays = Array.isArray(weekdays) ? weekdays : [];
    const sourceDoseOrders = Array.isArray(doseOrders) ? doseOrders : [];
    const hasAnyData = sourceWeekdays.some((weekday) =>
      Array.isArray(weekday.doses)
        ? weekday.doses.some(
            (dose) =>
              Number.isFinite(Number(dose.avg_minute_of_day)) &&
              Number(dose.samples) > 0
          )
        : false
    );
    if (sourceWeekdays.length === 0 || sourceDoseOrders.length === 0 || !hasAnyData) {
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
    const yMax = 24;
    const tickValues = [24, 18, 12, 6, 0];

    const yAt = (value) => padding.top + ((yMax - value) / yMax) * plotHeight;
    const referenceLines = Array.isArray(options?.referenceLines)
      ? options.referenceLines
          .map((line) => ({
            value: Number(line?.value),
            label: String(line?.label || "Average"),
            color: String(line?.color || "#f5a23a"),
          }))
          .filter((line) => Number.isFinite(line.value))
      : [];

    const gridLines = [];
    const yLabels = [];
    tickValues.forEach((value) => {
      const y = yAt(value);
      gridLines.push(
        `<line class="chart-grid-line" x1="${padding.left}" y1="${y.toFixed(
          2
        )}" x2="${width - padding.right}" y2="${y.toFixed(2)}"></line>`
      );
      yLabels.push(
        `<text class="chart-axis-label" x="${padding.left - 8}" y="${(
          y + 4
        ).toFixed(2)}" text-anchor="end">${escapeHtml(
          formatMinutesAsTime(value * 60, true)
        )}</text>`
      );
    });

    const slotWidth = plotWidth / sourceWeekdays.length;
    const bars = [];
    const xLabels = [];
    const referenceLineElements = [];
    const referenceLegendElements = [];
    const drawOrders = [...sourceDoseOrders].sort((left, right) => right - left);

    referenceLines.forEach((line, index) => {
      const clampedValue = Math.max(0, Math.min(yMax, line.value));
      const y = yAt(clampedValue);
      const valueLabel = formatMinutesAsTime(clampedValue * 60);
      referenceLineElements.push(
        `<line class="chart-reference-line chart-dose-reference-line" x1="${
          padding.left
        }" y1="${y.toFixed(2)}" x2="${width - padding.right}" y2="${y.toFixed(
          2
        )}" style="stroke:${escapeHtml(line.color)}"><title>${escapeHtml(
          `${line.label}: ${valueLabel}`
        )}</title></line>`
      );
      referenceLegendElements.push(
        `<text class="chart-reference-label chart-dose-reference-label" x="${(
          width - padding.right - 4
        ).toFixed(2)}" y="${(padding.top + 12 + index * 12).toFixed(
          2
        )}" text-anchor="end" style="fill:${escapeHtml(line.color)}">${escapeHtml(
          `${line.label}: ${valueLabel}`
        )}</text>`
      );
    });

    sourceWeekdays.forEach((weekday, weekdayIndex) => {
      const centerX = padding.left + weekdayIndex * slotWidth + slotWidth / 2;
      const dosesByOrder = new Map(
        (Array.isArray(weekday.doses) ? weekday.doses : []).map((dose) => [
          normalizeDoseOrder(dose.dose_order),
          dose,
        ])
      );

      drawOrders.forEach((doseOrder) => {
        const normalizedDoseOrder = normalizeDoseOrder(doseOrder);
        if (normalizedDoseOrder === null) {
          return;
        }

        const doseIndex = sourceDoseOrders.indexOf(normalizedDoseOrder);
        const doseRow = dosesByOrder.get(normalizedDoseOrder);
        if (!doseRow) {
          return;
        }

        const averageMinute = Number(doseRow.avg_minute_of_day);
        const samples = Number(doseRow.samples);
        if (
          !Number.isFinite(averageMinute) ||
          !Number.isFinite(samples) ||
          samples <= 0
        ) {
          return;
        }

        const valueHours = Math.max(0, Math.min(yMax, averageMinute / 60));
        const barHeight = (valueHours / yMax) * plotHeight;
        const y = padding.top + plotHeight - barHeight;
        const widthScale = 0.46 + doseIndex * 0.08;
        const barWidth = Math.max(
          12,
          Math.min(slotWidth * 0.84, slotWidth * widthScale)
        );
        const x = centerX - barWidth / 2;
        const tooltipText = `${weekday.weekday_label} ${formatOrdinal(
          normalizedDoseOrder
        )} dose: ${formatMinutesAsTime(averageMinute)} (${formatCount(
          samples
        )} samples)`;
        bars.push(
          `<rect class="chart-bar chart-dose-layer" x="${x.toFixed(
            2
          )}" y="${y.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${barHeight.toFixed(
            2
          )}" style="fill:${doseLayerColor(
            doseIndex,
            sourceDoseOrders.length
          )}" data-tooltip="${encodeURIComponent(tooltipText)}"></rect>`
        );
      });

      xLabels.push(
        `<text class="chart-axis-label" x="${centerX.toFixed(2)}" y="${
          height - padding.bottom + 18
        }" text-anchor="middle">${escapeHtml(weekday.weekday_label)}</text>`
      );
    });

    chartElement.innerHTML = `
      <svg class="chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(
      options?.ariaLabel || "Combined dose time highlights"
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
        ${referenceLegendElements.join("")}
        ${xLabels.join("")}
        ${referenceLineElements.join("")}
      </svg>
    `;

    attachChartHoverTooltip(chartElement, ".chart-dose-layer[data-tooltip]");
  }

  function renderDoseOrderControls(availableOrders) {
    if (!doseOrderControls) {
      return;
    }

    doseOrderControls.innerHTML = "";
    availableOrders.forEach((order) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "ghost-btn dose-order-btn";
      button.textContent = `${formatOrdinal(order)} dose`;
      if (order === selectedDoseOrder) {
        button.classList.add("is-active");
      }
      button.addEventListener("click", () => {
        if (selectedDoseOrder === order) {
          return;
        }

        selectedDoseOrder = order;
        if (latestTrends) {
          renderDoseOrderTrend(latestTrends);
        }
      });
      doseOrderControls.appendChild(button);
    });
  }

  function renderDoseMedicineControls(medicines) {
    if (!doseMedicineControls) {
      return;
    }

    if (!Array.isArray(medicines) || medicines.length <= 1) {
      doseMedicineControls.hidden = true;
      doseMedicineControls.innerHTML = "";
      return;
    }

    doseMedicineControls.hidden = false;
    doseMedicineControls.innerHTML = "";

    const allButton = document.createElement("button");
    allButton.type = "button";
    allButton.className = "ghost-btn dose-order-btn";
    allButton.textContent = "All medicines";
    if (selectedDoseMedicine === "all") {
      allButton.classList.add("is-active");
    }
    allButton.addEventListener("click", () => {
      if (selectedDoseMedicine === "all") {
        return;
      }

      selectedDoseMedicine = "all";
      if (latestTrends) {
        renderDoseOrderTrend(latestTrends);
      }
    });
    doseMedicineControls.appendChild(allButton);

    medicines.forEach((medicine) => {
      const medicineId = normalizeDoseMedicine(medicine.id);
      if (medicineId === null || medicineId === "all") {
        return;
      }

      const button = document.createElement("button");
      button.type = "button";
      button.className = "ghost-btn dose-order-btn";
      button.textContent = String(medicine.name || `Medicine ${medicineId}`);
      if (selectedDoseMedicine === medicineId) {
        button.classList.add("is-active");
      }
      button.addEventListener("click", () => {
        if (selectedDoseMedicine === medicineId) {
          return;
        }

        selectedDoseMedicine = medicineId;
        if (latestTrends) {
          renderDoseOrderTrend(latestTrends);
        }
      });
      doseMedicineControls.appendChild(button);
    });
  }

  function renderDoseOrderTrend(trends) {
    const doseWeekdayData = trends?.dose_weekday_patterns_90_days || {};
    const rawRows = Array.isArray(doseWeekdayData.rows)
      ? doseWeekdayData.rows
      : [];
    const dosageRowsRaw = Array.isArray(doseWeekdayData.dosage_averages)
      ? doseWeekdayData.dosage_averages
      : [];
    const availableMedicinesRaw = Array.isArray(
      doseWeekdayData.available_medicines
    )
      ? doseWeekdayData.available_medicines
      : [];
    let availableOrders = Array.isArray(doseWeekdayData.available_orders)
      ? doseWeekdayData.available_orders
          .map((value) => normalizeDoseOrder(value))
          .filter((value) => value !== null)
      : [];

    if (selectedDoseView !== "single" && selectedDoseView !== "combined") {
      selectedDoseView = "single";
    }

    if (availableOrders.length === 0) {
      availableOrders = Array.from(
        new Set(
          rawRows
            .map((row) => normalizeDoseOrder(row.dose_order))
            .filter((value) => value !== null)
        )
      );
    }
    availableOrders.sort((left, right) => left - right);

    if (availableOrders.length === 0) {
      selectedDoseMedicine = "all";
      if (doseViewControls) {
        doseViewControls.innerHTML = "";
      }
      if (doseOrderControls) {
        doseOrderControls.hidden = selectedDoseView !== "single";
        doseOrderControls.innerHTML = "";
      }
      if (doseMedicineControls) {
        doseMedicineControls.hidden = true;
        doseMedicineControls.innerHTML = "";
      }
      if (chartDoseOrderMeta) {
        chartDoseOrderMeta.textContent =
          "No dose-order timing data yet for the last 90 days.";
      }
      clearTable(doseOrderBody, "No data yet.");
      clearChart(chartDoseOrder, "No chart data yet.");
      return;
    }

    renderDoseViewControls();
    const isCombinedView = selectedDoseView === "combined";

    if (isCombinedView) {
      if (doseOrderControls) {
        doseOrderControls.hidden = true;
      }

      const medicinesForSelectedView = medicinesForCombined(
        rawRows,
        availableMedicinesRaw,
        availableOrders
      );
      const normalizedSelectedMedicine = normalizeDoseMedicine(selectedDoseMedicine);
      if (
        medicinesForSelectedView.length <= 1 ||
        normalizedSelectedMedicine === null
      ) {
        selectedDoseMedicine = "all";
      } else if (
        normalizedSelectedMedicine !== "all" &&
        !medicinesForSelectedView.some(
          (medicine) => medicine.id === normalizedSelectedMedicine
        )
      ) {
        selectedDoseMedicine = "all";
      }
      renderDoseMedicineControls(medicinesForSelectedView);

      const activeMedicineFilter =
        medicinesForSelectedView.length > 1
          ? normalizeDoseMedicine(selectedDoseMedicine) || "all"
          : "all";
      const selectedMedicine = medicinesForSelectedView.find(
        (medicine) => medicine.id === activeMedicineFilter
      );
      const selectedMedicineLabel =
        activeMedicineFilter === "all"
          ? medicinesForSelectedView.length === 1
            ? medicinesForSelectedView[0].name
            : "All medicines"
          : selectedMedicine?.name || "All medicines";

      const combinedSeries = buildDoseWeekdayCombinedSeries(
        rawRows,
        availableOrders,
        activeMedicineFilter
      );
      const activeOrders = availableOrders.filter((doseOrder) =>
        combinedSeries.some((weekday) =>
          (Array.isArray(weekday.doses) ? weekday.doses : []).some(
            (dose) =>
              normalizeDoseOrder(dose.dose_order) === doseOrder &&
              Number.isFinite(Number(dose.avg_minute_of_day)) &&
              Number(dose.samples) > 0
          )
        )
      );

      if (activeOrders.length === 0) {
        if (chartDoseOrderMeta) {
          chartDoseOrderMeta.textContent =
            "No combined dose timing data yet for the last 90 days.";
        }
        clearTable(doseOrderBody, "No data yet.");
        clearChart(chartDoseOrder, "No chart data yet.");
        return;
      }

      const perDoseAverages = activeOrders
        .map((doseOrder) => {
          const series = buildDoseWeekdaySeries(
            rawRows,
            doseOrder,
            activeMedicineFilter
          );
          const averageMinute = averageMinuteFromSeries(series);
          return {
            doseOrder,
            averageMinute,
          };
        })
        .filter((row) => row.averageMinute !== null);
      const perDoseDosageAverages = buildDoseDosageAveragesByOrder(
        dosageRowsRaw,
        activeOrders,
        activeMedicineFilter
      );

      const averagesSummary = perDoseAverages
        .map(
          (row) =>
            `${formatOrdinal(row.doseOrder)}: ${formatMinutesAsTime(
              row.averageMinute
            )}`
        )
        .join(" | ");
      const dosageSummary = perDoseDosageAverages
        .filter(
          (row) =>
            row.dose_order !== null &&
            row.samples > 0 &&
            String(row.dosage_text || "").trim() !== "--"
        )
        .map(
          (row) =>
            `${formatOrdinal(row.dose_order)}: ${String(row.dosage_text || "--")}`
        )
        .join(" | ");
      const averagesSummaryText =
        averagesSummary === "" ? "No average-time samples yet." : averagesSummary;
      const dosageSummaryText =
        dosageSummary === "" ? "No dosage averages yet." : dosageSummary;
      if (chartDoseOrderMeta) {
        chartDoseOrderMeta.textContent = `Combined weekday timing for ${selectedMedicineLabel} over the last 90 days. Avg times: ${averagesSummaryText}. Avg dosages: ${dosageSummaryText}`;
      }

      renderTrendRows(doseOrderBody, combinedSeries, 3, (row) => {
        const doseTimes = activeOrders
          .map((doseOrder) => {
            const matchingDose = (Array.isArray(row.doses) ? row.doses : []).find(
              (dose) => normalizeDoseOrder(dose.dose_order) === doseOrder
            );
            if (
              !matchingDose ||
              !Number.isFinite(Number(matchingDose.avg_minute_of_day)) ||
              Number(matchingDose.samples) <= 0
            ) {
              return null;
            }
            return `${formatOrdinal(doseOrder)}: ${formatMinutesAsTime(
              matchingDose.avg_minute_of_day
            )}`;
          })
          .filter((value) => value !== null);
        const totalSamples = (Array.isArray(row.doses) ? row.doses : []).reduce(
          (total, dose) => {
            const doseOrder = normalizeDoseOrder(dose.dose_order);
            if (doseOrder === null || !activeOrders.includes(doseOrder)) {
              return total;
            }
            return total + Math.max(0, Number(dose.samples) || 0);
          },
          0
        );

        return [
          row.weekday_label,
          doseTimes.length > 0 ? doseTimes.join(" | ") : "--",
          totalSamples,
        ];
      });

      renderCombinedDoseOrderChart(chartDoseOrder, combinedSeries, activeOrders, {
        ariaLabel: `Combined dose times by weekday for ${selectedMedicineLabel}`,
        referenceLines: perDoseAverages.map((row) => {
          const orderIndex = activeOrders.indexOf(row.doseOrder);
          const averageHour = Number(row.averageMinute) / 60;
          return {
            value: averageHour,
            label: `${formatOrdinal(row.doseOrder)} Avg`,
            color: doseLayerLineColor(orderIndex, activeOrders.length),
          };
        }),
      });
      return;
    }

    if (doseOrderControls) {
      doseOrderControls.hidden = false;
    }

    if (!availableOrders.includes(selectedDoseOrder)) {
      selectedDoseOrder = availableOrders[0];
    }

    renderDoseOrderControls(availableOrders);

    const medicinesForSelectedOrder = medicinesForDoseOrder(
      rawRows,
      availableMedicinesRaw,
      selectedDoseOrder
    );
    const normalizedSelectedMedicine = normalizeDoseMedicine(selectedDoseMedicine);
    if (
      medicinesForSelectedOrder.length <= 1 ||
      normalizedSelectedMedicine === null
    ) {
      selectedDoseMedicine = "all";
    } else if (
      normalizedSelectedMedicine !== "all" &&
      !medicinesForSelectedOrder.some(
        (medicine) => medicine.id === normalizedSelectedMedicine
      )
    ) {
      selectedDoseMedicine = "all";
    }
    renderDoseMedicineControls(medicinesForSelectedOrder);

    const activeMedicineFilter =
      medicinesForSelectedOrder.length > 1
        ? normalizeDoseMedicine(selectedDoseMedicine) || "all"
        : "all";
    const selectedMedicine = medicinesForSelectedOrder.find(
      (medicine) => medicine.id === activeMedicineFilter
    );
    const selectedMedicineLabel =
      activeMedicineFilter === "all"
        ? medicinesForSelectedOrder.length === 1
          ? medicinesForSelectedOrder[0].name
          : "All medicines"
        : selectedMedicine?.name || "All medicines";

    const selectedSeries = buildDoseWeekdaySeries(
      rawRows,
      selectedDoseOrder,
      activeMedicineFilter
    );
    const selectedDoseLabel = `${formatOrdinal(selectedDoseOrder)} dose`;
    const selectedAverageMinute = averageMinuteFromSeries(selectedSeries);
    const selectedAverageHour =
      selectedAverageMinute === null ? null : selectedAverageMinute / 60;
    const sampleTotal = selectedSeries.reduce(
      (total, row) => total + Math.max(0, Number(row.samples) || 0),
      0
    );
    const perDoseDosageAverages = buildDoseDosageAveragesByOrder(
      dosageRowsRaw,
      availableOrders,
      activeMedicineFilter
    );
    const selectedDoseDosage = perDoseDosageAverages.find(
      (row) => row.dose_order === selectedDoseOrder
    );
    const selectedDoseDosageText =
      selectedDoseDosage &&
      selectedDoseDosage.samples > 0 &&
      String(selectedDoseDosage.dosage_text || "").trim() !== ""
        ? String(selectedDoseDosage.dosage_text)
        : "--";
    const allDoseDosageSummary = perDoseDosageAverages
      .filter(
        (row) =>
          row.dose_order !== null &&
          row.samples > 0 &&
          String(row.dosage_text || "").trim() !== "--"
      )
      .map(
        (row) =>
          `${formatOrdinal(row.dose_order)}: ${String(row.dosage_text || "--")}`
      )
      .join(" | ");
    const allDoseDosageSummaryText =
      allDoseDosageSummary === "" ? "No dosage averages yet." : allDoseDosageSummary;

    if (chartDoseOrderMeta) {
      chartDoseOrderMeta.textContent = `${selectedDoseLabel} average time by weekday for ${selectedMedicineLabel} over the last 90 days. ${selectedDoseLabel} overall average: ${formatMinutesAsTime(
        selectedAverageMinute
      )} (${sampleTotal} samples). ${selectedDoseLabel} avg dosage: ${selectedDoseDosageText}. All dose avg dosages: ${allDoseDosageSummaryText}`;
    }

    renderTrendRows(doseOrderBody, selectedSeries, 3, (row) => [
      row.weekday_label,
      row.samples > 0 ? formatMinutesAsTime(row.avg_minute_of_day) : "--",
      row.samples,
    ]);

    const chartPoints = selectedSeries.map((row) => ({
      label: row.weekday_label,
      value:
        row.samples > 0 && Number.isFinite(Number(row.avg_minute_of_day))
          ? Number(row.avg_minute_of_day) / 60
          : Number.NaN,
    }));

    renderBarChart(chartDoseOrder, chartPoints, {
      ariaLabel: `${selectedDoseLabel} average time by weekday for ${selectedMedicineLabel}`,
      yMax: 24,
      integerTicks: true,
      keepAllPoints: true,
      enableHoverTooltip: true,
      tickFormatter: (value) => formatMinutesAsTime(value * 60, true),
      valueFormatter: (value) => formatMinutesAsTime(value * 60),
      labelFormatter: (label) => label,
      referenceLineValue: selectedAverageHour,
      referenceLineLabel: `${selectedDoseLabel} Avg`,
      referenceValueFormatter: (value) => formatMinutesAsTime(value * 60),
    });
  }

  function renderDoseIntervalTrend(trends) {
    const weeklyRows = Array.isArray(trends?.dose_interval_weekly_12_weeks)
      ? trends.dose_interval_weekly_12_weeks
      : [];
    const summary = trends?.summary || {};

    const avgIntervalThisWeekRaw = Number(
      summary.avg_dose_interval_this_week_minutes
    );
    const avgIntervalThisWeek = Number.isFinite(avgIntervalThisWeekRaw)
      ? avgIntervalThisWeekRaw
      : null;
    const intervalThisWeekSamplesRaw = Number(
      summary.dose_interval_this_week_samples
    );
    const intervalThisWeekSamples =
      Number.isFinite(intervalThisWeekSamplesRaw) && intervalThisWeekSamplesRaw > 0
        ? Math.round(intervalThisWeekSamplesRaw)
        : 0;

    const avgIntervalLast7DaysRaw = Number(
      summary.avg_dose_interval_last_7_days_minutes
    );
    const avgIntervalLast7Days = Number.isFinite(avgIntervalLast7DaysRaw)
      ? avgIntervalLast7DaysRaw
      : null;
    const intervalLast7DaysSamplesRaw = Number(
      summary.dose_interval_last_7_days_samples
    );
    const intervalLast7DaysSamples =
      Number.isFinite(intervalLast7DaysSamplesRaw) && intervalLast7DaysSamplesRaw > 0
        ? Math.round(intervalLast7DaysSamplesRaw)
        : 0;

    const avgIntervalLast90DaysRaw = Number(
      summary.avg_dose_interval_last_90_days_minutes
    );
    const avgIntervalLast90Days = Number.isFinite(avgIntervalLast90DaysRaw)
      ? avgIntervalLast90DaysRaw
      : null;
    const intervalLast90DaysSamplesRaw = Number(
      summary.dose_interval_last_90_days_samples
    );
    const intervalLast90DaysSamples =
      Number.isFinite(intervalLast90DaysSamplesRaw) && intervalLast90DaysSamplesRaw > 0
        ? Math.round(intervalLast90DaysSamplesRaw)
        : 0;

    if (metricDoseGapWeek) {
      metricDoseGapWeek.textContent =
        avgIntervalThisWeek !== null && intervalThisWeekSamples > 0
          ? formatMinutesAsDuration(avgIntervalThisWeek)
          : "--";
    }
    if (metricDoseGap7d) {
      metricDoseGap7d.textContent =
        avgIntervalLast7Days !== null && intervalLast7DaysSamples > 0
          ? formatMinutesAsDuration(avgIntervalLast7Days)
          : "--";
    }

    const weeklySummaryText =
      avgIntervalThisWeek !== null && intervalThisWeekSamples > 0
        ? `This week average gap: ${formatMinutesAsDuration(
            avgIntervalThisWeek
          )} (${intervalThisWeekSamples} gaps).`
        : "No dose-gap samples this week yet.";
    const rollingSevenDaySummaryText =
      avgIntervalLast7Days !== null && intervalLast7DaysSamples > 0
        ? `Rolling 7-day average gap: ${formatMinutesAsDuration(
            avgIntervalLast7Days
          )} (${intervalLast7DaysSamples} gaps).`
        : "No rolling 7-day dose-gap samples yet.";
    const ninetyDaySummaryText =
      avgIntervalLast90Days !== null && intervalLast90DaysSamples > 0
        ? `Last 90-day average gap: ${formatMinutesAsDuration(
            avgIntervalLast90Days
          )} (${intervalLast90DaysSamples} gaps).`
        : "No dose-gap data in the last 90 days.";

    if (chartDoseIntervalMeta) {
      chartDoseIntervalMeta.textContent = `Average time between consecutive doses by week over the last 12 weeks. ${weeklySummaryText} ${rollingSevenDaySummaryText} ${ninetyDaySummaryText}`;
    }

    const chartPoints = weeklyRows
      .map((row) => ({
        label: String(row.label || "-").replace(/^Week of\s+/i, ""),
        value:
          Number.isFinite(Number(row.avg_interval_minutes)) &&
          Number(row.avg_interval_minutes) >= 0
            ? Number(row.avg_interval_minutes) / 60
            : Number.NaN,
      }))
      .filter((point) => Number.isFinite(point.value));

    renderLineChart(chartDoseInterval, chartPoints, {
      yMin: 0,
      ariaLabel: "Average time between doses by week",
      tickFormatter: (value) => formatMinutesAsDuration(value * 60, true),
      valueFormatter: (value) => formatMinutesAsDuration(value * 60),
      labelFormatter: (label) => shortenLabel(label, 8),
    });
  }

  function renderRollingDoseIntervalTrend(trends) {
    const rollingRows = Array.isArray(trends?.dose_interval_rolling_7_days_30_days)
      ? trends.dose_interval_rolling_7_days_30_days
      : [];

    const latestRowWithSamples = [...rollingRows]
      .reverse()
      .find((row) => Number(row.samples) > 0);

    if (chartDoseIntervalRollingMeta) {
      if (
        latestRowWithSamples &&
        Number.isFinite(Number(latestRowWithSamples.avg_interval_minutes))
      ) {
        chartDoseIntervalRollingMeta.textContent = `Rolling 7-day average time between consecutive doses over the last 30 days. Latest window (${latestRowWithSamples.label || latestRowWithSamples.date || "today"}): ${formatMinutesAsDuration(
          Number(latestRowWithSamples.avg_interval_minutes)
        )} (${formatCount(latestRowWithSamples.samples)} gaps).`;
      } else {
        chartDoseIntervalRollingMeta.textContent =
          "Rolling 7-day average time between consecutive doses over the last 30 days. No rolling-window samples yet.";
      }
    }

    const chartPoints = rollingRows.map((row) => ({
      label: String(row.date || row.label || "-"),
      value:
        Number.isFinite(Number(row.avg_interval_minutes)) &&
        Number(row.avg_interval_minutes) >= 0
          ? Number(row.avg_interval_minutes) / 60
          : Number.NaN,
    }));

    renderLineChart(chartDoseIntervalRolling, chartPoints, {
      yMin: 0,
      ariaLabel: "Rolling 7-day average time between doses",
      tickFormatter: (value) => formatMinutesAsDuration(value * 60, true),
      valueFormatter: (value) => formatMinutesAsDuration(value * 60),
      labelFormatter: (label) => {
        if (/^\d{4}-\d{2}-\d{2}$/.test(label)) {
          const monthDay = label.slice(5);
          return monthDay.replace("-", "/");
        }
        return shortenLabel(label, 8);
      },
    });
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

    renderDoseOrderTrend(trends);
    renderDoseIntervalTrend(trends);
    renderRollingDoseIntervalTrend(trends);
  }

  if (!dbReady) {
    showStatus("Database unavailable.", "error");
    clearTable(monthlyBody, "Database unavailable.");
    clearTable(weeklyBody, "Database unavailable.");
    clearTable(medicinesBody, "Database unavailable.");
    clearTable(weekdayBody, "Database unavailable.");
    clearTable(doseOrderBody, "Database unavailable.");
    if (doseViewControls) {
      doseViewControls.innerHTML = "";
    }
    if (doseOrderControls) {
      doseOrderControls.hidden = true;
      doseOrderControls.innerHTML = "";
    }
    if (doseMedicineControls) {
      doseMedicineControls.hidden = true;
      doseMedicineControls.innerHTML = "";
    }
    clearAllCharts("Database unavailable.");
    return;
  }

  apiRequest("trends")
    .then((payload) => {
      latestTrends = payload.trends || {};
      renderTrends(latestTrends);
    })
    .catch((error) => {
      latestTrends = null;
      showStatus(error.message, "error");
      clearTable(monthlyBody, "Could not load trends.");
      clearTable(weeklyBody, "Could not load trends.");
      clearTable(medicinesBody, "Could not load trends.");
      clearTable(weekdayBody, "Could not load trends.");
      clearTable(doseOrderBody, "Could not load trends.");
      if (doseViewControls) {
        doseViewControls.innerHTML = "";
      }
      if (doseOrderControls) {
        doseOrderControls.hidden = true;
        doseOrderControls.innerHTML = "";
      }
      if (doseMedicineControls) {
        doseMedicineControls.hidden = true;
        doseMedicineControls.innerHTML = "";
      }
      clearAllCharts("Could not load charts.");
    });
});
