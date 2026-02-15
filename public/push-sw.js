self.addEventListener("push", (event) => {
  let payload = {
    title: "Medicine reminder",
    body: "It is time to log a scheduled dose.",
    url: "/index.php",
  };

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

  const notificationOptions = {
    body: payload.body,
    data: {
      url: payload.url || "/index.php",
    },
    renotify: true,
    tag: "medicine-reminder",
  };

  event.waitUntil(self.registration.showNotification(payload.title, notificationOptions));
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
