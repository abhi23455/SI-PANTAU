import { apiRequest, clearAuthSession, getAccessToken, setAuthSession } from "./client";

const profileCache = new Map();

export async function signUpUser({ email, password, name, phone, address, institution, major, role }) {
  const response = await apiRequest("/auth/register", {
    method: "POST",
    auth: false,
    body: { email, password, name, phone, address, institution, major, role },
  });
  setAuthSession(response.access_token, response.user);
  profileCache.set(String(response.user.id), response.profile);
  return response;
}

export async function signInUser(email, password) {
  const response = await apiRequest("/auth/login", {
    method: "POST",
    auth: false,
    body: { email, password },
  });
  setAuthSession(response.access_token, response.user);
  profileCache.set(String(response.user.id), response.profile);
  return { user: response.user, profile: response.profile };
}

export async function signOutUser() {
  try {
    if (getAccessToken()) {
      await apiRequest("/auth/logout", { method: "POST" });
    }
  } finally {
    profileCache.clear();
    clearAuthSession();
  }
}

export async function getActiveUser() {
  if (!getAccessToken()) return null;

  try {
    const { user } = await apiRequest("/auth/me");
    return user;
  } catch (error) {
    if (error.status === 401) {
      clearAuthSession();
      profileCache.clear();
      return null;
    }
    throw error;
  }
}

export async function getProfile(userId) {
  const cached = profileCache.get(String(userId));
  if (cached) return cached;

  const { profile } = await apiRequest("/auth/me");
  if (String(profile.id) !== String(userId)) {
    throw new Error("Profil yang diminta bukan milik akun yang sedang masuk.");
  }
  profileCache.set(String(userId), profile);
  return profile;
}

export function clearProfileCache(userId) {
  if (userId) profileCache.delete(String(userId));
  else profileCache.clear();
}

export async function updateProfile(userId, updates) {
  const { profile } = await apiRequest("/profile", {
    method: "PATCH",
    body: updates,
  });
  if (String(profile.id) !== String(userId)) {
    throw new Error("Profil yang diperbarui bukan milik akun yang sedang masuk.");
  }
  profileCache.set(String(userId), profile);
  return profile;
}
