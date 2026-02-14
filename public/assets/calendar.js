document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_CALENDAR_CONFIG || {};
  const apiPath = config.apiPath || "index.php";

  const body = document.body;
  const dbReady = body.dataset.dbReady === "1";

  const statusBanner = document.getElementById("calendar-status");
  const monthLabel = document.getElementById("calendar-month-label");
  const monthPicker = document.getElementById("calendar-month-picker");
  const prevButton = document.getElementById("calendar-prev");
  const nextButton = document.getElementById("calendar-next");
  const grid = document.getElementById("calendar-grid");
  const dayTitle = document.getElementById("calendar-day-title");
  const daySummary = document.getElementById("calendar-day-summary");
  const dayList = document.getElementById("calendar-day-list");

  let currentMonth = normalizeMonth(config.initialMonth) || monthKeyFromDate(new Date());
  let selectedDate = "";

  function normalizeMonth(value) {
    const text = String(value || "").trim();
    return /^\d{4}-(0[1-9]|1[0-2])$/.test(text) ? text : "";
  }

  function monthKeyFromDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    return `${year}-${month}`;
  }

  function shiftMonth(monthKey, offset) {
    const cleanMonth = normalizeMonth(monthKey);
    if (!cleanMonth) {
      return monthKeyFromDate(new Date());
    }

    const [yearText, monthText] = cleanMonth.split("-");
    const year = Number(yearText);
    const month = Number(monthText);
    const date = new Date(year, month - 1 + offset, 1);
    return monthKeyFromDate(date);
  }

  function dateLabelFromKey(dateKey) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dateKey));
    if (!match) {
      return String(dateKey || "-");
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString(undefined, {
      month: "long",
      day: "numeric",
      year: "numeric",
    });
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

  function clearStatus() {
    if (!statusBanner) {
      return;
    }

    statusBanner.hidden = true;
    statusBanner.textContent = "";
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function buildApiUrl(action, params = {}) {
    const url = new URL(apiPath, window.location.href);
    url.searchParams.set("api", action);
    Object.entries(params).forEach(([key, value]) => {
      url.searchParams.set(key, String(value));
    });
    return url.toString();
  }

  async function apiRequest(action, params = {}) {
    const response = await fetch(buildApiUrl(action, params), {
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

  function setLoadingCalendar(message) {
    if (!grid) {
      return;
    }

    grid.innerHTML = `
      <div class="calendar-cell is-empty">
        <p class="calendar-empty-text">${escapeHtml(message)}</p>
      </div>
    `;
  }

  function setEmptyDayDetails(message) {
    if (!dayTitle || !daySummary || !dayList) {
      return;
    }

    dayTitle.textContent = "Day Details";
    daySummary.textContent = message;
    dayList.innerHTML = '<li class="day-entry-item is-empty">No entries.</li>';
  }

  function renderDayDetails(dayData, dateKey) {
    if (!dayTitle || !daySummary || !dayList) {
      return;
    }

    dayTitle.textContent = dateLabelFromKey(dateKey);
    if (!dayData || !Array.isArray(dayData.entries) || dayData.entries.length === 0) {
      daySummary.textContent = "No intakes recorded on this day.";
      dayList.innerHTML = '<li class="day-entry-item is-empty">No entries logged.</li>';
      return;
    }

    const entryCount = Number(dayData.entry_count || dayData.entries.length);
    const avgRating = Number(dayData.avg_rating);
    const avgRatingText = Number.isFinite(avgRating) ? `${avgRating.toFixed(2)} / 5` : "--";
    daySummary.textContent = `${entryCount} intake${entryCount === 1 ? "" : "s"} • Avg rating ${avgRatingText}`;

    const rows = dayData.entries.map((entry) => {
      const notes = String(entry.notes || "").trim();
      const notesText = notes ? ` • ${notes}` : "";
      return `
        <li class="day-entry-item">
          <p class="day-entry-title">${escapeHtml(
            `${entry.taken_time_display || entry.taken_at_display || "-"} • ${entry.medicine_name || "-"}`
          )}</p>
          <p class="day-entry-meta">${escapeHtml(
            `${entry.dosage_display || "-"} • ${entry.rating_display || entry.rating || "-"}${notesText}`
          )}</p>
        </li>
      `;
    });

    dayList.innerHTML = rows.join("");
  }

  function renderCalendar(calendar) {
    if (!grid || !monthLabel || !monthPicker) {
      return;
    }

    currentMonth = normalizeMonth(calendar.month) || currentMonth;

    monthLabel.textContent = calendar.month_label || currentMonth;
    monthPicker.value = currentMonth;

    const daysInMonth = Number(calendar.days_in_month || 0);
    const firstWeekday = Number(calendar.first_weekday || 0);
    const today = String(calendar.today || "");

    const dayMap = new Map();
    if (Array.isArray(calendar.days)) {
      calendar.days.forEach((day) => {
        const dayNumber = Number(day.day || 0);
        if (dayNumber > 0) {
          dayMap.set(dayNumber, day);
        }
      });
    }

    grid.innerHTML = "";
    for (let index = 0; index < firstWeekday; index += 1) {
      const emptyCell = document.createElement("div");
      emptyCell.className = "calendar-cell is-empty";
      grid.appendChild(emptyCell);
    }

    let fallbackSelectableDate = "";
    for (let day = 1; day <= daysInMonth; day += 1) {
      const dateKey = `${currentMonth}-${String(day).padStart(2, "0")}`;
      const dayData = dayMap.get(day) || null;
      const entryCount = dayData ? Number(dayData.entry_count || 0) : 0;
      const previewEntries =
        dayData && Array.isArray(dayData.entries) ? dayData.entries.slice(0, 3) : [];
      const remainingEntries = Math.max(0, entryCount - previewEntries.length);

      if (!fallbackSelectableDate && entryCount > 0) {
        fallbackSelectableDate = dateKey;
      }

      const cell = document.createElement("button");
      cell.type = "button";
      cell.className = "calendar-cell";
      if (entryCount > 0) {
        cell.classList.add("is-active");
      }
      if (today === dateKey) {
        cell.classList.add("is-today");
      }

      const header = document.createElement("div");
      header.className = "calendar-cell-head";
      const dayNumber = document.createElement("span");
      dayNumber.className = "calendar-day-number";
      dayNumber.textContent = String(day);
      header.appendChild(dayNumber);

      if (entryCount > 0) {
        const count = document.createElement("span");
        count.className = "calendar-day-count";
        count.textContent = String(entryCount);
        header.appendChild(count);
      }

      cell.appendChild(header);

      previewEntries.forEach((entry) => {
        const pill = document.createElement("p");
        pill.className = "calendar-entry-pill";
        pill.textContent = `${entry.taken_time_display || "-"} • ${entry.medicine_name || "-"}`;
        cell.appendChild(pill);
      });

      if (remainingEntries > 0) {
        const more = document.createElement("p");
        more.className = "calendar-more";
        more.textContent = `+${remainingEntries} more`;
        cell.appendChild(more);
      }

      cell.addEventListener("click", () => {
        selectedDate = dateKey;
        renderDayDetails(dayData, dateKey);
        renderCalendarSelection();
      });

      cell.dataset.dateKey = dateKey;
      grid.appendChild(cell);
    }

    const preferredDate = selectedDate;
    const todayInMonth = today.startsWith(`${currentMonth}-`) ? today : "";
    if (
      preferredDate &&
      preferredDate.startsWith(`${currentMonth}-`) &&
      Number(preferredDate.slice(-2)) <= daysInMonth
    ) {
      selectedDate = preferredDate;
    } else if (todayInMonth) {
      selectedDate = todayInMonth;
    } else if (fallbackSelectableDate) {
      selectedDate = fallbackSelectableDate;
    } else if (daysInMonth > 0) {
      selectedDate = `${currentMonth}-01`;
    } else {
      selectedDate = "";
    }

    const selectedDayData = selectedDate
      ? dayMap.get(Number(selectedDate.slice(-2))) || null
      : null;
    renderDayDetails(selectedDayData, selectedDate || `${currentMonth}-01`);
    renderCalendarSelection();
  }

  function renderCalendarSelection() {
    if (!grid || !selectedDate) {
      return;
    }

    const cells = grid.querySelectorAll(".calendar-cell");
    cells.forEach((cell) => {
      const dateKey = cell.dataset.dateKey || "";
      if (dateKey === selectedDate) {
        cell.classList.add("is-selected");
      } else {
        cell.classList.remove("is-selected");
      }
    });
  }

  function updateBrowserMonth(monthKey) {
    const url = new URL(window.location.href);
    url.searchParams.set("month", monthKey);
    window.history.replaceState({}, "", url.toString());
  }

  async function loadCalendar(monthKey) {
    const safeMonth = normalizeMonth(monthKey) || monthKeyFromDate(new Date());
    currentMonth = safeMonth;
    setLoadingCalendar("Loading calendar...");
    clearStatus();

    const payload = await apiRequest("calendar", { month: safeMonth });
    const calendar = payload.calendar || {};
    renderCalendar(calendar);
    updateBrowserMonth(calendar.month || safeMonth);
  }

  if (!dbReady) {
    showStatus("Database unavailable.", "error");
    setLoadingCalendar("Database unavailable.");
    setEmptyDayDetails("Database unavailable.");
    return;
  }

  prevButton?.addEventListener("click", () => {
    loadCalendar(shiftMonth(currentMonth, -1)).catch((error) => {
      showStatus(error.message, "error");
    });
  });

  nextButton?.addEventListener("click", () => {
    loadCalendar(shiftMonth(currentMonth, 1)).catch((error) => {
      showStatus(error.message, "error");
    });
  });

  monthPicker?.addEventListener("change", () => {
    const selectedMonth = normalizeMonth(monthPicker.value);
    if (!selectedMonth) {
      return;
    }

    loadCalendar(selectedMonth).catch((error) => {
      showStatus(error.message, "error");
    });
  });

  loadCalendar(currentMonth).catch((error) => {
    showStatus(error.message, "error");
    setLoadingCalendar("Could not load calendar.");
    setEmptyDayDetails("Could not load day details.");
  });
});
