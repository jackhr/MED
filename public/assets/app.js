document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_LOG_CONFIG || {};
  const apiPath = config.apiPath || window.location.pathname;

  const body = document.body;
  const dbReady = body.dataset.dbReady === "1";

  const statusBanner = document.getElementById("status-banner");
  const metricToday = document.getElementById("metric-today");
  const metricWeek = document.getElementById("metric-week");
  const metricMedicines = document.getElementById("metric-medicines");

  const createForm = document.getElementById("create-intake-form");
  const createSubmitButton = createForm?.querySelector("button[type='submit']");
  const createTakenAt = document.getElementById("taken_at");
  const createDosageValue = document.getElementById("dosage_value");
  const createDosageUnit = document.getElementById("dosage_unit");
  const createRatingWidget = document.getElementById("create-rating-widget");
  const createRating = document.getElementById("rating");

  const entriesBody = document.getElementById("entries-body");
  const tableMeta = document.getElementById("table-meta");
  const pagination = document.getElementById("pagination");

  const modal = document.getElementById("edit-modal");
  const editForm = document.getElementById("edit-intake-form");
  const editSubmitButton = editForm?.querySelector("button[type='submit']");
  const deleteEntryButton = document.getElementById("delete-entry-btn");
  const editId = document.getElementById("edit_id");
  const editDosageValue = document.getElementById("edit_dosage_value");
  const editDosageUnit = document.getElementById("edit_dosage_unit");
  const editRatingWidget = document.getElementById("edit-rating-widget");
  const editRating = document.getElementById("edit_rating");
  const editTakenAt = document.getElementById("edit_taken_at");
  const editNotes = document.getElementById("edit_notes");

  const createMedicineContext = {
    picker: document.getElementById("create-medicine-picker"),
    select: document.getElementById("medicine_select"),
    custom: document.getElementById("medicine_custom"),
  };
  const editMedicineContext = {
    picker: document.getElementById("edit-medicine-picker"),
    select: document.getElementById("edit_medicine_select"),
    custom: document.getElementById("edit_medicine_custom"),
  };

  let currentPage = 1;
  let totalPages = 1;
  let bannerTimer = null;
  let medicines = [];
  let mostRecentMedicine = null;
  const entriesById = new Map();

  function buildApiUrl(action, params = {}) {
    const url = new URL(apiPath, window.location.origin);
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

  function localDateTimeInputValue() {
    const now = new Date();
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 16);
  }

  function showStatus(message, tone = "success") {
    if (!statusBanner) {
      return;
    }

    if (bannerTimer) {
      window.clearTimeout(bannerTimer);
      bannerTimer = null;
    }

    statusBanner.hidden = false;
    statusBanner.textContent = message;
    statusBanner.className =
      tone === "error" ? "alert alert-error" : "alert alert-success";

    bannerTimer = window.setTimeout(() => {
      statusBanner.hidden = true;
    }, 3200);
  }

  function escapeText(value) {
    return value == null || value === "" ? "-" : String(value);
  }

  function createCell(text, className = "") {
    const cell = document.createElement("td");
    cell.textContent = escapeText(text);
    if (className) {
      cell.className = className;
    }
    return cell;
  }

  function clearEntriesTable(message) {
    if (!entriesBody) {
      return;
    }

    entriesBody.innerHTML = "";
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.className = "empty-cell";
    cell.colSpan = 6;
    cell.textContent = message;
    row.appendChild(cell);
    entriesBody.appendChild(row);
  }

  function renderEntries(entries) {
    if (!entriesBody) {
      return;
    }

    entriesById.clear();
    entriesBody.innerHTML = "";

    if (!entries.length) {
      clearEntriesTable("No entries yet.");
      return;
    }

    const groupedEntries = [];
    entries.forEach((entry) => {
      const dayKey =
        entry.taken_day_key ||
        (typeof entry.taken_at === "string" ? entry.taken_at.slice(0, 10) : "");
      const dayLabel = entry.taken_day_display || entry.taken_at_display || dayKey;

      const currentGroup = groupedEntries[groupedEntries.length - 1];
      if (currentGroup && currentGroup.dayKey === dayKey) {
        currentGroup.entries.push(entry);
      } else {
        groupedEntries.push({
          dayKey,
          dayLabel,
          entries: [entry],
        });
      }
    });

    groupedEntries.forEach((group) => {
      if (group.entries.length > 1) {
        const dayRow = document.createElement("tr");
        dayRow.className = "day-group-row";
        const dayCell = document.createElement("td");
        dayCell.colSpan = 6;
        const intakeLabel = group.entries.length === 1 ? "intake" : "intakes";
        dayCell.textContent = `${group.dayLabel} (${group.entries.length} ${intakeLabel})`;
        dayRow.appendChild(dayCell);
        entriesBody.appendChild(dayRow);
      }

      group.entries.forEach((entry) => {
        entriesById.set(entry.id, entry);

        const isNested = group.entries.length > 1;
        const row = document.createElement("tr");
        row.className = isNested ? "entry-row is-nested" : "entry-row";
        const whenText = isNested
          ? entry.taken_time_display || entry.taken_at_display
          : entry.taken_at_display;

        row.appendChild(createCell(whenText, isNested ? "nested-time" : ""));
        row.appendChild(createCell(entry.medicine_name));
        row.appendChild(createCell(entry.dosage_display));
        row.appendChild(
          createCell(entry.rating_display || entry.rating || "-", "rating-cell")
        );
        row.appendChild(createCell(entry.notes || ""));

        const actionsCell = document.createElement("td");
        const editButton = document.createElement("button");
        editButton.type = "button";
        editButton.className = "table-action";
        editButton.dataset.action = "edit";
        editButton.dataset.id = String(entry.id);
        editButton.textContent = "Edit";
        actionsCell.appendChild(editButton);
        row.appendChild(actionsCell);

        entriesBody.appendChild(row);
      });
    });
  }

  function createPageButton(label, pageNumber, isDisabled, isCurrent = false) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "page-btn";
    button.textContent = label;
    button.disabled = isDisabled;

    if (!isDisabled) {
      button.dataset.page = String(pageNumber);
    }

    if (isCurrent) {
      button.classList.add("is-current");
    }

    return button;
  }

  function renderPagination() {
    if (!pagination) {
      return;
    }

    pagination.innerHTML = "";

    if (totalPages <= 1) {
      return;
    }

    pagination.appendChild(
      createPageButton("Previous", currentPage - 1, currentPage === 1)
    );

    const startPage = Math.max(1, currentPage - 2);
    const endPage = Math.min(totalPages, currentPage + 2);

    for (let page = startPage; page <= endPage; page += 1) {
      pagination.appendChild(
        createPageButton(
          String(page),
          page,
          page === currentPage,
          page === currentPage
        )
      );
    }

    pagination.appendChild(
      createPageButton("Next", currentPage + 1, currentPage === totalPages)
    );
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

      paintRatingWidget(widget, normalizeRating(input.value), normalizeRating(target.dataset.star));
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

  function setFormBusy(form, isBusy) {
    if (!form) {
      return;
    }

    const submitButton = form.querySelector("button[type='submit']");
    if (submitButton) {
      submitButton.disabled = isBusy;
    }
  }

  function setModalBusy(isBusy) {
    if (editSubmitButton) {
      editSubmitButton.disabled = isBusy;
    }
    if (deleteEntryButton) {
      deleteEntryButton.disabled = isBusy;
    }
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
    const createCurrent = getCurrentMedicinePreference(createMedicineContext);
    const editCurrent = getCurrentMedicinePreference(editMedicineContext);

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
    syncMedicineContext(editMedicineContext, editCurrent);
  }

  async function loadMetrics() {
    const payload = await apiRequest("dashboard");
    metricToday.textContent = String(payload.metrics.entries_today);
    metricWeek.textContent = String(payload.metrics.entries_this_week);
    metricMedicines.textContent = String(payload.metrics.unique_medicines);
  }

  async function loadEntries(page = 1, options = {}) {
    const shouldSyncCreateDefault = options.syncCreateDefault === true;
    const payload = await apiRequest("entries", {
      params: {
        page,
      },
    });

    const paginationData = payload.pagination;
    currentPage = paginationData.page;
    totalPages = paginationData.total_pages;

    if (paginationData.page === 1 && Array.isArray(payload.entries)) {
      if (payload.entries.length > 0) {
        const latestEntry = payload.entries[0];
        mostRecentMedicine = {
          id: Number(latestEntry.medicine_id) || 0,
          name: String(latestEntry.medicine_name || ""),
        };

        if (shouldSyncCreateDefault && mostRecentMedicine.id > 0) {
          syncMedicineContext(createMedicineContext, mostRecentMedicine);
          setMedicinePickerMode(createMedicineContext, "existing");
        }
      } else {
        mostRecentMedicine = null;
      }
    }

    renderEntries(payload.entries);

    if (tableMeta) {
      tableMeta.textContent = `Page ${paginationData.page} of ${paginationData.total_pages} | ${paginationData.total_entries} total entries`;
    }

    renderPagination();
  }

  function openEditModal(entry) {
    if (!modal || !editForm || !entry) {
      return;
    }

    editId.value = String(entry.id);
    editDosageValue.value = entry.dosage_value || "20";
    editDosageUnit.value = entry.dosage_unit || "mg";
    if (editRating) {
      setRating(editRating, editRatingWidget, entry.rating || 3);
    }
    editTakenAt.value = entry.taken_at_input || localDateTimeInputValue();
    editNotes.value = entry.notes || "";
    syncMedicineContext(editMedicineContext, {
      id: Number(entry.medicine_id) || 0,
      name: entry.medicine_name || "",
    });

    modal.hidden = false;
    body.classList.add("modal-open");
  }

  function closeEditModal() {
    if (!modal || !editForm) {
      return;
    }

    modal.hidden = true;
    body.classList.remove("modal-open");
    editForm.reset();
    if (editDosageValue) {
      editDosageValue.value = "20";
    }
    if (editDosageUnit) {
      editDosageUnit.value = "mg";
    }
    if (editRating) {
      setRating(editRating, editRatingWidget, 3);
    }
    syncMedicineContext(editMedicineContext);
  }

  function validateRequiredFields(payload) {
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

  if (!dbReady) {
    clearEntriesTable("Database unavailable.");
    return;
  }

  setupMedicineTabs(createMedicineContext);
  setupMedicineTabs(editMedicineContext);
  setupRatingWidget(createRatingWidget, createRating);
  setupRatingWidget(editRatingWidget, editRating);
  syncMedicineContext(createMedicineContext);
  syncMedicineContext(editMedicineContext);

  if (createTakenAt && !createTakenAt.value) {
    createTakenAt.value = localDateTimeInputValue();
  }
  if (createDosageValue && !createDosageValue.value) {
    createDosageValue.value = "20";
  }
  if (createDosageUnit && !createDosageUnit.value) {
    createDosageUnit.value = "mg";
  }
  if (createRating && !createRating.value) {
    setRating(createRating, createRatingWidget, 3);
  } else {
    setRating(createRating, createRatingWidget, createRating?.value || 3);
  }
  if (editRating && !editRating.value) {
    setRating(editRating, editRatingWidget, 3);
  } else {
    setRating(editRating, editRatingWidget, editRating?.value || 3);
  }

  createForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const medicinePayload = getMedicinePayload(createMedicineContext);
    const payload = {
      ...medicinePayload,
      dosage_value: createForm.dosage_value.value.trim(),
      dosage_unit: createForm.dosage_unit.value.trim(),
      rating: createRating ? createRating.value.trim() : "",
      taken_at: createForm.taken_at.value.trim(),
      notes: createForm.notes.value.trim(),
    };

    if (!validateRequiredFields(payload)) {
      showStatus("Please complete all required fields.", "error");
      return;
    }

    setFormBusy(createForm, true);
    if (createSubmitButton) {
      createSubmitButton.textContent = "Saving...";
    }

    try {
      await apiRequest("create", {
        method: "POST",
        data: payload,
      });

      showStatus("Entry saved.");
      createForm.reset();
      if (createTakenAt) {
        createTakenAt.value = localDateTimeInputValue();
      }
      if (createDosageValue) {
        createDosageValue.value = "20";
      }
      if (createDosageUnit) {
        createDosageUnit.value = "mg";
      }
      if (createRating) {
        setRating(createRating, createRatingWidget, 3);
      }

      await Promise.all([loadMedicines(), loadMetrics(), loadEntries(1)]);
      if (mostRecentMedicine && mostRecentMedicine.id > 0) {
        syncMedicineContext(createMedicineContext, mostRecentMedicine);
        setMedicinePickerMode(createMedicineContext, "existing");
      } else {
        setMedicinePickerMode(
          createMedicineContext,
          medicines.length > 0 ? "existing" : "new"
        );
      }
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setFormBusy(createForm, false);
      if (createSubmitButton) {
        createSubmitButton.textContent = "Save Intake";
      }
    }
  });

  entriesBody?.addEventListener("click", (event) => {
    const target = event.target.closest("button[data-action='edit']");
    if (!target) {
      return;
    }

    const id = Number(target.dataset.id);
    const entry = entriesById.get(id);
    if (!entry) {
      showStatus("Could not load that entry.", "error");
      return;
    }

    openEditModal(entry);
  });

  editForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const medicinePayload = getMedicinePayload(editMedicineContext);
    const payload = {
      id: Number(editId.value),
      ...medicinePayload,
      dosage_value: editDosageValue.value.trim(),
      dosage_unit: editDosageUnit.value.trim(),
      rating: editRating ? editRating.value.trim() : "",
      taken_at: editTakenAt.value.trim(),
      notes: editNotes.value.trim(),
    };

    if (!payload.id || !validateRequiredFields(payload)) {
      showStatus("Please complete all required fields.", "error");
      return;
    }

    setModalBusy(true);
    if (editSubmitButton) {
      editSubmitButton.textContent = "Saving...";
    }

    try {
      await apiRequest("update", {
        method: "POST",
        data: payload,
      });

      closeEditModal();
      showStatus("Entry updated.");
      await Promise.all([loadMedicines(), loadMetrics(), loadEntries(currentPage)]);
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setModalBusy(false);
      if (editSubmitButton) {
        editSubmitButton.textContent = "Save Changes";
      }
    }
  });

  deleteEntryButton?.addEventListener("click", async () => {
    const id = Number(editId.value);
    if (!id) {
      showStatus("Missing entry ID for delete.", "error");
      return;
    }

    const shouldDelete = window.confirm("Delete this entry permanently?");
    if (!shouldDelete) {
      return;
    }

    setModalBusy(true);
    deleteEntryButton.textContent = "Deleting...";

    try {
      await apiRequest("delete", {
        method: "POST",
        data: { id },
      });

      closeEditModal();
      showStatus("Entry deleted.");
      await Promise.all([loadMedicines(), loadMetrics(), loadEntries(currentPage)]);
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setModalBusy(false);
      deleteEntryButton.textContent = "Delete Entry";
    }
  });

  pagination?.addEventListener("click", async (event) => {
    const target = event.target.closest("button[data-page]");
    if (!target || target.disabled) {
      return;
    }

    const page = Number(target.dataset.page);
    if (!page || page === currentPage) {
      return;
    }

    try {
      await loadEntries(page);
    } catch (error) {
      showStatus(error.message, "error");
    }
  });

  modal?.addEventListener("click", (event) => {
    const closeButton = event.target.closest("[data-close-modal='true']");
    if (closeButton) {
      closeEditModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal && !modal.hidden) {
      closeEditModal();
    }
  });

  Promise.all([loadMedicines(), loadMetrics(), loadEntries(1)]).then(() => {
    if (mostRecentMedicine && mostRecentMedicine.id > 0) {
      syncMedicineContext(createMedicineContext, mostRecentMedicine);
      setMedicinePickerMode(createMedicineContext, "existing");
    }
  }).catch((error) => {
    showStatus(error.message, "error");
    clearEntriesTable("Could not load entries.");
  });
});
