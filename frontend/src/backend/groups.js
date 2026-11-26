import { apiRequest } from "./client";

const groupCache = new Map();

export function clearGroupCache(groupId) {
  if (groupId) groupCache.delete(String(groupId));
  else groupCache.clear();
}

export async function getUserGroups() {
  return apiRequest("/groups");
}

export async function getGroupDetails(groupId, { forceRefresh = false } = {}) {
  const key = String(groupId);
  if (!forceRefresh && groupCache.has(key)) return groupCache.get(key);

  const group = await apiRequest(`/groups/${encodeURIComponent(groupId)}`);
  groupCache.set(key, group);
  return group;
}

export async function createGroup(groupData, members = []) {
  const group = await apiRequest("/groups", {
    method: "POST",
    body: { ...groupData, members },
  });
  clearGroupCache();
  return group;
}

export async function updateGroup(groupId, updates) {
  const group = await apiRequest(`/groups/${encodeURIComponent(groupId)}`, {
    method: "PATCH",
    body: updates,
  });
  clearGroupCache(groupId);
  return group;
}

export async function deleteGroup(groupId, hardDelete = false) {
  const query = hardDelete ? "?hard_delete=1" : "";
  const response = await apiRequest(`/groups/${encodeURIComponent(groupId)}${query}`, {
    method: "DELETE",
  });
  clearGroupCache(groupId);
  return response;
}

export async function addGroupMember(groupId, userId) {
  const response = await apiRequest(`/groups/${encodeURIComponent(groupId)}/members`, {
    method: "POST",
    body: { user_id: userId },
  });
  clearGroupCache(groupId);
  return response;
}

export async function removeGroupMember(groupId, userId) {
  const response = await apiRequest(
    `/groups/${encodeURIComponent(groupId)}/members/${encodeURIComponent(userId)}`,
    { method: "DELETE" },
  );
  clearGroupCache(groupId);
  return response;
}
