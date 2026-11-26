"use client";

import React, { useEffect, useMemo, useState } from "react";
import { getActiveUser, getProfile } from "../../../backend/auth";
import { getAllUsers } from "../../../backend/admin";
import { getUserGroups } from "../../../backend/groups";
import {
  createTask,
  downloadTaskEvidence,
  getUserTasks,
  reviewTaskEvidence,
  submitTaskEvidence,
} from "../../../backend/tasks";

const taskStatus = {
  todo: "Belum dikerjakan",
  in_progress: "Sedang dikerjakan",
  inprogress: "Sedang dikerjakan",
  in_review: "Menunggu pemeriksaan",
  review: "Menunggu pemeriksaan",
  done: "Selesai",
  completed: "Selesai",
};

const evidenceStatus = {
  pending: "Menunggu pemeriksaan",
  approved: "Disetujui",
  needs_revision: "Perlu diperbaiki",
};

function isHttpUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

export default function TasksPage() {
  const [profile, setProfile] = useState(null);
  const [tasks, setTasks] = useState([]);
  const [groups, setGroups] = useState([]);
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [selectedFiles, setSelectedFiles] = useState({});
  const [evidenceLinks, setEvidenceLinks] = useState({});
  const [notes, setNotes] = useState({});
  const [editingEvidence, setEditingEvidence] = useState({});
  const [feedbacks, setFeedbacks] = useState({});
  const [busyTask, setBusyTask] = useState(null);
  const [focusedTask, setFocusedTask] = useState("");
  const [form, setForm] = useState({
    group_id: "",
    assigned_to: "",
    title: "",
    description: "",
    due_date: "",
    priority: "medium",
  });

  const isManager = profile?.role === "admin" || profile?.role === "mentor";
  const selectedGroup = groups.find((group) => String(group.id) === form.group_id);
  const groupMembers = useMemo(() => {
    const memberIds = (selectedGroup?.group_members || []).map((member) => member.user_id);
    return users.filter((user) => memberIds.includes(user.id) && ["pemagang", "intern"].includes(user.role));
  }, [selectedGroup, users]);

  async function loadPage() {
    setLoading(true);
    setError("");
    try {
      const current = await getActiveUser();
      if (!current) throw new Error("Sesi login tidak ditemukan. Silakan masuk kembali.");
      const [userProfile, userTasks, userGroups, directory] = await Promise.all([
        getProfile(current.id),
        getUserTasks(),
        getUserGroups(),
        getAllUsers(),
      ]);
      setProfile(userProfile);
      setTasks(userTasks || []);
      const activeGroups = (userGroups || []).filter((group) => !group.is_deleted);
      setGroups(activeGroups);
      const defaultGroup = activeGroups.find((group) => group.is_default) || (activeGroups.length === 1 ? activeGroups[0] : null);
      if (defaultGroup) {
        setForm((current) => ({ ...current, group_id: current.group_id || String(defaultGroup.id) }));
      }
      setUsers((directory || []).filter((user) => user.status === "active"));
    } catch (loadError) {
      setError(loadError.message || "Gagal memuat daftar tugas.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadPage();
  }, []);

  useEffect(() => {
    if (typeof window === "undefined" || !tasks.length) return;
    const taskId = new URLSearchParams(window.location.search).get("task");
    if (!taskId) return;
    setFocusedTask(taskId);
    document.getElementById(`task-${taskId}`)?.scrollIntoView({ behavior: "smooth", block: "center" });
  }, [tasks]);

  useEffect(() => {
    if (!selectedGroup) return;
    const validAssignee = groupMembers.some((member) => String(member.id) === form.assigned_to);
    if (!validAssignee) {
      setForm((current) => ({ ...current, assigned_to: "" }));
    }
  }, [selectedGroup, groupMembers, form.assigned_to]);

  async function handleCreateTask(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    try {
      await createTask({
        ...form,
        group_id: Number(form.group_id),
        assigned_to: Number(form.assigned_to),
      });
      setForm({ group_id: "", assigned_to: "", title: "", description: "", due_date: "", priority: "medium" });
      setShowCreateForm(false);
      await loadPage();
    } catch (saveError) {
      setError(saveError.message || "Gagal membuat tugas.");
    } finally {
      setSaving(false);
    }
  }

  async function handleSubmitEvidence(task) {
    const file = selectedFiles[task.id];
    const evidenceUrl = (evidenceLinks[task.id] || "").trim();
    if (!file && !evidenceUrl) {
      setError("Pilih file atau tempel tautan bukti terlebih dahulu.");
      return;
    }
    if (evidenceUrl && !isHttpUrl(evidenceUrl)) {
      setError("Tautan bukti harus menggunakan alamat http:// atau https:// yang valid.");
      return;
    }
    setBusyTask(task.id);
    setError("");
    try {
      await submitTaskEvidence(task.id, { file, note: notes[task.id] || "", evidenceUrl });
      setSelectedFiles((current) => ({ ...current, [task.id]: null }));
      setEvidenceLinks((current) => ({ ...current, [task.id]: "" }));
      setNotes((current) => ({ ...current, [task.id]: "" }));
      setEditingEvidence((current) => ({ ...current, [task.id]: false }));
      await loadPage();
    } catch (submitError) {
      setError(submitError.message || "Gagal mengirim bukti tugas.");
    } finally {
      setBusyTask(null);
    }
  }

  async function handleReview(submission, status) {
    const feedback = feedbacks[submission.id] || "";
    if (status === "needs_revision" && !feedback.trim()) {
      setError("Tulis catatan perbaikan sebelum meminta revisi.");
      return;
    }
    setBusyTask(submission.id);
    setError("");
    try {
      await reviewTaskEvidence(submission.id, status, feedback);
      await loadPage();
    } catch (reviewError) {
      setError(reviewError.message || "Gagal menyimpan hasil pemeriksaan.");
    } finally {
      setBusyTask(null);
    }
  }

  const summary = {
    total: tasks.length,
    waiting: tasks.filter((task) => ["todo", "in_progress", "inprogress"].includes(task.status)).length,
    review: tasks.filter((task) => ["in_review", "review"].includes(task.status)).length,
    done: tasks.filter((task) => ["done", "completed"].includes(task.status)).length,
  };

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 pb-5">
        <div>
          <p className="text-xs font-bold uppercase tracking-wider text-violet-600">
            {isManager ? "Kelola dan periksa pekerjaan" : "Daftar pekerjaan Anda"}
          </p>
          <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-slate-900">
            {isManager ? "Tugas Pemagang" : "Tugas Saya"}
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {isManager
              ? "Berikan tugas, pantau deadline, dan periksa bukti hasil."
              : "Lihat tugas, unggah bukti hasil, dan tunggu pemeriksaan mentor."}
          </p>
        </div>
        {isManager && (
          <button
            onClick={() => setShowCreateForm((open) => !open)}
            className="h-10 rounded-xl bg-violet-600 px-4 text-xs font-bold text-white hover:bg-violet-700"
          >
            {showCreateForm ? "Tutup" : "+ Beri Tugas"}
          </button>
        )}
      </header>

      <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          ["Semua tugas", summary.total, "text-slate-800", "bg-slate-50"],
          ["Belum selesai", summary.waiting, "text-amber-700", "bg-amber-50"],
          ["Perlu diperiksa", summary.review, "text-blue-700", "bg-blue-50"],
          ["Selesai", summary.done, "text-emerald-700", "bg-emerald-50"],
        ].map(([label, count, color, background]) => (
          <div key={label} className={`rounded-2xl border border-slate-100 p-4 ${background}`}>
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className={`mt-2 text-2xl font-extrabold ${color}`}>{count}</p>
          </div>
        ))}
      </section>

      {isManager && showCreateForm && (
        <form onSubmit={handleCreateTask} className="grid grid-cols-1 gap-3 rounded-2xl border border-violet-100 bg-violet-50/40 p-4 sm:grid-cols-2">
          <h2 className="text-sm font-extrabold text-slate-800 sm:col-span-2">Buat tugas baru</h2>
          {groups.length === 1 ? (
            <div className="space-y-1 text-xs font-bold text-slate-600">
              Kelompok
              <p className="flex h-10 items-center rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm font-semibold text-slate-600">{groups[0].name}</p>
            </div>
          ) : (
            <label className="space-y-1 text-xs font-bold text-slate-600">
              Kelompok
              <select required value={form.group_id} onChange={(event) => setForm({ ...form, group_id: event.target.value })} className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium">
                <option value="">Pilih kelompok</option>
                {groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
              </select>
            </label>
          )}
          <label className="space-y-1 text-xs font-bold text-slate-600">
            Tugaskan kepada
            <select required value={form.assigned_to} onChange={(event) => setForm({ ...form, assigned_to: event.target.value })} disabled={!form.group_id} className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium disabled:bg-slate-100">
              <option value="">Pilih pemagang</option>
              {groupMembers.map((member) => <option key={member.id} value={member.id}>{member.full_name || member.name}</option>)}
            </select>
          </label>
          <label className="space-y-1 text-xs font-bold text-slate-600 sm:col-span-2">
            Nama tugas
            <input required maxLength={255} value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium" placeholder="Contoh: Rekap data survei" />
          </label>
          <label className="space-y-1 text-xs font-bold text-slate-600 sm:col-span-2">
            Instruksi
            <textarea required maxLength={20000} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium" rows={3} placeholder="Jelaskan hasil yang diharapkan." />
          </label>
          <label className="space-y-1 text-xs font-bold text-slate-600">
            Deadline
            <input required type="date" value={form.due_date} onChange={(event) => setForm({ ...form, due_date: event.target.value })} className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium" />
          </label>
          <label className="space-y-1 text-xs font-bold text-slate-600">
            Prioritas
            <select value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })} className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium">
              <option value="low">Rendah</option>
              <option value="medium">Sedang</option>
              <option value="high">Tinggi</option>
              <option value="urgent">Mendesak</option>
            </select>
          </label>
          <button disabled={saving || !groupMembers.length} className="h-10 rounded-xl bg-violet-600 px-4 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-50 sm:col-span-2">
            {saving ? "Menyimpan..." : "Berikan tugas"}
          </button>
          {form.group_id && !groupMembers.length && <p className="text-xs text-rose-600 sm:col-span-2">Kelompok ini belum memiliki anggota pemagang aktif.</p>}
        </form>
      )}

      {error && <p role="alert" className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}

      <section className="space-y-3">
        {loading ? (
          <p className="rounded-2xl border border-slate-100 bg-white p-8 text-center text-sm text-slate-400">Memuat daftar tugas...</p>
        ) : tasks.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center">
            <h2 className="text-sm font-bold text-slate-700">Belum ada tugas</h2>
            <p className="mt-1 text-xs text-slate-400">{isManager ? "Buat tugas pertama untuk anggota kelompok." : "Tugas yang diberikan mentor akan muncul di sini."}</p>
          </div>
        ) : tasks.map((task) => {
          const submission = task.submissions?.[0];
          const isDone = ["done", "completed"].includes(task.status);
          const needsRevision = submission?.status === "needs_revision";
          const canSubmit = !isManager && !isDone && submission?.status !== "pending" && submission?.status !== "approved";
          const showEvidenceForm = canSubmit && (!needsRevision || editingEvidence[task.id]);

          return (
            <article id={`task-${task.id}`} key={task.id} className={`space-y-4 rounded-2xl border bg-white p-4 shadow-sm transition-colors sm:p-5 ${focusedTask === String(task.id) ? "border-violet-300 ring-2 ring-violet-100" : "border-slate-100"}`}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-sm font-extrabold text-slate-800">{task.title}</h2>
                    <span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${isDone ? "bg-emerald-50 text-emerald-700" : task.status === "in_review" ? "bg-blue-50 text-blue-700" : "bg-amber-50 text-amber-700"}`}>
                      {taskStatus[task.status] || task.status}
                    </span>
                  </div>
                  <p className="mt-2 whitespace-pre-wrap text-xs leading-relaxed text-slate-600">{task.description || "Tidak ada instruksi tambahan."}</p>
                </div>
                <div className="text-right text-[11px] font-semibold text-slate-500">
                  <p>{task.group?.name || "Kelompok"}</p>
                  <p className="mt-1">Deadline: {task.due_date || "Belum ditentukan"}</p>
                  {isManager && <p className="mt-1">Pemagang: {task.assigned_user?.full_name || task.assigned_user?.name || "-"}</p>}
                </div>
              </div>

              {submission && (
                <div className={`rounded-xl border p-3 ${submission.status === "needs_revision" ? "border-amber-200 bg-amber-50/50" : "border-slate-100 bg-slate-50/70"}`}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="text-xs font-bold text-slate-700">
                        {isManager ? `Bukti dari ${submission.user?.name || task.assigned_user?.name || "pemagang"}` : "Bukti terakhir"}
                        <span className="ml-2 rounded-full bg-white px-2 py-0.5 text-[10px] text-slate-600">{evidenceStatus[submission.status] || submission.status}</span>
                      </p>
                      {submission.file_name && <p className="mt-1 text-[11px] text-slate-500">{submission.file_name}</p>}
                      {submission.evidence_url && (
                        <a href={submission.evidence_url} target="_blank" rel="noopener noreferrer" className="mt-1 block break-all text-[11px] text-violet-700 underline hover:text-violet-900">
                          Buka tautan bukti
                        </a>
                      )}
                    </div>
                    {submission.file_name && (
                      <button onClick={() => downloadTaskEvidence(submission.id, submission.file_name).catch((downloadError) => setError(downloadError.message))} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-700 hover:bg-slate-100">
                        Unduh file
                      </button>
                    )}
                  </div>
                  {submission.note && <p className="mt-2 text-xs text-slate-600">{submission.note}</p>}
                  {submission.feedback && <p className="mt-2 rounded-lg bg-white p-2 text-xs text-amber-800">Catatan mentor: {submission.feedback}</p>}

                  {!isManager && needsRevision && !editingEvidence[task.id] && (
                    <button
                      onClick={() => setEditingEvidence((current) => ({ ...current, [task.id]: true }))}
                      className="mt-3 rounded-lg bg-amber-600 px-3 py-2 text-[10px] font-bold text-white hover:bg-amber-700"
                    >
                      Perbaiki & kirim ulang jawaban
                    </button>
                  )}

                  {isManager && submission.status === "pending" && (
                    <div className="mt-3 space-y-2 border-t border-slate-200 pt-3">
                      <label className="block space-y-1 text-[10px] font-bold text-slate-500">
                        Catatan pemeriksaan (wajib bila meminta perbaikan)
                        <textarea value={feedbacks[submission.id] || ""} onChange={(event) => setFeedbacks({ ...feedbacks, [submission.id]: event.target.value })} rows={2} className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium" placeholder="Tulis arahan yang jelas untuk pemagang." />
                      </label>
                      <div className="flex flex-wrap gap-2">
                        <button disabled={busyTask === submission.id} onClick={() => handleReview(submission, "approved")} className="rounded-lg bg-emerald-600 px-3 py-2 text-[10px] font-bold text-white hover:bg-emerald-700 disabled:opacity-50">Setujui & selesaikan</button>
                        <button disabled={busyTask === submission.id} onClick={() => handleReview(submission, "needs_revision")} className="rounded-lg border border-amber-300 bg-white px-3 py-2 text-[10px] font-bold text-amber-800 hover:bg-amber-50 disabled:opacity-50">Minta perbaikan</button>
                      </div>
                    </div>
                  )}
                </div>
              )}

              {showEvidenceForm && (
                <div className="space-y-3 rounded-xl border border-violet-100 bg-violet-50/40 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs font-bold text-violet-800">{needsRevision ? "Perbarui jawaban tugas" : "Kirim bukti hasil tugas"}</p>
                    {needsRevision && (
                      <button
                        onClick={() => setEditingEvidence((current) => ({ ...current, [task.id]: false }))}
                        className="text-[10px] font-bold text-slate-500 hover:text-slate-700"
                      >
                        Batal
                      </button>
                    )}
                  </div>
                  {needsRevision && <p className="text-[10px] text-slate-600">Perbarui bukti sesuai catatan mentor, lalu kirim ulang untuk diperiksa.</p>}
                  <p className="text-[10px] text-slate-500">Unggah file atau tempel tautan; keduanya juga boleh diisi.</p>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block space-y-1 text-[10px] font-bold text-slate-600">
                      Pilih file
                      <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" onChange={(event) => setSelectedFiles({ ...selectedFiles, [task.id]: event.target.files?.[0] || null })} className="block w-full rounded-lg border border-violet-100 bg-white p-2 text-xs font-normal text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-50 file:px-3 file:py-2 file:text-xs file:font-bold file:text-violet-700" />
                      <span className="block text-[10px] font-normal text-slate-400">PDF, gambar, Word, atau Excel; maksimal 10 MB.</span>
                    </label>
                    <label className="block space-y-1 text-[10px] font-bold text-slate-600">
                      Tempel tautan
                      <input type="url" value={evidenceLinks[task.id] || ""} onChange={(event) => setEvidenceLinks({ ...evidenceLinks, [task.id]: event.target.value })} placeholder="https://drive.google.com/..." className="block w-full rounded-lg border border-violet-100 bg-white px-3 py-2 text-xs font-normal text-slate-700 placeholder:text-slate-400" />
                      <span className="block text-[10px] font-normal text-slate-400">Pastikan mentor memiliki akses ke tautan.</span>
                    </label>
                  </div>
                  <textarea value={notes[task.id] || ""} onChange={(event) => setNotes({ ...notes, [task.id]: event.target.value })} rows={2} className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs" placeholder="Catatan singkat hasil pekerjaan (opsional)" />
                  <button disabled={busyTask === task.id || (!selectedFiles[task.id] && !(evidenceLinks[task.id] || "").trim())} onClick={() => handleSubmitEvidence(task)} className="rounded-lg bg-violet-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50">
                    {busyTask === task.id ? "Mengirim..." : needsRevision ? "Kirim ulang untuk diperiksa" : "Kirim untuk diperiksa"}
                  </button>
                </div>
              )}
            </article>
          );
        })}
      </section>
    </div>
  );
}
