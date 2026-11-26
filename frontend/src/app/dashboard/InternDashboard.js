"use client";

import React from "react";
import { useRouter } from "next/navigation";

const completedStatuses = ["done", "completed"];
const reviewStatuses = ["in_review", "review"];

function formatDueDate(value) {
  if (!value) return "Tanpa deadline";
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(date);
}

function isOverdue(task) {
  if (!task.due_date || completedStatuses.includes(task.status)) return false;
  const dueDate = new Date(`${task.due_date}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return !Number.isNaN(dueDate.getTime()) && dueDate < today;
}

export default function InternDashboard({ profile, tasks = [], error = "" }) {
  const router = useRouter();
  const waitingTasks = tasks.filter((task) => !completedStatuses.includes(task.status) && !reviewStatuses.includes(task.status));
  const reviewTasks = tasks.filter((task) => reviewStatuses.includes(task.status));
  const completedTasks = tasks.filter((task) => completedStatuses.includes(task.status));
  const attentionTasks = [...tasks]
    .filter((task) => !completedStatuses.includes(task.status))
    .sort((first, second) => {
      const firstReview = reviewStatuses.includes(first.status) ? 0 : 1;
      const secondReview = reviewStatuses.includes(second.status) ? 0 : 1;
      if (firstReview !== secondReview) return firstReview - secondReview;
      if (!first.due_date) return 1;
      if (!second.due_date) return -1;
      return first.due_date.localeCompare(second.due_date);
    })
    .slice(0, 4);

  const openTasks = (taskId) => {
    router.push(taskId ? `/dashboard/tasks?task=${taskId}` : "/dashboard/tasks");
  };

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-violet-700 via-violet-700 to-indigo-800 p-6 text-white shadow-lg shadow-violet-200 sm:p-8">
        <div className="pointer-events-none absolute -right-12 -top-16 h-56 w-56 rounded-full border-[28px] border-white/10" />
        <div className="pointer-events-none absolute bottom-[-5rem] right-32 h-40 w-40 rounded-full bg-fuchsia-400/20 blur-2xl" />
        <div className="relative max-w-2xl">
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-violet-200">SIPANTAU 2.0 · Ruang Pemagang</p>
          <h1 className="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">
            Halo, {profile?.full_name || profile?.name || "Pemagang"}!
          </h1>
          <p className="mt-3 max-w-xl text-sm leading-relaxed text-violet-100">
            Fokus pada tugas yang diberikan, kirim bukti hasil, dan pantau tanggapan mentor dari satu tempat.
          </p>
          <button
            onClick={() => openTasks()}
            className="mt-6 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-violet-800 transition hover:bg-violet-50"
          >
            Buka tugas saya
          </button>
        </div>
      </section>

      <section className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {[
          ["Perlu dikerjakan", waitingTasks.length, "bg-amber-50 text-amber-700", "Tugas yang belum selesai"],
          ["Menunggu mentor", reviewTasks.length, "bg-blue-50 text-blue-700", "Bukti yang sedang diperiksa"],
          ["Sudah selesai", completedTasks.length, "bg-emerald-50 text-emerald-700", "Tugas yang disetujui"],
        ].map(([label, count, colors, description]) => (
          <div key={label} className="rounded-2xl border border-slate-100 bg-white p-5">
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className={`mt-3 inline-flex min-w-10 items-center justify-center rounded-xl px-3 py-1 text-2xl font-extrabold ${colors}`}>
              {count}
            </p>
            <p className="mt-2 text-[11px] text-slate-400">{description}</p>
          </div>
        ))}
      </section>

      {error && <p role="alert" className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}

      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <div>
            <h2 className="text-sm font-extrabold text-slate-800">Yang perlu diperhatikan</h2>
            <p className="mt-1 text-xs text-slate-400">Tugas menunggu pemeriksaan dan deadline terdekat muncul lebih dulu.</p>
          </div>
          <button onClick={() => openTasks()} className="text-xs font-bold text-violet-700 hover:text-violet-900">
            Lihat semua tugas
          </button>
        </div>

        {attentionTasks.length ? (
          <div className="divide-y divide-slate-50">
            {attentionTasks.map((task) => {
              const awaitingReview = reviewStatuses.includes(task.status);
              const overdue = isOverdue(task);
              return (
                <button
                  key={task.id}
                  onClick={() => openTasks(task.id)}
                  className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left transition hover:bg-violet-50/50"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-bold text-slate-800">{task.title}</span>
                    <span className="mt-1 block text-xs text-slate-400">Deadline: {formatDueDate(task.due_date)}</span>
                  </span>
                  <span className={`shrink-0 rounded-full px-3 py-1.5 text-[10px] font-bold ${
                    overdue ? "bg-rose-50 text-rose-700" : awaitingReview ? "bg-blue-50 text-blue-700" : "bg-amber-50 text-amber-700"
                  }`}>
                    {overdue ? "Terlambat" : awaitingReview ? "Menunggu mentor" : "Belum selesai"}
                  </span>
                </button>
              );
            })}
          </div>
        ) : (
          <div className="px-5 py-10 text-center">
            <p className="text-sm font-bold text-slate-700">{tasks.length ? "Semua tugas sudah selesai!" : "Belum ada tugas untukmu"}</p>
            <p className="mt-1 text-xs text-slate-400">
              {tasks.length ? "Terima kasih sudah menyelesaikan pekerjaan." : "Tugas baru dari mentor akan muncul di halaman ini."}
            </p>
          </div>
        )}
      </section>
    </div>
  );
}
