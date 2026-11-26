import { apiRequest } from "./client";

let usersCache = null;
let usersCachePromise = null;
let adminUsersCache = null;
let adminUsersCachePromise = null;

export async function getAllUsers({ forceRefresh = false } = {}) {
  if (!forceRefresh && usersCache) return usersCache;
  if (!forceRefresh && usersCachePromise) return usersCachePromise;

  usersCachePromise = apiRequest("/users");
  try {
    usersCache = await usersCachePromise;
    return usersCache;
  } finally {
    usersCachePromise = null;
  }
}

export async function getAdminUsers({ forceRefresh = false } = {}) {
  if (!forceRefresh && adminUsersCache) return adminUsersCache;
  if (!forceRefresh && adminUsersCachePromise) return adminUsersCachePromise;

  adminUsersCachePromise = apiRequest("/admin/users");
  try {
    adminUsersCache = await adminUsersCachePromise;
    return adminUsersCache;
  } finally {
    adminUsersCachePromise = null;
  }
}

export function clearUsersCache() {
  usersCache = null;
  adminUsersCache = null;
}

export async function createUserAccount(account) {
  const user = await apiRequest("/admin/users", {
    method: "POST",
    body: {
      ...account,
      name: account.name || account.full_name,
    },
  });
  clearUsersCache();
  return user;
}

export async function updateUserStatus(_adminId, targetUserId, newStatus) {
  const user = await apiRequest(`/admin/users/${encodeURIComponent(targetUserId)}`, {
    method: "PATCH",
    body: { status: newStatus },
  });
  clearUsersCache();
  return user;
}

export async function updateUserProfile(userId, updates) {
  const user = await apiRequest(`/admin/users/${encodeURIComponent(userId)}`, {
    method: "PATCH",
    body: updates,
  });
  clearUsersCache();
  return user;
}

export async function deleteUsers(userIds) {
  const response = await apiRequest("/admin/users", {
    method: "DELETE",
    body: { user_ids: userIds },
  });
  clearUsersCache();
  return response;
}
