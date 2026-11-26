import { API_BASE_URL, apiRequest, getAccessToken } from "./client";

export async function getGroupTasks(groupId) {
  return apiRequest(`/tasks?group_id=${encodeURIComponent(groupId)}`);
}

export async function getUserTasks() {
  return apiRequest("/tasks");
}

export async function updateTaskStatus(taskId, newStatus) {
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}`, {
    method: "PATCH",
    body: { status: newStatus },
  });
}

export async function createTaskComment(taskId, _userId, content) {
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}/comments`, {
    method: "POST",
    body: { content },
  });
}

export async function createSubtask(taskId, title) {
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}/subtasks`, {
    method: "POST",
    body: { title },
  });
}

export async function updateSubtaskStatus(subtaskId, isCompleted) {
  return apiRequest(`/subtasks/${encodeURIComponent(subtaskId)}`, {
    method: "PATCH",
    body: { is_completed: isCompleted },
  });
}

export async function createTask(taskData) {
  return apiRequest("/tasks", { method: "POST", body: taskData });
}

export async function deleteTask(taskId) {
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}`, { method: "DELETE" });
}

export async function addHistory(taskId, { name, text, time }) {
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}/history`, {
    method: "POST",
    body: { name, text, time },
  });
}

export async function updateTask(taskId, taskData) {
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}`, {
    method: "PATCH",
    body: taskData,
  });
}

export async function submitTaskEvidence(taskId, { file, note, evidenceUrl }) {
  const formData = new FormData();
  if (file) formData.append("file", file);
  if (evidenceUrl) formData.append("evidence_url", evidenceUrl);
  if (note) formData.append("note", note);
  return apiRequest(`/tasks/${encodeURIComponent(taskId)}/submissions`, {
    method: "POST",
    body: formData,
  });
}

export async function reviewTaskEvidence(submissionId, status, feedback = "") {
  return apiRequest(`/task-submissions/${encodeURIComponent(submissionId)}/review`, {
    method: "PATCH",
    body: { status, feedback },
  });
}

export async function downloadTaskEvidence(submissionId, fileName) {
  const token = getAccessToken();
  const response = await fetch(
    `${API_BASE_URL}/task-submissions/${encodeURIComponent(submissionId)}/file`,
    { headers: token ? { Authorization: `Bearer ${token}` } : {} },
  );
  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    throw new Error(payload?.message || `Unduhan bukti gagal (${response.status}).`);
  }
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
