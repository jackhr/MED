document.addEventListener("DOMContentLoaded", () => {
  const config = window.MEDICINE_SETTINGS_CONFIG || {};
  const apiPath = String(config.apiPath || "index.php");

  const body = document.body;
  const dbReady = body.dataset.dbReady === "1";

  const statusBanner = document.getElementById("settings-status");
  const accountMeta = document.getElementById("account-meta");
  const signedInUsernameText = document.getElementById("signed-in-username");
  const accountForm = document.getElementById("account-form");
  const accountSubmitButton = document.getElementById("account-submit-btn");
  const accountUsernameInput = document.getElementById("account_username");
  const accountCurrentPasswordInput = document.getElementById(
    "account_current_password"
  );
  const accountNewPasswordInput = document.getElementById("account_new_password");
  const accountConfirmPasswordInput = document.getElementById(
    "account_confirm_password"
  );

  let currentAccountUsername = String(accountUsernameInput?.value || "").trim();

  function buildApiUrl(action) {
    const url = new URL(apiPath, window.location.origin);
    url.searchParams.set("api", action);
    return url.toString();
  }

  async function apiRequest(action, options = {}) {
    const method = String(options.method || "GET");
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

    const response = await fetch(buildApiUrl(action), requestConfig);
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

  function setFormBusy(isBusy) {
    if (!accountForm) {
      return;
    }

    accountForm.querySelectorAll("input, button").forEach((element) => {
      element.disabled = isBusy;
    });
  }

  function clearPasswordFields() {
    if (accountCurrentPasswordInput) {
      accountCurrentPasswordInput.value = "";
    }
    if (accountNewPasswordInput) {
      accountNewPasswordInput.value = "";
    }
    if (accountConfirmPasswordInput) {
      accountConfirmPasswordInput.value = "";
    }
  }

  async function loadAccount() {
    if (!accountUsernameInput) {
      return;
    }

    const payload = await apiRequest("account");
    const account = payload.account || {};
    const username = String(account.username || "").trim();

    if (!username) {
      throw new Error("Could not load account username.");
    }

    accountUsernameInput.value = username;
    currentAccountUsername = username;

    if (signedInUsernameText) {
      signedInUsernameText.textContent = username;
    }

    if (accountMeta) {
      const lastLogin = String(account.last_login_at || "").trim();
      accountMeta.textContent =
        lastLogin !== ""
          ? `Last login: ${lastLogin}. Update your username and password.`
          : "Update your username and password.";
    }
  }

  if (!dbReady) {
    setFormBusy(true);
    showStatus("Database unavailable. Update your .env settings first.", "error");
    return;
  }

  accountForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const username = String(accountUsernameInput?.value || "").trim();
    const currentPassword = String(accountCurrentPasswordInput?.value || "");
    const newPassword = String(accountNewPasswordInput?.value || "");
    const confirmPassword = String(accountConfirmPasswordInput?.value || "");
    const hasUsernameChange = username !== currentAccountUsername;
    const hasPasswordChange = newPassword !== "" || confirmPassword !== "";

    if (username === "") {
      showStatus("Username is required.", "error");
      return;
    }

    if (!hasUsernameChange && !hasPasswordChange) {
      showStatus("No account changes to save.", "error");
      return;
    }

    if (currentPassword === "") {
      showStatus("Current password is required to update account details.", "error");
      return;
    }

    if (hasPasswordChange) {
      if (newPassword.length < 8) {
        showStatus("New password must be at least 8 characters.", "error");
        return;
      }

      if (newPassword !== confirmPassword) {
        showStatus("New password confirmation does not match.", "error");
        return;
      }
    }

    setFormBusy(true);
    if (accountSubmitButton) {
      accountSubmitButton.textContent = "Updating...";
    }

    try {
      const payload = await apiRequest("account_update", {
        method: "POST",
        data: {
          username,
          current_password: currentPassword,
          new_password: newPassword,
          confirm_password: confirmPassword,
        },
      });

      const accountUsername = String(payload?.account?.username || username).trim();
      if (accountUsernameInput) {
        accountUsernameInput.value = accountUsername;
      }
      currentAccountUsername = accountUsername;

      if (signedInUsernameText) {
        signedInUsernameText.textContent = accountUsername;
      }

      clearPasswordFields();
      if (accountMeta) {
        accountMeta.textContent = "Account details updated.";
      }

      showStatus(payload.message || "Account updated.");
    } catch (error) {
      showStatus(error.message, "error");
    } finally {
      setFormBusy(false);
      if (accountSubmitButton) {
        accountSubmitButton.textContent = "Update Account";
      }
    }
  });

  clearPasswordFields();
  loadAccount().catch((error) => {
    showStatus(error.message, "error");
  });
});
