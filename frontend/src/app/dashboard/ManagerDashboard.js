"use client";

import React from "react";
import { useRouter } from "next/navigation";

const completedStatuses = ["done", "completed"];

function getDueDate(task) {
  if (!task.due_date) return null;
  const date = new Date(`${task.due_date}T00:00:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function isAwaitingReview(task) {
  return task.submissions?.some((submission) => submission.status === "pending")
    || ["in_review", "review"].includes(task.status);
}

function formatDueDate(task) {
  const date = getDueDate(task);
  if (!date) return "Deadline belum ditentukan";
  return new Intl.DateTimeFormat("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(date);
}

export default function ManagerDashboard({ profile, role, tasks = [], error = "" }) {
  const router = useRouter();
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const nextWeek = new Date(today);
  nextWeek.setDate(nextWeek.getDate() + 7);

  const activeTasks = tasks.filter((task) => !completedStatuses.includes(task.status));
  const reviewTasks = tasks.filter(isAwaitingReview);
  const overdueTasks = activeTasks.filter((task) => {
    const dueDate = getDueDate(task);
    return dueDate && dueDate < today;
  });
  const upcomingTasks = activeTasks.filter((task) => {
    const dueDate = getDueDate(task);
    return dueDate && dueDate >= today && dueDate <= nextWeek;
  });

  const priorityRank = (task) => {
    if (isAwaitingReview(task)) return 0;
    const dueDate = getDueDate(task);
    if (dueDate && dueDate < today) return 1;
    if (dueDate && dueDate <= nextWeek) return 2;
    return 3;
  };
  const followUpTasks = [...activeTasks]
    .filter((task) => priorityRank(task) < 3)
    .sort((first, second) => {
      const rankDifference = priorityRank(first) - priorityRank(second);
      if (rankDifference) return rankDifference;
      return (getDueDate(first)?.getTime() || Infinity) - (getDueDate(second)?.getTime() || Infinity);
    })
    .slice(0, 5);

  const openTask = (taskId) => {
    router.push(taskId ? `/dashboard/tasks?task=${taskId}` : "/dashboard/tasks");
  };

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-violet-950 to-violet-800 p-6 text-white shadow-lg shadow-violet-200 sm:p-8">
        <div className="pointer-events-none absolute -right-10 -top-20 h-60 w-60 rounded-full border-[30px] border-white/10" />
        <div className="relative flex flex-wrap items-end justify-between gap-5">
          <div className="max-w-2xl">
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-violet-200">
              SIPANTAU 2.0 · Ruang {role === "admin" ? "Administrator" : "Mentor"}
            </p>
            <h1 className="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
              Halo, {profile?.full_name || profile?.name || "Pengelola"}.
            </h1>
            <p className="mt-3 text-sm leading-relaxed text-violet-100">
              Prioritaskan bukti yang perlu diperiksa dan tugas yang mendekati tenggat.
            </p>
          </div>
          <div className="flex gap-2">
            <button onClick={() => openTask()} className="rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-violet-900 transition hover:bg-violet-50">
              Kelola tugas
            </button>
            <button onClick={() => router.push("/dashboard/reports")} className="rounded-xl border border-white/30 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-white/10">
              Laporan
            </button>
          </div>
        </div>
      </section>

      <section className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {[
          ["Bukti menunggu", reviewTasks.length, "bg-blue-50 text-blue-700", "Perlu ditinjau"],
          ["Tugas terlambat", overdueTasks.length, "bg-rose-50 text-rose-700", "Melewati deadline"],
          ["Deadline 7 hari", upcomingTasks.length, "bg-amber-50 text-amber-700", "Jatuh tempo minggu ini"],
        ].map(([label, count, colors, description]) => (
          <button
            key={label}
            onClick={() => openTask()}
            className="rounded-2xl border border-slate-100 bg-white p-5 text-left transition hover:border-violet-200 hover:shadow-sm"
          >
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className={`mt-3 inline-flex min-w-10 items-center justify-center rounded-xl px-3 py-1 text-2xl font-extrabold ${colors}`}>
              {count}
            </p>
            <p className="mt-2 text-[11px] text-slate-400">{description}</p>
          </button>
        ))}
      </section>

      {error && <p role="alert" className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}

      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <div>
            <h2 className="text-sm font-extrabold text-slate-800">Tindakan berikutnya</h2>
            <p className="mt-1 text-xs text-slate-400">Bukti pemeriksaan dan deadline terdekat diprioritaskan.</p>
          </div>
          <button onClick={() => openTask()} className="text-xs font-bold text-violet-700 hover:text-violet-900">
            Lihat semua tugas
          </button>
        </div>

        {followUpTasks.length ? (
          <div className="divide-y divide-slate-50">
            {followUpTasks.map((task) => {
              const awaitingReview = isAwaitingReview(task);
              const dueDate = getDueDate(task);
              const overdue = dueDate && dueDate < today;
              return (
                <div key={task.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-slate-800">{task.title}</p>
                    <p className="mt-1 text-xs text-slate-400">
                      {task.assigned_user?.full_name || "Belum ada penerima"} · {formatDueDate(task)}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <span className={`rounded-full px-3 py-1.5 text-[10px] font-bold ${
                      awaitingReview ? "bg-blue-50 text-blue-700" : overdue ? "bg-rose-50 text-rose-700" : "bg-amber-50 text-amber-700"
                    }`}>
                      {awaitingReview ? "Perlu diperiksa" : overdue ? "Terlambat" : "Deadline dekat"}
                    </span>
                    <button onClick={() => openTask(task.id)} className="text-xs font-bold text-violet-700 hover:text-violet-900">
                      {awaitingReview ? "Periksa bukti" : "Buka tugas"}
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        ) : (
          <div className="px-5 py-10 text-center">
            <p className="text-sm font-bold text-slate-700">{tasks.length ? "Tidak ada tindak lanjut mendesak" : "Belum ada tugas"}</p>
            <p className="mt-1 text-xs text-slate-400">
              {tasks.length ? "Tugas yang perlu perhatian akan muncul di sini." : "Tugas yang dibuat akan muncul di halaman ini."}
            </p>
          </div>
        )}
      </section>
    </div>
  );
}
