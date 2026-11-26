import { apiRequest } from "../backend/client";

const monthNamesInIndonesian = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember"
];
const shortMonthNamesInIndonesian = [
  "Jan", "Feb", "Mar", "Apr", "Mei", "Jun",
  "Jul", "Agu", "Sep", "Okt", "Nov", "Des"
];

export function dbDateToFrontend(dbDateStr) {
  if (!dbDateStr) return "";
  const parts = dbDateStr.split("-");
  if (parts.length !== 3) return dbDateStr;
  const year = parseInt(parts[0]);
  const month = parseInt(parts[1]) - 1;
  const day = parseInt(parts[2]);
  if (isNaN(year) || isNaN(month) || isNaN(day)) return dbDateStr;
  return `${day} ${monthNamesInIndonesian[month]} ${year}`;
}

export function frontendDateToDb(feDateStr) {
  if (!feDateStr) return null;
  const parts = feDateStr.trim().split(/\s+/);
  if (parts.length < 3) return null;
  const day = parseInt(parts[0]);
  const year = parseInt(parts[2]);
  const monthStr = parts[1].toLowerCase();

  let month = monthNamesInIndonesian.findIndex(m => monthStr.startsWith(m.toLowerCase().substring(0, 3)));
  if (month === -1) {
    month = shortMonthsInIndonesian.findIndex(m => monthStr.startsWith(m.toLowerCase().substring(0, 3)));
  }
  if (isNaN(day) || isNaN(year) || month === -1) return null;

  const pad = (num) => String(num).padStart(2, "0");
  return `${year}-${pad(month + 1)}-${pad(day)}`;
}

export function getTypeBadgeStyle(typeName, taskTypes = []) {
  const nameLower = (typeName || "").toLowerCase();
  const match = (taskTypes || []).find(t => {
    const parts = t.split(":");
    return parts[0].toLowerCase() === nameLower;
  });

  let color = "indigo";
  if (match && match.includes(":")) {
    color = match.split(":")[1];
  } else if (nameLower === "design") color = "violet";
  else if (nameLower === "bug") color = "cyan";
  else if (nameLower === "aset") color = "amber";
  else if (nameLower === "fitur") color = "rose";
  else if (nameLower === "tugas") color = "indigo";

  switch (color.toLowerCase()) {
    case "violet": return { badge: "bg-violet-100 text-violet-600 border-violet-200/40", dot: "bg-violet-500" };
    case "cyan": return { badge: "bg-cyan-100 text-cyan-600 border-cyan-200/40", dot: "bg-cyan-500" };
    case "amber": return { badge: "bg-amber-100 text-amber-600 border-amber-200/40", dot: "bg-amber-500" };
    case "rose": return { badge: "bg-rose-100 text-rose-600 border-rose-200/40", dot: "bg-rose-500" };
    case "indigo": return { badge: "bg-indigo-100 text-indigo-650 border-indigo-200/40", dot: "bg-indigo-500" };
    case "emerald": return { badge: "bg-emerald-100 text-emerald-600 border-emerald-200/40", dot: "bg-emerald-500" };
    case "slate": return { badge: "bg-slate-100 text-slate-600 border-slate-200/40", dot: "bg-slate-500" };
    default: return { badge: "bg-indigo-100 text-indigo-650 border-indigo-200/40", dot: "bg-indigo-500" };
  }
}

export function fePriorityToDb(priority) {
  const value = String(priority).toLowerCase();
  if (value === "terendah" || value === "rendah") return "low";
  if (value === "sedang") return "medium";
  if (value === "tinggi") return "high";
  if (value === "tertinggi") return "urgent";
  return priority;
}

export function dbPriorityToFe(priority) {
  const value = String(priority).toLowerCase();
  if (value === "low" || value === "terendah" || value === "rendah") return "Rendah";
  if (value === "medium" || value === "sedang") return "Sedang";
  if (value === "high" || value === "tinggi") return "Tinggi";
  if (value === "urgent" || value === "critical" || value === "tertinggi") return "Tertinggi";
  return priority || "Sedang";
}

function mapTask(task) {
  const initials = (task.task_assignees || []).map(assignee => assignee.member_initial);
  if (initials.length === 0 && task.assigned_user?.full_name) {
    initials.push(task.assigned_user.full_name.charAt(0).toUpperCase());
  }

  return {
    id: task.id,
    title: task.title,
    desc: task.description || "",
    date: dbDateToFrontend(task.due_date),
    type: task.type || "Tugas",
    priority: dbPriorityToFe(task.priority),
    status: task.status === "in_progress" ? "inprogress" : task.status,
    done: task.status === "done",
    orang: initials,
    assigned_to: task.assigned_to,
    assigned_user: task.assigned_user,
    riwayat: (task.task_history || []).map(history => ({
      id: history.id,
      name: history.name,
      text: history.text,
      time: history.time,
      created_at: history.created_at,
    })),
    komentar: (task.task_comments || [])
      .slice()
      .sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
      .map(comment => ({
        id: comment.id,
        name: comment.profiles?.full_name || comment.user?.full_name || "User",
        text: comment.content,
        created_at: comment.created_at,
        avatar_url: comment.profiles?.avatar_url || comment.user?.avatar_url || null,
      })),
    subtasks: (task.subtasks || []).map(subtask => ({
      id: subtask.id,
      title: subtask.title,
      done: subtask.is_completed,
    })),
  };
}

export async function getTasksByTeam(teamId) {
  const tasks = await apiRequest(`/tasks?group_id=${encodeURIComponent(teamId)}`);
  return tasks.map(mapTask);
}

export async function createTask({ teamId, title, desc, date, type, priority, status, orang }) {
  try {
    const task = await apiRequest("/tasks", {
      method: "POST",
      body: {
        group_id: teamId,
        title,
        description: desc || "Tidak ada deskripsi",
        due_date: frontendDateToDb(date),
        type,
        priority: fePriorityToDb(priority),
        status: status === "inprogress" ? "in_progress" : (status || "todo"),
        orang: orang || [],
      },
    });
    return { data: mapTask(task), error: null };
  } catch (error) {
    return { data: null, error: error.message };
  }
}

export async function updateTask(id, updates) {
  const body = {};
  if (updates.title !== undefined) body.title = updates.title;
  if (updates.desc !== undefined) body.description = updates.desc;
  if (updates.date !== undefined) body.due_date = frontendDateToDb(updates.date);
  if (updates.type !== undefined) body.type = updates.type;
  if (updates.priority !== undefined) body.priority = fePriorityToDb(updates.priority);
  if (updates.status !== undefined) body.status = updates.status === "inprogress" ? "in_progress" : updates.status;
  if (updates.done !== undefined) body.done = updates.done;
  if (updates.assigned_to !== undefined) body.assigned_to = updates.assigned_to;
  if (updates.orang !== undefined) body.orang = updates.orang;

  try {
    const task = await apiRequest(`/tasks/${encodeURIComponent(id)}`, { method: "PATCH", body });
    return { data: mapTask(task), error: null };
  } catch (error) {
    return { data: null, error: error.message };
  }
}

export async function deleteTask(id) {
  try {
    await apiRequest(`/tasks/${encodeURIComponent(id)}`, { method: "DELETE" });
    return { error: null };
  } catch (error) {
    return { error: error.message };
  }
}

export async function addComment(taskId, { text }) {
  try {
    const comment = await apiRequest(`/tasks/${encodeURIComponent(taskId)}/comments`, {
      method: "POST",
      body: { content: text },
    });
    return {
      data: {
        id: comment.id,
        name: comment.profiles?.full_name || comment.user?.full_name || "User",
        text: comment.content,
        created_at: comment.created_at,
      },
      error: null,
    };
  } catch (error) {
    return { data: null, error: error.message };
  }
}

export async function addHistory(taskId, { name, text, time }) {
  try {
    const history = await apiRequest(`/tasks/${encodeURIComponent(taskId)}/history`, {
      method: "POST",
      body: { name, text, time },
    });
    return { data: history, error: null };
  } catch (error) {
    return { data: null, error: error.message };
  }
}

export async function addSubtask(taskId, title) {
  try {
    const subtask = await apiRequest(`/tasks/${encodeURIComponent(taskId)}/subtasks`, {
      method: "POST",
      body: { title },
    });
    return { data: { id: subtask.id, title: subtask.title, done: subtask.is_completed }, error: null };
  } catch (error) {
    return { data: null, error: error.message };
  }
}

export async function toggleSubtask(subtaskId, done) {
  try {
    await apiRequest(`/subtasks/${encodeURIComponent(subtaskId)}`, {
      method: "PATCH",
      body: { is_completed: done },
    });
    return { error: null };
  } catch (error) {
    return { error: error.message };
  }
}

export async function deleteSubtask(subtaskId) {
  try {
    await apiRequest(`/subtasks/${encodeURIComponent(subtaskId)}`, { method: "DELETE" });
    return { error: null };
  } catch (error) {
    return { error: error.message };
  }
}

export async function getActivityLogs(_userId, limit = 20) {
  return apiRequest(`/activity-logs?limit=${encodeURIComponent(limit)}`);
}

export async function logActivity({ action, detail, groupId = null, taskId = null, userId: _userId }) {
  return apiRequest("/activity-logs", {
    method: "POST",
    body: {
      action,
      detail,
      group_id: groupId,
      task_id: taskId,
    },
  });
}
