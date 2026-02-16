document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_SCHEDULES_CONFIG || {};
  const apiPath = config.apiPath || "index.php";

  const body = document.body;
  const dbReady = body.dataset.dbReady === "1";

  const statusBanner = document.getElementById("schedules-status");
  const schedulesBody = document.getElementById("schedules-body");
  const scheduleForm = document.getElementById("schedule-form");
  const scheduleSubmitButton = document.getElementById("schedule-submit-btn");
  const scheduleMedicineSelect = document.getElementById("schedule_medicine_id");
  const scheduleDosageValue = document.getElementById("schedule_dosage_value");
  const scheduleDosageUnit = document.getElementById("schedule_dosage_unit");
  const scheduleTimeOfDay = document.getElementById("schedule_time_of_day");
  const scheduleReminderMessage = document.getElementById(
    "schedule_reminder_message"
  );
  const scheduleIsActive = document.getElementById("schedule_is_active");
  const scheduleEditModal = document.getElementById("schedule-edit-modal");
  const scheduleEditForm = document.getElementById("schedule-edit-form");
  const scheduleEditToggleButton = document.getElementById(
    "schedule-edit-toggle-btn"
  );
  const scheduleEditSubmitButton = document.getElementById(
    "schedule-edit-submit-btn"
  );
  const scheduleEditId = document.getElementById("schedule_edit_id");
  const scheduleEditMedicineSelect = document.getElementById(
    "schedule_edit_medicine_id"
  );
  const scheduleEditDosageValue = document.getElementById(
    "schedule_edit_dosage_value"
  );
  const scheduleEditDosageUnit = document.getElementById(
    "schedule_edit_dosage_unit"
  );
  const scheduleEditTimeOfDay = document.getElementById(
    "schedule_edit_time_of_day"
  );
  const scheduleEditReminderMessage = document.getElementById(
    "schedule_edit_reminder_message"
  );
  const scheduleEditIsActive = document.getElementById(
    "schedule_edit_is_active"
  );
  const scheduleMeta = document.getElementById("schedule-meta");
  const pushStatus = document.getElementById("push-status");
  const enablePushButton = document.getElementById("enable-push-btn");
  const disablePushButton = document.getElementById("disable-push-btn");
  const testPushButton = document.getElementById("test-push-btn");
  const runRemindersButton = document.getElementById("run-reminders-btn");

  const pushPublicKey = String(config.pushPublicKey || "");
  const pushConfigured = Boolean(config.pushConfigured) && pushPublicKey !== "";

  let bannerTimer = null;
  let medicines = [];
  let schedules = [];
  let currentEditingScheduleId = null;
  let serviceWorkerRegistration = null;
  let currentPushSubscription = null;

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

  function updatePushStatus(message, tone = "neutral") {
    if (!pushStatus) {
      return;
    }

    pushStatus.textContent = message;
    pushStatus.classList.remove("push-ok", "push-error");
    if (tone === "ok") {
      pushStatus.classList.add("push-ok");
    }
    if (tone === "error") {
      pushStatus.classList.add("push-error");
    }
  }

  function findScheduleById(scheduleId) {
    return (
      schedules.find((schedule) => Number(schedule.id) === Number(scheduleId)) || null
    );
  }

  function syncScheduleEditToggleButton() {
    if (!scheduleEditToggleButton) {
      return;
    }

    const schedule =
      currentEditingScheduleId !== null
        ? findScheduleById(currentEditingScheduleId)
        : null;

    if (!schedule) {
      scheduleEditToggleButton.hidden = true;
      scheduleEditToggleButton.disabled = true;
      scheduleEditToggleButton.textContent = "Pause Schedule";
      scheduleEditToggleButton.dataset.active = "";
      return;
    }

    const isActive = Boolean(schedule.is_active);
    scheduleEditToggleButton.hidden = false;
    scheduleEditToggleButton.disabled = false;
    scheduleEditToggleButton.dataset.active = isActive ? "1" : "0";
    scheduleEditToggleButton.textContent = isActive
      ? "Pause Schedule"
      : "Resume Schedule";
  }

  function isScheduleEditModalOpen() {
    return Boolean(scheduleEditModal && !scheduleEditModal.hidden);
  }

  function openScheduleEditModal(scheduleId) {
    const schedule = findScheduleById(scheduleId);
    if (!schedule) {
      showStatus("Schedule not found. Refresh and try again.", "error");
      return;
    }

    currentEditingScheduleId = Number(schedule.id);
    if (scheduleEditId) {
      scheduleEditId.value = String(schedule.id);
    }

    populateScheduleEditMedicineSelect(String(schedule.medicine_id || ""));

    if (scheduleEditDosageValue) {
      scheduleEditDosageValue.value = String(schedule.dosage_value || "20");
    }
    if (scheduleEditDosageUnit) {
      scheduleEditDosageUnit.value = String(schedule.dosage_unit || "mg");
    }
    if (scheduleEditTimeOfDay) {
      scheduleEditTimeOfDay.value = String(schedule.time_of_day_input || "").slice(0, 5);
    }
    if (scheduleEditReminderMessage) {
      scheduleEditReminderMessage.value = String(schedule.reminder_message || "");
    }
    if (scheduleEditIsActive) {
      scheduleEditIsActive.checked = Boolean(schedule.is_active);
    }
    if (scheduleEditSubmitButton) {
      scheduleEditSubmitButton.textContent = "Save Schedule";
    }
    syncScheduleEditToggleButton();
    if (scheduleEditModal) {
      scheduleEditModal.hidden = false;
      body.classList.add("modal-open");
    }
  }

  function populateMedicineSelect(selectElement, preferredValue = "") {
    if (!selectElement) {
      return;
    }

    const selectedValue =
      preferredValue !== "" ? preferredValue : String(selectElement.value || "");
    selectElement.innerHTML = "";

    if (!medicines.length) {
      const option = new Option("No medicine types yet", "");
      option.disabled = true;
      option.selected = true;
      selectElement.add(option);
      return;
    }

    medicines.forEach((medicine) => {
      selectElement.add(new Option(medicine.name, String(medicine.id)));
    });

    if (
      selectedValue !== "" &&
      medicines.some((item) => String(item.id) === selectedValue)
    ) {
      selectElement.value = selectedValue;
      return;
    }

    selectElement.value = String(medicines[0].id);
  }

  function populateScheduleMedicineSelect() {
    populateMedicineSelect(scheduleMedicineSelect);
  }

  function populateScheduleEditMedicineSelect(preferredValue = "") {
    populateMedicineSelect(scheduleEditMedicineSelect, preferredValue);
  }

  function resetScheduleEditForm() {
    if (!scheduleEditForm) {
      return;
    }

    scheduleEditForm.reset();
    if (scheduleEditId) {
      scheduleEditId.value = "";
    }
    if (scheduleEditDosageValue) {
      scheduleEditDosageValue.value = "20";
    }
    if (scheduleEditDosageUnit) {
      scheduleEditDosageUnit.value = "mg";
    }
    if (scheduleEditIsActive) {
      scheduleEditIsActive.checked = true;
    }
    if (scheduleEditReminderMessage) {
      scheduleEditReminderMessage.value = "";
    }
    populateScheduleEditMedicineSelect();
    syncScheduleEditToggleButton();
  }

  function closeScheduleEditModal() {
    if (scheduleEditModal) {
      scheduleEditModal.hidden = true;
      body.classList.remove("modal-open");
    }
    currentEditingScheduleId = null;
    resetScheduleEditForm();
  }

  function createCell(text, className = "") {
    const cell = document.createElement("td");
    cell.textContent = text == null || text === "" ? "-" : String(text);
    if (className) {
      cell.className = className;
    }
    return cell;
  }

  async function loadMedicines() {
    const payload = await apiRequest("medicines");
    medicines = Array.isArray(payload.medicines)
      ? payload.medicines
          .map((item) => ({
            id: Number(item.id),
            name: String(item.name || ""),
          }))
          .filter((item) => item.id > 0 && item.name !== "")
      : [];

    populateScheduleMedicineSelect();
    populateScheduleEditMedicineSelect();
  }

  function renderSchedules(rows) {
    if (!schedulesBody) {
      return;
    }

    schedulesBody.innerHTML = "";

    if (!Array.isArray(rows) || rows.length === 0) {
      const emptyRow = document.createElement("tr");
      const emptyCell = document.createElement("td");
      emptyCell.className = "empty-cell";
      emptyCell.colSpan = 6;
      emptyCell.textContent = "No schedules yet.";
      emptyRow.appendChild(emptyCell);
      schedulesBody.appendChild(emptyRow);
      return;
    }

    rows.forEach((schedule) => {
      const row = document.createElement("tr");
      row.appendChild(createCell(schedule.time_label || schedule.time_of_day_input || "-"));
      row.appendChild(createCell(schedule.medicine_name || "-"));
      row.appendChild(createCell(schedule.dosage_display || "-"));
      row.appendChild(createCell(schedule.reminder_message || ""));
      row.appendChild(createCell(schedule.is_active ? "Active" : "Paused"));

      const actionsCell = document.createElement("td");
      const editButton = document.createElement("button");
      editButton.type = "button";
      editButton.className = "table-action";
      editButton.dataset.scheduleAction = "edit";
      editButton.dataset.id = String(schedule.id);
      editButton.textContent = "Edit";

      const deleteButton = document.createElement("button");
      deleteButton.type = "button";
      deleteButton.className = "table-action schedule-delete-btn";
      deleteButton.dataset.scheduleAction = "delete";
      deleteButton.dataset.id = String(schedule.id);
      deleteButton.textContent = "Delete";

      actionsCell.appendChild(editButton);
      actionsCell.appendChild(deleteButton);
      row.appendChild(actionsCell);
      schedulesBody.appendChild(row);
    });
  }

  async function loadSchedules() {
    if (!scheduleMeta) {
      return;
    }

    const payload = await apiRequest("schedules");
    schedules = Array.isArray(payload.schedules) ? payload.schedules : [];
    renderSchedules(schedules);

    if (
      currentEditingScheduleId !== null &&
      !findScheduleById(currentEditingScheduleId)
    ) {
      closeScheduleEditModal();
    } else if (isScheduleEditModalOpen()) {
      syncScheduleEditToggleButton();
    }

    const activeCount = schedules.filter((schedule) => schedule.is_active).length;
    scheduleMeta.textContent = `${schedules.length} schedule${
      schedules.length === 1 ? "" : "s"
    } (${activeCount} active)`;
  }

  function setScheduleFormBusy(isBusy) {
    if (!scheduleForm) {
      return;
    }

    scheduleForm.querySelectorAll("input, select, button").forEach((element) => {
      if (element.id === "schedule_is_active") {
        return;
      }
      element.disabled = isBusy;
    });
  }

  function setScheduleEditFormBusy(isBusy) {
    if (!scheduleEditForm) {
      return;
    }

    scheduleEditForm.querySelectorAll("input, select, button").forEach((element) => {
      if (element.id === "schedule_edit_is_active") {
        return;
      }
      element.disabled = isBusy;
    });
  }

  function resetScheduleForm() {
    if (!scheduleForm) {
      return;
    }

    scheduleForm.reset();
    if (scheduleDosageValue) {
      scheduleDosageValue.value = "20";
    }
    if (scheduleDosageUnit) {
      scheduleDosageUnit.value = "mg";
    }
    if (scheduleIsActive) {
      scheduleIsActive.checked = true;
    }
    if (scheduleTimeOfDay && !scheduleTimeOfDay.value) {
      const now = new Date();
      now.setMinutes(now.getMinutes() + 30);
      scheduleTimeOfDay.value = `${String(now.getHours()).padStart(2, "0")}:${String(
        now.getMinutes()
      ).padStart(2, "0")}`;
    }
    if (scheduleReminderMessage) {
      scheduleReminderMessage.value = "";
    }

    populateScheduleMedicineSelect();
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
    const raw = window.atob(base64);
    const output = new Uint8Array(raw.length);
    for (let index = 0; index < raw.length; index += 1) {
      output[index] = raw.charCodeAt(index);
    }
    return output;
  }

  function isIosDevice() {
    const userAgent = navigator.userAgent || "";
    const platform = navigator.platform || "";
    return (
      /iPad|iPhone|iPod/.test(userAgent) ||
      (platform === "MacIntel" && Number(navigator.maxTouchPoints || 0) > 1)
    );
  }

  function isStandaloneMode() {
    const mediaMatch =
      typeof window.matchMedia === "function" &&
      window.matchMedia("(display-mode: standalone)").matches;
    const legacyStandalone = typeof navigator.standalone === "boolean" && navigator.standalone;
    return Boolean(mediaMatch || legacyStandalone);
  }

  function pushUnsupportedMessage() {
    if (isIosDevice() && !isStandaloneMode()) {
      return "On iPhone/iPad, add this site to Home Screen to enable push notifications.";
    }

    return "Push notifications are not supported in this browser context.";
  }

  function getPushManager(registration) {
    if (!registration || !registration.pushManager) {
      throw new Error(pushUnsupportedMessage());
    }

    return registration.pushManager;
  }

  async function ensureServiceWorker() {
    if (!("serviceWorker" in navigator)) {
      throw new Error("Service workers are not supported in this browser.");
    }

    if (serviceWorkerRegistration) {
      return serviceWorkerRegistration;
    }

    const serviceWorkerPath = new URL("push-sw.js", window.location.href).pathname;
    await navigator.serviceWorker.register(serviceWorkerPath);
    serviceWorkerRegistration = await navigator.serviceWorker.ready;

    if (!serviceWorkerRegistration) {
      throw new Error("Service worker did not become ready.");
    }

    return serviceWorkerRegistration;
  }

  function syncPushButtons() {
    const subscribed = Boolean(currentPushSubscription);
    if (enablePushButton) {
      enablePushButton.disabled = !pushConfigured || subscribed;
    }
    if (disablePushButton) {
      disablePushButton.disabled = !subscribed;
    }
    if (testPushButton) {
      testPushButton.disabled = !pushConfigured || !subscribed;
    }
  }

  async function saveBrowserSubscription(subscription) {
    const payload = subscription ? subscription.toJSON() : null;
    if (!payload) {
      throw new Error("Could not read browser subscription details.");
    }

    await apiRequest("push_subscribe", {
      method: "POST",
      data: {
        endpoint: payload.endpoint,
        keys: payload.keys || {},
        user_agent: navigator.userAgent || "",
      },
    });
  }

  async function enablePushNotifications() {
    if (!pushConfigured) {
      throw new Error("Push is not configured on the server.");
    }
    if (!("PushManager" in window)) {
      throw new Error(pushUnsupportedMessage());
    }
    if (Notification.permission === "denied") {
      throw new Error("Notifications are blocked for this site.");
    }

    const registration = await ensureServiceWorker();
    const pushManager = getPushManager(registration);
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      throw new Error("Notification permission was not granted.");
    }

    let subscription = await pushManager.getSubscription();
    if (!subscription) {
      subscription = await pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(pushPublicKey),
      });
    }

    await saveBrowserSubscription(subscription);
    currentPushSubscription = subscription;
    syncPushButtons();
    updatePushStatus("Push enabled for this browser.", "ok");
  }

  async function disablePushNotifications() {
    const registration = await ensureServiceWorker();
    const pushManager = getPushManager(registration);
    const subscription = currentPushSubscription || (await pushManager.getSubscription());

    if (!subscription) {
      currentPushSubscription = null;
      syncPushButtons();
      updatePushStatus("Push is not enabled in this browser.");
      return;
    }

    const payload = subscription.toJSON();
    await apiRequest("push_unsubscribe", {
      method: "POST",
      data: {
        endpoint: payload.endpoint || "",
      },
    });

    await subscription.unsubscribe();
    currentPushSubscription = null;
    syncPushButtons();
    updatePushStatus("Push disabled for this browser.");
  }

  async function initializePushState() {
    if (!pushConfigured) {
      updatePushStatus("Push is not configured. Add VAPID keys in .env.", "error");
      syncPushButtons();
      return;
    }

    try {
      const registration = await ensureServiceWorker();
      const pushManager = getPushManager(registration);
      currentPushSubscription = await pushManager.getSubscription();
      syncPushButtons();

      if (currentPushSubscription) {
        await saveBrowserSubscription(currentPushSubscription);
        updatePushStatus("Push enabled for this browser.", "ok");
      } else if (Notification.permission === "denied") {
        updatePushStatus("Notifications are blocked in this browser.", "error");
      } else {
        updatePushStatus("Push is available. Enable it to receive reminders.");
      }
    } catch (error) {
      updatePushStatus(error.message || "Could not initialize push.", "error");
    }
  }

  if (!dbReady) {
    if (scheduleMeta) {
      scheduleMeta.textContent = "Database unavailable.";
    }
    if (schedulesBody) {
      schedulesBody.innerHTML =
        '<tr><td class="empty-cell" colspan="6">Database unavailable.</td></tr>';
    }
    updatePushStatus("Database unavailable.", "error");
    return;
  }

  syncPushButtons();
  if (!pushConfigured) {
    updatePushStatus("Push is not configured. Add VAPID keys in .env.", "error");
  }

  scheduleForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const payload = {
      medicine_id: Number(scheduleMedicineSelect?.value || 0),
      dosage_value: String(scheduleDosageValue?.value || "").trim(),
      dosage_unit: String(scheduleDosageUnit?.value || "").trim(),
      time_of_day: String(scheduleTimeOfDay?.value || "").trim(),
      reminder_message: String(scheduleReminderMessage?.value || "").trim(),
      is_active: scheduleIsActive?.checked ? 1 : 0,
    };

    if (!payload.medicine_id || !payload.dosage_value || !payload.dosage_unit || !payload.time_of_day) {
      showStatus("Please complete all schedule fields.", "error");
      return;
    }

    setScheduleFormBusy(true);
    if (scheduleSubmitButton) {
      scheduleSubmitButton.textContent = "Adding...";
    }

    try {
      await apiRequest("schedule_create", {
        method: "POST",
        data: payload,
      });

      showStatus("Schedule added.");
      resetScheduleForm();
      await loadSchedules();
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setScheduleFormBusy(false);
      if (scheduleSubmitButton) {
        scheduleSubmitButton.textContent = "Add Schedule";
      }
    }
  });

  scheduleEditForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const scheduleId = Number(
      scheduleEditId?.value || currentEditingScheduleId || 0
    );
    const payload = {
      id: scheduleId,
      medicine_id: Number(scheduleEditMedicineSelect?.value || 0),
      dosage_value: String(scheduleEditDosageValue?.value || "").trim(),
      dosage_unit: String(scheduleEditDosageUnit?.value || "").trim(),
      time_of_day: String(scheduleEditTimeOfDay?.value || "").trim(),
      reminder_message: String(scheduleEditReminderMessage?.value || "").trim(),
      is_active: scheduleEditIsActive?.checked ? 1 : 0,
    };

    if (
      !payload.id ||
      !payload.medicine_id ||
      !payload.dosage_value ||
      !payload.dosage_unit ||
      !payload.time_of_day
    ) {
      showStatus("Please complete all schedule edit fields.", "error");
      return;
    }

    setScheduleEditFormBusy(true);
    if (scheduleEditSubmitButton) {
      scheduleEditSubmitButton.textContent = "Saving...";
    }

    try {
      await apiRequest("schedule_update", {
        method: "POST",
        data: payload,
      });
      closeScheduleEditModal();
      showStatus("Schedule updated.");
      await loadSchedules();
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setScheduleEditFormBusy(false);
      if (scheduleEditSubmitButton) {
        scheduleEditSubmitButton.textContent = "Save Schedule";
      }
    }
  });

  scheduleEditToggleButton?.addEventListener("click", async () => {
    const schedule =
      currentEditingScheduleId !== null
        ? findScheduleById(currentEditingScheduleId)
        : null;

    if (!schedule) {
      closeScheduleEditModal();
      showStatus("Schedule not found. Refresh and try again.", "error");
      return;
    }

    const nextActive = schedule.is_active ? 0 : 1;

    setScheduleEditFormBusy(true);
    if (scheduleEditToggleButton) {
      scheduleEditToggleButton.textContent = schedule.is_active
        ? "Pausing..."
        : "Resuming...";
    }

    try {
      await apiRequest("schedule_toggle", {
        method: "POST",
        data: {
          id: Number(schedule.id),
          is_active: nextActive,
        },
      });

      await loadSchedules();
      const refreshedSchedule = findScheduleById(Number(schedule.id));
      if (scheduleEditIsActive) {
        scheduleEditIsActive.checked = Boolean(refreshedSchedule?.is_active ?? nextActive);
      }
      syncScheduleEditToggleButton();
      showStatus(nextActive === 1 ? "Schedule resumed." : "Schedule paused.");
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setScheduleEditFormBusy(false);
      syncScheduleEditToggleButton();
    }
  });

  schedulesBody?.addEventListener("click", async (event) => {
    const actionButton = event.target.closest("button[data-schedule-action]");
    if (!actionButton) {
      return;
    }

    const scheduleId = Number(actionButton.dataset.id || 0);
    if (!scheduleId) {
      return;
    }

    if (actionButton.dataset.scheduleAction === "edit") {
      openScheduleEditModal(scheduleId);
      return;
    }

    if (actionButton.dataset.scheduleAction === "delete") {
      const confirmed = window.confirm("Delete this schedule?");
      if (!confirmed) {
        return;
      }

      try {
        await apiRequest("schedule_delete", {
          method: "POST",
          data: { id: scheduleId },
        });
        if (currentEditingScheduleId === scheduleId && isScheduleEditModalOpen()) {
          closeScheduleEditModal();
        }
        showStatus("Schedule deleted.");
        await loadSchedules();
      } catch (error) {
        showStatus(error.message, "error");
      }
      return;
    }

  });

  scheduleEditModal?.addEventListener("click", (event) => {
    const closeButton = event.target.closest("[data-close-schedule-modal='true']");
    if (closeButton) {
      closeScheduleEditModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && isScheduleEditModalOpen()) {
      closeScheduleEditModal();
    }
  });

  enablePushButton?.addEventListener("click", async () => {
    try {
      await enablePushNotifications();
      showStatus("Push notifications enabled.");
    } catch (error) {
      showStatus(error.message, "error");
      updatePushStatus(error.message, "error");
    }
  });

  disablePushButton?.addEventListener("click", async () => {
    try {
      await disablePushNotifications();
      showStatus("Push notifications disabled.");
    } catch (error) {
      showStatus(error.message, "error");
      updatePushStatus(error.message, "error");
    }
  });

  testPushButton?.addEventListener("click", async () => {
    try {
      const payload = await apiRequest("push_test", {
        method: "POST",
      });
      const dispatch = payload.dispatch || {};
      showStatus(
        `Test push sent: ${dispatch.sent || 0}/${dispatch.attempted || 0} delivered.`
      );
    } catch (error) {
      showStatus(error.message, "error");
    }
  });

  runRemindersButton?.addEventListener("click", async () => {
    const button = runRemindersButton;
    if (button) {
      button.disabled = true;
      button.textContent = "Running...";
    }

    try {
      const payload = await apiRequest("process_reminders", {
        method: "POST",
      });
      const result = payload.result || {};
      showStatus(
        `Reminder check complete. Due: ${result.due || 0}, sent: ${result.sent || 0}.`
      );
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = "Run Reminder Check";
      }
    }
  });

  Promise.all([loadMedicines(), loadSchedules()])
    .then(async () => {
      resetScheduleForm();
      closeScheduleEditModal();
      await initializePushState();
    })
    .catch((error) => {
      showStatus(error.message, "error");
      if (scheduleMeta) {
        scheduleMeta.textContent = "Schedules unavailable. Run latest DB migrations.";
      }
      if (schedulesBody) {
        schedulesBody.innerHTML =
          '<tr><td class="empty-cell" colspan="6">Schedules unavailable.</td></tr>';
      }
    });
});
