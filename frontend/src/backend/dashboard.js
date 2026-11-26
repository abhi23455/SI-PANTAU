import { apiRequest } from "./client";

export async function getAdminStats() {
  return apiRequest("/dashboard/admin-stats");
}

export async function getPersonalStats() {
  return apiRequest("/dashboard/personal-stats");
}

export async function getPersonalLogs() {
  return apiRequest("/activity-logs?limit=20");
}

export async function getTaskReport(filters = {}) {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value);
  });
  const query = params.toString();
  return apiRequest(`/dashboard/task-report${query ? `?${query}` : ""}`);
}

export async function exportTaskReport(filters = {}) {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value);
  });
  const query = params.toString();
  return apiRequest(`/dashboard/task-report/export${query ? `?${query}` : ""}`);
}

export async function getNotifications() {
  return apiRequest("/notifications");
}

export async function markNotificationRead(notificationId) {
  return apiRequest(`/notifications/${encodeURIComponent(notificationId)}/read`, {
    method: "POST",
  });
}

export async function markAllNotificationsRead() {
  return apiRequest("/notifications/read-all", {
    method: "POST",
  });
}

export async function logActivity(userId, description, taskId = null, groupId = null) {
  if (!description) throw new Error("Deskripsi aktivitas wajib diisi.");
  return apiRequest("/activity-logs", {
    method: "POST",
    body: {
      description,
      task_id: taskId,
      group_id: groupId,
      user_id: userId,
    },
  });
}
