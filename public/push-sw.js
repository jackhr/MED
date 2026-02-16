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

    try {
      const response = await fetch("/index.php?api=push_message", {
        method: "GET",
        credentials: "include",
        cache: "no-store",
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
