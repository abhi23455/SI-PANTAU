"use client";

import React, { useState } from "react";
import { useRouter } from "next/navigation";
import { getActiveUser, getProfile } from "../../../backend/auth";
import { getUserGroups } from "../../../backend/groups";
import { exportTaskReport, getTaskReport } from "../../../backend/dashboard";

const initialSummary = { total: 0, completed: 0, in_progress: 0, scheduled: 0, overdue: 0, upcoming: 0 };
const initialFilters = { group_id: "", status: "" };

function statusLabel(status) {
  return {
    todo: "Belum dikerjakan",
    in_progress: "Dikerjakan",
    inprogress: "Dikerjakan",
    in_review: "Perlu ditinjau",
    review: "Perlu ditinjau",
    done: "Selesai",
    completed: "Selesai",
    Selesai: "Selesai",
  }[status] || status;
}

function statusStyle(status) {
  if (["done", "completed", "Selesai"].includes(status)) return "bg-emerald-50 text-emerald-700";
  if (["in_review", "review"].includes(status)) return "bg-blue-50 text-blue-700";
  return "bg-amber-50 text-amber-700";
}

function formatDueDate(value) {
  if (!value) return "Belum ditentukan";
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(date);
}

export default function ReportsPage() {
  const router = useRouter();
  const [profile, setProfile] = useState(null);
  const [groups, setGroups] = useState([]);
  const [filters, setFilters] = useState(initialFilters);
  const [summary, setSummary] = useState(initialSummary);
  const [tasks, setTasks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState("");

  const isManager = profile?.role === "admin" || profile?.role === "mentor";

  async function loadReport(nextFilters = filters) {
    setLoading(true);
    setError("");
    try {
      const report = await getTaskReport(nextFilters);
      setSummary({ ...initialSummary, ...(report.summary || {}) });
      setTasks(report.tasks || []);
    } catch (loadError) {
      setError(loadError.message || "Gagal memuat laporan.");
    } finally {
      setLoading(false);
    }
  }

  React.useEffect(() => {
    let active = true;
    async function loadPage() {
      setLoading(true);
      try {
        const account = await getActiveUser();
        if (!account) throw new Error("Sesi login tidak ditemukan. Silakan masuk kembali.");
        const userProfile = await getProfile(account.id);
        const userGroups = ["admin", "mentor"].includes(userProfile.role)
          ? await getUserGroups()
          : [];
        if (!active) return;
        setProfile(userProfile);
        setGroups((userGroups || []).filter((group) => !group.is_deleted));
        await loadReport(initialFilters);
      } catch (loadError) {
        if (active) {
          setError(loadError.message || "Gagal memuat laporan.");
          setLoading(false);
        }
      }
    }
    loadPage();
    return () => { active = false; };
  }, []);

  async function updateFilter(key, value) {
    const nextFilters = { ...filters, [key]: value };
    setFilters(nextFilters);
    await loadReport(nextFilters);
  }

  async function resetFilters() {
    setFilters(initialFilters);
    await loadReport(initialFilters);
  }

  async function handleExport() {
    setExporting(true);
    setError("");
    try {
      const csv = await exportTaskReport(filters);
      const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "laporan-tugas-sipantau-2.0.csv";
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (exportError) {
      setError(exportError.message || "Gagal mengekspor laporan.");
    } finally {
      setExporting(false);
    }
  }

  const cards = [
    ["Total tugas", summary.total, "text-slate-800", "bg-slate-50"],
    ["Selesai", summary.completed, "text-emerald-700", "bg-emerald-50"],
    ["Terlambat", summary.overdue, "text-rose-700", "bg-rose-50"],
    ["Deadline 7 hari", summary.upcoming, "text-amber-700", "bg-amber-50"],
  ];

  return (
    <div className="space-y-6">
      <header className="border-b border-slate-100 pb-5">
        <p className="text-xs font-bold uppercase tracking-wider text-violet-600">
          {isManager ? "Pemantauan pekerjaan" : "Ringkasan pribadi"}
        </p>
        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-slate-900">
          {isManager ? "Laporan Tugas" : "Laporan Saya"}
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Ringkasan status dan deadline tugas dalam satu tampilan.
        </p>
      </header>

      <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {cards.map(([label, value, color, background]) => (
          <div key={label} className={`rounded-2xl border border-slate-100 p-4 ${background}`}>
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className={`mt-2 text-2xl font-extrabold ${color}`}>{value}</p>
          </div>
        ))}
      </section>

      <section className="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-white p-4">
        {isManager && (
          <label className="min-w-[220px] flex-1 space-y-1 text-xs font-bold text-slate-500">
            Kelompok
            <select
              value={filters.group_id}
              onChange={(event) => updateFilter("group_id", event.target.value)}
              className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700"
            >
              <option value="">Semua kelompok</option>
              {groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
            </select>
          </label>
        )}
        <label className="min-w-[200px] flex-1 space-y-1 text-xs font-bold text-slate-500">
          Status tugas
          <select
            value={filters.status}
            onChange={(event) => updateFilter("status", event.target.value)}
            className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700"
          >
            <option value="">Semua status</option>
            <option value="todo,in_progress,inprogress,in_review,review">Belum selesai</option>
            <option value="in_review,review">Perlu ditinjau</option>
            <option value="done,completed,Selesai">Selesai</option>
          </select>
        </label>
        <div className="flex gap-2">
          <button onClick={resetFilters} className="h-10 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 hover:bg-slate-50">
            Reset
          </button>
          <button onClick={handleExport} disabled={exporting || loading} className="h-10 rounded-xl bg-violet-600 px-4 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-60">
            {exporting ? "Menyiapkan..." : "Ekspor CSV"}
          </button>
        </div>
      </section>

      {error && <p role="alert" className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}

      <section className="overflow-hidden rounded-2xl border border-slate-100 bg-white">
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <div>
            <h2 className="text-sm font-extrabold text-slate-800">Daftar tugas</h2>
            <p className="mt-0.5 text-xs text-slate-400">{tasks.length} tugas ditemukan</p>
          </div>
          {loading && <span className="text-xs font-semibold text-violet-600">Memuat...</span>}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-xs">
            <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Tugas</th>
                {isManager && <th className="px-4 py-3">Kelompok</th>}
                {isManager && <th className="px-4 py-3">Pemagang</th>}
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Deadline</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {!loading && tasks.length === 0 ? (
                <tr>
                  <td colSpan={isManager ? 5 : 3} className="px-4 py-10 text-center text-slate-400">
                    Tidak ada tugas untuk filter ini.
                  </td>
                </tr>
              ) : tasks.map((task) => (
                <tr key={task.id} className="hover:bg-slate-50/70">
                  <td className="px-4 py-3">
                    <button onClick={() => router.push(`/dashboard/tasks?task=${task.id}`)} className="text-left font-bold text-violet-700 hover:underline">
                      {task.title}
                    </button>
                  </td>
                  {isManager && <td className="px-4 py-3 text-slate-600">{task.group || "-"}</td>}
                  {isManager && <td className="px-4 py-3 text-slate-600">{task.assignee || "-"}</td>}
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${statusStyle(task.status)}`}>
                      {statusLabel(task.status)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{formatDueDate(task.due_date)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
