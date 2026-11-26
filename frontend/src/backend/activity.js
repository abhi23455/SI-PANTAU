import { apiRequest } from "./client";

export async function getTeamActivityLogs(groupId) {
  return apiRequest(`/activity-logs?group_id=${encodeURIComponent(groupId)}&limit=50`);
}
