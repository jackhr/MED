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
  const accountDisplayNameInput = document.getElementById("account_display_name");
  const accountEmailInput = document.getElementById("account_email");
  const accountCurrentPasswordInput = document.getElementById(
    "account_current_password"
  );
  const accountNewPasswordInput = document.getElementById("account_new_password");
  const accountConfirmPasswordInput = document.getElementById(
    "account_confirm_password"
  );

  const currentAccountProfile = {
    username: String(accountUsernameInput?.value || "").trim(),
    display_name: String(accountDisplayNameInput?.value || "").trim(),
    email: String(accountEmailInput?.value || "")
      .trim()
      .toLowerCase(),
  };

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

  function accountLabel(account) {
    const displayName = String(account?.display_name || "").trim();
    if (displayName !== "") {
      return displayName;
    }

    return String(account?.username || "").trim();
  }

  async function loadAccount() {
    if (!accountUsernameInput) {
      return;
    }

    const payload = await apiRequest("account");
    const account = payload.account || {};
    const username = String(account.username || "").trim();
    const displayName = String(account.display_name || "").trim();
    const email = String(account.email || "")
      .trim()
      .toLowerCase();

    if (!username) {
      throw new Error("Could not load account username.");
    }

    accountUsernameInput.value = username;
    if (accountDisplayNameInput) {
      accountDisplayNameInput.value = displayName;
    }
    if (accountEmailInput) {
      accountEmailInput.value = email;
    }
    currentAccountProfile.username = username;
    currentAccountProfile.display_name = displayName;
    currentAccountProfile.email = email;

    if (signedInUsernameText) {
      signedInUsernameText.textContent = accountLabel({
        username,
        display_name: displayName,
      });
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
    const displayName = String(accountDisplayNameInput?.value || "").trim();
    const email = String(accountEmailInput?.value || "")
      .trim()
      .toLowerCase();
    const currentPassword = String(accountCurrentPasswordInput?.value || "");
    const newPassword = String(accountNewPasswordInput?.value || "");
    const confirmPassword = String(accountConfirmPasswordInput?.value || "");
    const hasUsernameChange = username !== currentAccountProfile.username;
    const hasDisplayNameChange = displayName !== currentAccountProfile.display_name;
    const hasEmailChange = email !== currentAccountProfile.email;
    const hasPasswordChange = newPassword !== "" || confirmPassword !== "";

    if (username === "") {
      showStatus("Username is required.", "error");
      return;
    }

    if (
      !hasUsernameChange &&
      !hasDisplayNameChange &&
      !hasEmailChange &&
      !hasPasswordChange
    ) {
      showStatus("No account changes to save.", "error");
      return;
    }

    if (email !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showStatus("Email format is invalid.", "error");
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
          display_name: displayName,
          email,
          current_password: currentPassword,
          new_password: newPassword,
          confirm_password: confirmPassword,
        },
      });

      const accountUsername = String(payload?.account?.username || username).trim();
      const accountDisplayName = String(
        payload?.account?.display_name || displayName
      ).trim();
      const accountEmail = String(payload?.account?.email || email)
        .trim()
        .toLowerCase();
      if (accountUsernameInput) {
        accountUsernameInput.value = accountUsername;
      }
      if (accountDisplayNameInput) {
        accountDisplayNameInput.value = accountDisplayName;
      }
      if (accountEmailInput) {
        accountEmailInput.value = accountEmail;
      }
      currentAccountProfile.username = accountUsername;
      currentAccountProfile.display_name = accountDisplayName;
      currentAccountProfile.email = accountEmail;

      if (signedInUsernameText) {
        signedInUsernameText.textContent = accountLabel({
          username: accountUsername,
          display_name: accountDisplayName,
        });
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
