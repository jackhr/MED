document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_INVENTORY_CONFIG || {};
  const apiPath = config.apiPath || "index.php";
  const canWrite = config.canWrite !== false && config.canWrite !== "false";

  const body = document.body;
  const dbReady = body.dataset.dbReady === "1";

  const statusBanner = document.getElementById("inventory-status");
  const inventoryMeta = document.getElementById("inventory-meta");
  const inventoryBody = document.getElementById("inventory-body");
  const adjustmentsBody = document.getElementById("inventory-adjustments-body");
  const trackedCountMetric = document.getElementById("inventory-tracked-count");
  const lowStockCountMetric = document.getElementById("inventory-low-stock-count");
  const outStockCountMetric = document.getElementById("inventory-out-stock-count");
  const untrackedCountMetric = document.getElementById("inventory-untracked-count");

  const inventoryForm = document.getElementById("inventory-form");
  const inventorySubmitButton = document.getElementById("inventory-submit-btn");
  const inventoryClearButton = document.getElementById("inventory-clear-btn");
  const inventoryMedicineSelect = document.getElementById("inventory_medicine_id");
  const inventoryUnit = document.getElementById("inventory_unit");
  const inventoryStockOnHand = document.getElementById("inventory_stock_on_hand");
  const inventoryLowStockThreshold = document.getElementById(
    "inventory_low_stock_threshold"
  );
  const inventoryReorderQuantity = document.getElementById("inventory_reorder_quantity");
  const inventoryLastRestockedAt = document.getElementById(
    "inventory_last_restocked_at"
  );

  const adjustForm = document.getElementById("inventory-adjust-form");
  const adjustSubmitButton = document.getElementById("inventory-adjust-submit-btn");
  const adjustMedicineSelect = document.getElementById("inventory_adjust_medicine_id");
  const adjustChangeAmount = document.getElementById("inventory_adjust_change_amount");
  const adjustReason = document.getElementById("inventory_adjust_reason");
  const adjustNote = document.getElementById("inventory_adjust_note");

  let bannerTimer = null;
  let inventoryRows = [];

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

  function tableCell(value, className = "") {
    const cell = document.createElement("td");
    const text = value == null || value === "" ? "-" : String(value);
    cell.textContent = text;
    if (className) {
      cell.className = className;
    }
    return cell;
  }

  function emptyRow(targetBody, colSpan, text) {
    if (!targetBody) {
      return;
    }

    targetBody.innerHTML = "";
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = colSpan;
    cell.className = "empty-cell";
    cell.textContent = text;
    row.appendChild(cell);
    targetBody.appendChild(row);
  }

  function updateSummaryMetrics(summary = {}) {
    if (trackedCountMetric) {
      trackedCountMetric.textContent = String(summary.tracked_medicines ?? 0);
    }
    if (lowStockCountMetric) {
      lowStockCountMetric.textContent = String(summary.low_stock_medicines ?? 0);
    }
    if (outStockCountMetric) {
      outStockCountMetric.textContent = String(summary.out_of_stock_medicines ?? 0);
    }
    if (untrackedCountMetric) {
      untrackedCountMetric.textContent = String(summary.untracked_medicines ?? 0);
    }
  }

  function toDateTimeLocalInputValue(value) {
    const text = String(value || "").trim();
    if (text === "") {
      return "";
    }

    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) {
      return text.slice(0, 16).replace(" ", "T");
    }

    const parsed = new Date(text);
    if (!Number.isFinite(parsed.getTime())) {
      return "";
    }

    const shifted = new Date(parsed.getTime() - parsed.getTimezoneOffset() * 60000);
    return shifted.toISOString().slice(0, 16);
  }

  function findInventoryByMedicineId(medicineId) {
    return (
      inventoryRows.find((row) => Number(row.medicine_id) === Number(medicineId)) ||
      null
    );
  }

  function fillInventoryFormForMedicine(medicineId) {
    if (!canWrite || !inventoryForm) {
      return;
    }

    const row = findInventoryByMedicineId(medicineId);
    if (!row || !inventoryMedicineSelect) {
      return;
    }

    inventoryMedicineSelect.value = String(row.medicine_id || "");
    if (inventoryUnit) {
      inventoryUnit.value = String(row.unit || "mg");
    }
    if (inventoryStockOnHand) {
      inventoryStockOnHand.value = String(row.stock_on_hand || "0");
    }
    if (inventoryLowStockThreshold) {
      inventoryLowStockThreshold.value = String(row.low_stock_threshold || "0");
    }
    if (inventoryReorderQuantity) {
      inventoryReorderQuantity.value = String(row.reorder_quantity || "");
    }
    if (inventoryLastRestockedAt) {
      inventoryLastRestockedAt.value = toDateTimeLocalInputValue(row.last_restocked_at);
    }
  }

  function setFormBusy(formElement, isBusy, ignoredIds = []) {
    if (!formElement) {
      return;
    }

    const ignoredIdSet = new Set(ignoredIds);
    formElement.querySelectorAll("input, select, textarea, button").forEach((element) => {
      if (ignoredIdSet.has(element.id)) {
        return;
      }
      element.disabled = isBusy;
    });
  }

  function resetInventoryForm() {
    if (!inventoryForm) {
      return;
    }

    const selectedMedicine = inventoryMedicineSelect?.value || "";
    inventoryForm.reset();

    if (inventoryMedicineSelect && selectedMedicine !== "") {
      inventoryMedicineSelect.value = selectedMedicine;
    }
    if (inventoryUnit) {
      inventoryUnit.value = "mg";
    }
    if (inventoryStockOnHand) {
      inventoryStockOnHand.value = "0";
    }
    if (inventoryLowStockThreshold) {
      inventoryLowStockThreshold.value = "0";
    }
    if (inventoryReorderQuantity) {
      inventoryReorderQuantity.value = "";
    }
    if (inventoryLastRestockedAt) {
      inventoryLastRestockedAt.value = "";
    }
  }

  function populateMedicineSelect(selectElement, includeOnlyTracked = false) {
    if (!selectElement) {
      return;
    }

    const selectedValue = String(selectElement.value || "");
    selectElement.innerHTML = "";

    const options = includeOnlyTracked
      ? inventoryRows.filter((row) => row.tracked === true)
      : inventoryRows;

    if (!options.length) {
      const option = new Option("No medicines found", "");
      option.disabled = true;
      option.selected = true;
      selectElement.add(option);
      return;
    }

    options.forEach((row) => {
      selectElement.add(new Option(String(row.medicine_name || ""), String(row.medicine_id)));
    });

    if (selectedValue !== "" && options.some((row) => String(row.medicine_id) === selectedValue)) {
      selectElement.value = selectedValue;
      return;
    }

    selectElement.value = String(options[0].medicine_id);
  }

  function renderInventoryTable(rows) {
    if (!inventoryBody) {
      return;
    }

    inventoryBody.innerHTML = "";

    if (!Array.isArray(rows) || rows.length === 0) {
      emptyRow(
        inventoryBody,
        canWrite ? 8 : 7,
        "No medicine types available. Add medicines from the dashboard."
      );
      return;
    }

    rows.forEach((row) => {
      const tableRow = document.createElement("tr");
      tableRow.appendChild(tableCell(row.medicine_name || "-"));
      tableRow.appendChild(tableCell(row.tracked ? row.stock_display : "-"));
      tableRow.appendChild(
        tableCell(row.tracked ? row.low_stock_threshold_display : "-")
      );
      tableRow.appendChild(tableCell(row.reorder_quantity_display || "-"));
      tableRow.appendChild(tableCell(row.last_restocked_at_display || "-"));
      tableRow.appendChild(tableCell(row.estimated_days_remaining_display || "-"));

      const statusCell = document.createElement("td");
      const statusPill = document.createElement("span");
      statusPill.className = "inventory-status-pill";
      if (row.status === "In stock") {
        statusPill.classList.add("is-ok");
      } else if (row.status === "Low stock") {
        statusPill.classList.add("is-low");
      } else if (row.status === "Out of stock") {
        statusPill.classList.add("is-out");
      } else {
        statusPill.classList.add("is-untracked");
      }
      statusPill.textContent = String(row.status || "Not tracked");
      statusCell.appendChild(statusPill);
      tableRow.appendChild(statusCell);

      if (canWrite) {
        const actionsCell = document.createElement("td");
        const editButton = document.createElement("button");
        editButton.type = "button";
        editButton.className = "table-action";
        editButton.dataset.inventoryAction = "edit";
        editButton.dataset.medicineId = String(row.medicine_id || "");
        editButton.textContent = "Edit";
        actionsCell.appendChild(editButton);
        tableRow.appendChild(actionsCell);
      }

      inventoryBody.appendChild(tableRow);
    });
  }

  function renderAdjustments(rows) {
    if (!adjustmentsBody) {
      return;
    }

    adjustmentsBody.innerHTML = "";

    if (!Array.isArray(rows) || rows.length === 0) {
      emptyRow(adjustmentsBody, 7, "No adjustments logged yet.");
      return;
    }

    rows.forEach((row) => {
      const tableRow = document.createElement("tr");
      tableRow.appendChild(tableCell(row.created_at_display || row.created_at || "-"));
      tableRow.appendChild(tableCell(row.medicine_name || "-"));

      const changeCell = tableCell(row.change_display || "-");
      changeCell.classList.add("inventory-change");
      if (row.is_positive === true) {
        changeCell.classList.add("is-positive");
      } else if (row.is_negative === true) {
        changeCell.classList.add("is-negative");
      }
      tableRow.appendChild(changeCell);

      tableRow.appendChild(tableCell(row.resulting_stock_display || "-"));
      tableRow.appendChild(tableCell(row.changed_by_username || "System"));
      tableRow.appendChild(tableCell(row.reason_label || "-"));
      tableRow.appendChild(tableCell(row.note || "-"));
      adjustmentsBody.appendChild(tableRow);
    });
  }

  function updateInventoryMeta(summary = {}) {
    if (!inventoryMeta) {
      return;
    }

    const total = Number(summary.total_medicines || 0);
    const tracked = Number(summary.tracked_medicines || 0);
    const lowStock = Number(summary.low_stock_medicines || 0);
    const outOfStock = Number(summary.out_of_stock_medicines || 0);

    inventoryMeta.textContent = `${total} medicine${
      total === 1 ? "" : "s"
    } | ${tracked} tracked | ${lowStock} low | ${outOfStock} out`;
  }

  async function loadInventory() {
    const payload = await apiRequest("inventory");
    inventoryRows = Array.isArray(payload.inventory) ? payload.inventory : [];
    const summary = payload.summary || {};

    updateSummaryMetrics(summary);
    updateInventoryMeta(summary);
    renderInventoryTable(inventoryRows);

    populateMedicineSelect(inventoryMedicineSelect, false);
    populateMedicineSelect(adjustMedicineSelect, true);
  }

  async function loadAdjustments() {
    const payload = await apiRequest("inventory_adjustments");
    const rows = Array.isArray(payload.adjustments) ? payload.adjustments : [];
    renderAdjustments(rows);
  }

  if (!dbReady) {
    emptyRow(inventoryBody, canWrite ? 8 : 7, "Database unavailable.");
    emptyRow(adjustmentsBody, 7, "Database unavailable.");
    if (inventoryMeta) {
      inventoryMeta.textContent = "Database unavailable.";
    }
    return;
  }

  if (!canWrite) {
    showStatus("Read-only access enabled for this workspace.");
  }

  inventoryForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!canWrite) {
      showStatus("Read-only access: inventory updates are disabled.", "error");
      return;
    }

    const payload = {
      medicine_id: Number(inventoryMedicineSelect?.value || 0),
      stock_on_hand: String(inventoryStockOnHand?.value || "").trim(),
      unit: String(inventoryUnit?.value || "").trim(),
      low_stock_threshold: String(inventoryLowStockThreshold?.value || "").trim(),
      reorder_quantity: String(inventoryReorderQuantity?.value || "").trim(),
      last_restocked_at: String(inventoryLastRestockedAt?.value || "").trim(),
    };

    if (!payload.medicine_id || payload.stock_on_hand === "" || payload.unit === "") {
      showStatus("Please complete required inventory fields.", "error");
      return;
    }

    setFormBusy(inventoryForm, true, ["inventory-clear-btn"]);
    if (inventorySubmitButton) {
      inventorySubmitButton.textContent = "Saving...";
    }

    try {
      await apiRequest("inventory_upsert", {
        method: "POST",
        data: payload,
      });
      showStatus("Inventory saved.");
      await Promise.all([loadInventory(), loadAdjustments()]);
      fillInventoryFormForMedicine(payload.medicine_id);
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setFormBusy(inventoryForm, false, ["inventory-clear-btn"]);
      if (inventorySubmitButton) {
        inventorySubmitButton.textContent = "Save Inventory";
      }
    }
  });

  inventoryClearButton?.addEventListener("click", () => {
    resetInventoryForm();
  });

  adjustForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!canWrite) {
      showStatus("Read-only access: inventory adjustments are disabled.", "error");
      return;
    }

    const payload = {
      medicine_id: Number(adjustMedicineSelect?.value || 0),
      change_amount: String(adjustChangeAmount?.value || "").trim(),
      reason: String(adjustReason?.value || "manual_adjustment").trim(),
      note: String(adjustNote?.value || "").trim(),
    };

    if (!payload.medicine_id || payload.change_amount === "") {
      showStatus("Please choose a medicine and enter a change amount.", "error");
      return;
    }

    setFormBusy(adjustForm, true);
    if (adjustSubmitButton) {
      adjustSubmitButton.textContent = "Applying...";
    }

    try {
      const response = await apiRequest("inventory_adjust", {
        method: "POST",
        data: payload,
      });

      const result = response.result || {};
      const unit = String(result.unit || "");
      const stockValue = String(result.new_stock_on_hand || "");
      showStatus(
        stockValue !== ""
          ? `Inventory updated. New stock: ${stockValue}${unit !== "" ? ` ${unit}` : ""}.`
          : "Inventory adjustment applied."
      );
      if (adjustChangeAmount) {
        adjustChangeAmount.value = "";
      }
      if (adjustNote) {
        adjustNote.value = "";
      }
      await Promise.all([loadInventory(), loadAdjustments()]);
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setFormBusy(adjustForm, false);
      if (adjustSubmitButton) {
        adjustSubmitButton.textContent = "Apply Adjustment";
      }
    }
  });

  inventoryBody?.addEventListener("click", (event) => {
    if (!canWrite) {
      return;
    }

    const editButton = event.target.closest("button[data-inventory-action='edit']");
    if (!editButton) {
      return;
    }

    const medicineId = Number(editButton.dataset.medicineId || 0);
    if (!medicineId) {
      return;
    }

    fillInventoryFormForMedicine(medicineId);
    inventoryForm?.scrollIntoView({ behavior: "smooth", block: "start" });
  });

  Promise.all([loadInventory(), loadAdjustments()]).catch((error) => {
    showStatus(error.message, "error");
    emptyRow(inventoryBody, canWrite ? 8 : 7, "Could not load inventory.");
    emptyRow(adjustmentsBody, 7, "Could not load adjustments.");
  });
});
