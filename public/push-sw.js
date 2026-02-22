self.addEventListener("install", (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener("activate", (event) => {
  event.waitUntil(clients.claim());
});

self.addEventListener("push", (event) => {
  const fallbackPayload = {
    title: "Medicine reminder",
    body: "It is time to log a scheduled dose.",
    url: "/index.php",
  };

  const loadPayload = async () => {
    let payload = { ...fallbackPayload };

    if (event.data) {
      try {
        const parsed = event.data.json();
        payload = {
          ...payload,
          ...parsed,
        };
      } catch (error) {
        const text = event.data.text();
        if (text && text.trim() !== "") {
          payload.body = text.trim();
        }
      }
    }

    let subscriptionEndpoint = "";
    try {
      const subscription = await self.registration.pushManager.getSubscription();
      if (subscription && typeof subscription.endpoint === "string") {
        subscriptionEndpoint = subscription.endpoint;
      }
    } catch (error) {
      // Session-based auth may still work without endpoint fallback.
    }

    try {
      const params = new URLSearchParams();
      params.set("api", "push_message");
      if (subscriptionEndpoint !== "") {
        params.set("endpoint", subscriptionEndpoint);
      }

      const response = await fetch(`/index.php?${params.toString()}`, {
        method: "GET",
        credentials: "include",
        cache: "no-store",
        headers: {
          Accept: "application/json",
        },
      });
      if (!response.ok) {
        return payload;
      }

      const apiPayload = await response.json();
      if (!apiPayload || apiPayload.ok !== true || !apiPayload.notification) {
        return payload;
      }

      return {
        ...payload,
        ...apiPayload.notification,
      };
    } catch (error) {
      return payload;
    }
  };

  event.waitUntil(
    loadPayload().then((payload) => {
      const notificationOptions = {
        body: payload.body,
        data: {
          url: payload.url || "/index.php",
        },
        renotify: true,
        tag: "medicine-reminder",
      };

      return self.registration.showNotification(payload.title, notificationOptions);
    })
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl =
    event.notification?.data?.url && typeof event.notification.data.url === "string"
      ? event.notification.data.url
      : "/index.php";

  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if ("focus" in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }

      return undefined;
    })
  );
});
