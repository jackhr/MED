document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_CALENDAR_CONFIG || {};
  const apiPath = config.apiPath || "index.php";
  const canWrite = config.canWrite !== false && config.canWrite !== "false";

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

  const openCreateModalButton = document.getElementById("calendar-open-create-modal-btn");
  const createModal = document.getElementById("calendar-create-modal");
  const createForm = document.getElementById("calendar-create-intake-form");
  const createSubmitButton = createForm?.querySelector("button[type='submit']");
  const createTakenAt = document.getElementById("calendar-taken-at");
  const createDosageValue = document.getElementById("calendar-dosage-value");
  const createDosageUnit = document.getElementById("calendar-dosage-unit");
  const createRatingWidget = document.getElementById("calendar-create-rating-widget");
  const createRating = document.getElementById("calendar-rating");

  const createMedicineContext = {
    picker: document.getElementById("calendar-create-medicine-picker"),
    select: document.getElementById("calendar-medicine-select"),
    custom: document.getElementById("calendar-medicine-custom"),
  };

  let currentMonth = normalizeMonth(config.initialMonth) || monthKeyFromDate(new Date());
  let selectedDate = "";
  let medicines = [];

  function normalizeMonth(value) {
    const text = String(value || "").trim();
    return /^\d{4}-(0[1-9]|1[0-2])$/.test(text) ? text : "";
  }

  function normalizeDateKey(value) {
    const text = String(value || "").trim();
    return /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/.test(text) ? text : "";
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

  function localDateTimeInputValue() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 16);
  }

  function dateTimeInputValueForDate(dateKey) {
    const normalizedDate = normalizeDateKey(dateKey);
    if (!normalizedDate) {
      return localDateTimeInputValue();
    }

    const now = new Date();
    const hours = String(now.getHours()).padStart(2, "0");
    const minutes = String(now.getMinutes()).padStart(2, "0");
    return `${normalizedDate}T${hours}:${minutes}`;
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
      .replace(/\"/g, "&quot;")
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

  async function apiRequest(action, options = {}) {
    const method = options.method || "GET";
    const params = options.params || {};
    const data = options.data;

    const requestConfig = {
      method,
      headers: {
        Accept: "application/json",
      },
    };

    if (data !== undefined) {
      requestConfig.headers["Content-Type"] = "application/json";
      requestConfig.body = JSON.stringify(data);
    }

    const response = await fetch(buildApiUrl(action, params), requestConfig);

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

  function normalizeRating(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return 3;
    }

    return Math.max(1, Math.min(5, Math.round(numeric)));
  }

  function paintRatingWidget(widget, selected, preview = 0) {
    if (!widget) {
      return;
    }

    const active = preview > 0 ? preview : selected;
    widget.querySelectorAll("button[data-star]").forEach((button) => {
      const starValue = normalizeRating(button.dataset.star);
      button.classList.toggle("is-active", starValue <= active);
      button.setAttribute("aria-checked", starValue === selected ? "true" : "false");
    });
  }

  function setRating(input, widget, value) {
    if (!input) {
      return;
    }

    const selected = normalizeRating(value);
    input.value = String(selected);
    paintRatingWidget(widget, selected);
  }

  function setupRatingWidget(widget, input) {
    if (!widget || !input) {
      return;
    }

    setRating(input, widget, input.value);

    widget.addEventListener("mouseover", (event) => {
      const target = event.target.closest("button[data-star]");
      if (!target || !widget.contains(target)) {
        return;
      }

      paintRatingWidget(
        widget,
        normalizeRating(input.value),
        normalizeRating(target.dataset.star)
      );
    });

    widget.addEventListener("mouseleave", () => {
      paintRatingWidget(widget, normalizeRating(input.value));
    });

    widget.addEventListener("click", (event) => {
      const target = event.target.closest("button[data-star]");
      if (!target || !widget.contains(target)) {
        return;
      }

      setRating(input, widget, target.dataset.star);
    });
  }

  function setMedicinePickerMode(context, mode) {
    const picker = context.picker;
    const select = context.select;
    const custom = context.custom;

    if (!picker || !select || !custom) {
      return;
    }

    const resolvedMode = mode === "new" ? "new" : "existing";
    picker.dataset.mode = resolvedMode;

    picker.querySelectorAll("[data-tab-mode]").forEach((button) => {
      const isActive = button.dataset.tabMode === resolvedMode;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-selected", isActive ? "true" : "false");
    });

    picker.querySelectorAll("[data-panel-mode]").forEach((panel) => {
      panel.hidden = panel.dataset.panelMode !== resolvedMode;
    });

    select.disabled = resolvedMode !== "existing" || medicines.length === 0;
    select.required = resolvedMode === "existing" && medicines.length > 0;
    custom.disabled = resolvedMode !== "new";
    custom.required = resolvedMode === "new";

    if (!canWrite) {
      select.disabled = true;
      custom.disabled = true;
    }
  }

  function getMedicinePayload(context) {
    const picker = context.picker;
    const select = context.select;
    const custom = context.custom;
    if (!picker || !select || !custom) {
      return {
        medicine_mode: "new",
        medicine_id: 0,
        medicine_name: "",
      };
    }

    const mode = picker.dataset.mode === "new" ? "new" : "existing";
    if (mode === "new") {
      return {
        medicine_mode: "new",
        medicine_id: 0,
        medicine_name: custom.value.trim(),
      };
    }

    return {
      medicine_mode: "existing",
      medicine_id: Number(select.value) || 0,
      medicine_name: "",
    };
  }

  function getCurrentMedicinePreference(context) {
    const payload = getMedicinePayload(context);

    if (payload.medicine_mode === "existing" && payload.medicine_id > 0) {
      const existing = medicines.find((item) => item.id === payload.medicine_id);
      if (existing) {
        return { id: existing.id, name: existing.name };
      }
      return null;
    }

    if (payload.medicine_mode === "new" && payload.medicine_name) {
      return { id: 0, name: payload.medicine_name };
    }

    return null;
  }

  function populateMedicineSelect(select, preferredId = 0) {
    if (!select) {
      return;
    }

    select.innerHTML = "";

    if (!medicines.length) {
      const option = new Option("No medicine types yet", "");
      option.disabled = true;
      option.selected = true;
      select.add(option);
      return;
    }

    medicines.forEach((medicine) => {
      select.add(new Option(medicine.name, String(medicine.id)));
    });

    if (preferredId > 0 && medicines.some((item) => item.id === preferredId)) {
      select.value = String(preferredId);
    } else {
      select.value = String(medicines[0].id);
    }
  }

  function syncMedicineContext(context, preferred = null) {
    const picker = context.picker;
    const select = context.select;
    const custom = context.custom;
    if (!picker || !select || !custom) {
      return;
    }

    const preferredId = preferred && preferred.id ? Number(preferred.id) : 0;
    const preferredName = preferred && preferred.name ? String(preferred.name) : "";

    populateMedicineSelect(select, preferredId);

    if (preferredId > 0 && medicines.some((item) => item.id === preferredId)) {
      custom.value = "";
      setMedicinePickerMode(context, "existing");
      return;
    }

    if (preferredName !== "") {
      custom.value = preferredName;
      setMedicinePickerMode(context, "new");
      return;
    }

    if (!medicines.length) {
      custom.value = "";
      setMedicinePickerMode(context, "new");
      return;
    }

    const keepMode = picker.dataset.mode === "new" ? "new" : "existing";
    if (keepMode === "new") {
      setMedicinePickerMode(context, "new");
      return;
    }

    custom.value = "";
    setMedicinePickerMode(context, "existing");
  }

  function setupMedicineTabs(context) {
    const picker = context.picker;
    if (!picker) {
      return;
    }

    picker.addEventListener("click", (event) => {
      const tabButton = event.target.closest("button[data-tab-mode]");
      if (!tabButton) {
        return;
      }

      const nextMode = tabButton.dataset.tabMode === "new" ? "new" : "existing";
      if (nextMode === "existing" && medicines.length === 0) {
        showStatus("No medicine types yet. Add a new medicine first.", "error");
        return;
      }

      setMedicinePickerMode(context, nextMode);
      if (nextMode === "new" && context.custom) {
        context.custom.focus();
      }
    });
  }

  async function loadMedicines() {
    if (!createForm) {
      return;
    }

    const createCurrent = getCurrentMedicinePreference(createMedicineContext);
    const payload = await apiRequest("medicines");
    medicines = Array.isArray(payload.medicines)
      ? payload.medicines
          .map((item) => ({
            id: Number(item.id),
            name: String(item.name || ""),
          }))
          .filter((item) => item.id > 0 && item.name !== "")
      : [];

    syncMedicineContext(createMedicineContext, createCurrent);
  }

  function syncModalOpenState() {
    const isCreateModalOpen = Boolean(createModal && !createModal.hidden);
    body.classList.toggle("modal-open", isCreateModalOpen);
  }

  function setCreateFormBusy(isBusy) {
    if (!createForm) {
      return;
    }

    const submitButton = createForm.querySelector("button[type='submit']");
    if (submitButton) {
      submitButton.disabled = isBusy;
    }
  }

  function applyCreateFormDefaults() {
    if (!createForm) {
      return;
    }

    createForm.reset();

    if (createDosageValue) {
      createDosageValue.value = "20";
    }
    if (createDosageUnit) {
      createDosageUnit.value = "mg";
    }
    if (createRating) {
      setRating(createRating, createRatingWidget, 3);
    }
    if (createTakenAt) {
      createTakenAt.value = dateTimeInputValueForDate(selectedDate);
    }

    if (!medicines.length) {
      setMedicinePickerMode(createMedicineContext, "new");
      return;
    }

    syncMedicineContext(createMedicineContext);
    setMedicinePickerMode(createMedicineContext, "existing");
  }

  function openCreateModal() {
    if (!canWrite || !createModal || !createForm) {
      return;
    }

    applyCreateFormDefaults();
    createModal.hidden = false;
    syncModalOpenState();
  }

  function closeCreateModal(resetForm = false) {
    if (!createModal) {
      return;
    }

    createModal.hidden = true;
    syncModalOpenState();

    if (resetForm) {
      applyCreateFormDefaults();
    }
  }

  function validateCreatePayload(payload) {
    const hasMedicine =
      (payload.medicine_mode === "existing" && payload.medicine_id > 0) ||
      (payload.medicine_mode === "new" && payload.medicine_name !== "");

    return (
      hasMedicine &&
      payload.dosage_value !== "" &&
      payload.dosage_unit !== "" &&
      payload.rating !== "" &&
      payload.taken_at !== ""
    );
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
            `${entry.taken_time_display || entry.taken_at_display || "-"} • ${entry.medicine_name || "-"} • ${entry.logged_by_username || "-"}`
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

    const payload = await apiRequest("calendar", {
      params: { month: safeMonth },
    });
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

  setupMedicineTabs(createMedicineContext);
  setupRatingWidget(createRatingWidget, createRating);

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

  openCreateModalButton?.addEventListener("click", () => {
    openCreateModal();
  });

  createModal?.addEventListener("click", (event) => {
    const closeButton = event.target.closest("[data-close-calendar-create-modal='true']");
    if (closeButton) {
      closeCreateModal(true);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }

    if (createModal && !createModal.hidden) {
      closeCreateModal(true);
    }
  });

  createForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!canWrite) {
      showStatus("Read-only access: entry logging is disabled.", "error");
      return;
    }

    const medicinePayload = getMedicinePayload(createMedicineContext);
    const payload = {
      ...medicinePayload,
      dosage_value: createForm.dosage_value.value.trim(),
      dosage_unit: createForm.dosage_unit.value.trim(),
      rating: createRating ? createRating.value.trim() : "",
      taken_at: createForm.taken_at.value.trim(),
      notes: createForm.notes.value.trim(),
    };

    if (!validateCreatePayload(payload)) {
      showStatus("Please complete all required fields.", "error");
      return;
    }

    setCreateFormBusy(true);
    if (createSubmitButton) {
      createSubmitButton.textContent = "Saving...";
    }

    try {
      const takenDateKey = normalizeDateKey(payload.taken_at.slice(0, 10));
      if (takenDateKey && takenDateKey.startsWith(`${currentMonth}-`)) {
        selectedDate = takenDateKey;
      }

      await apiRequest("create", {
        method: "POST",
        data: payload,
      });

      closeCreateModal(true);
      await Promise.all([loadMedicines(), loadCalendar(currentMonth)]);
      showStatus("Entry saved.");
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setCreateFormBusy(false);
      if (createSubmitButton) {
        createSubmitButton.textContent = "Save Intake";
      }
    }
  });

  Promise.all([loadMedicines(), loadCalendar(currentMonth)]).catch((error) => {
    showStatus(error.message, "error");
    setLoadingCalendar("Could not load calendar.");
    setEmptyDayDetails("Could not load day details.");
  });
});
