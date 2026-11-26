"use client";

import React, { useMemo, useState } from "react";
import { addGroupMember, createGroup, removeGroupMember } from "../../../backend/groups";

export default function GroupManagementPanel({ groups = [], users = [], mentors = [], role, refreshing = false, onRefresh }) {
  const [name, setName] = useState("");
  const [mentorId, setMentorId] = useState("");
  const [selectedMembers, setSelectedMembers] = useState({});
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const activeInterns = useMemo(
    () => users.filter((user) =>
      ["pemagang", "intern"].includes(user.role) && user.status === "active"
    ),
    [users]
  );

  async function handleCreateGroup(event) {
    event.preventDefault();
    if (!name.trim()) return;

    setBusy("create");
    setError("");
    setMessage("");
    try {
      await createGroup({
        name: name.trim(),
        ...(role === "admin" ? { mentor_id: mentorId || null } : {}),
      });
      setName("");
      setMentorId("");
      setMessage("Kelompok berhasil ditambahkan.");
      await onRefresh();
    } catch (createError) {
      setError(createError.message || "Gagal menambahkan kelompok.");
    } finally {
      setBusy("");
    }
  }

  async function handleAddMember(group) {
    const userId = selectedMembers[group.id];
    if (!userId) return;

    setBusy(`add-${group.id}`);
    setError("");
    setMessage("");
    try {
      await addGroupMember(group.id, Number(userId));
      setSelectedMembers((current) => ({ ...current, [group.id]: "" }));
      setMessage("Anggota berhasil ditambahkan.");
      await onRefresh();
    } catch (addError) {
      setError(addError.message || "Gagal menambahkan anggota.");
    } finally {
      setBusy("");
    }
  }

  async function handleRemoveMember(group, member) {
    const memberName = member.profiles?.full_name || member.profiles?.name || "anggota ini";
    if (!window.confirm(`Hapus ${memberName} dari ${group.name}? Tugas yang sudah diberikan tetap dapat diakses pemagang.`)) return;

    setBusy(`remove-${group.id}-${member.user_id}`);
    setError("");
    setMessage("");
    try {
      await removeGroupMember(group.id, member.user_id);
      setMessage(`${memberName} berhasil dihapus dari kelompok.`);
      await onRefresh();
    } catch (removeError) {
      setError(removeError.message || "Gagal menghapus anggota.");
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="space-y-5">
      <header className="border-b border-slate-100 pb-5">
        <p className="text-xs font-bold uppercase tracking-wider text-violet-600">Kelompok kerja</p>
        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-slate-900">Kelola Kelompok</h1>
        <p className="mt-1 text-sm text-slate-500">
          Tambahkan kelompok dan atur anggota. Pemagang dapat bergabung di lebih dari satu kelompok.
        </p>
      </header>

      <form
        onSubmit={handleCreateGroup}
        className={`grid gap-3 rounded-2xl border border-violet-100 bg-violet-50/50 p-4 ${
          role === "admin" ? "sm:grid-cols-[minmax(0,1fr)_minmax(12rem,auto)_auto]" : "sm:grid-cols-[minmax(0,1fr)_auto]"
        }`}
      >
        <label className="space-y-1 text-xs font-bold text-slate-600">
          Nama kelompok baru
          <input
            required
            maxLength={255}
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Contoh: Tim Pengolahan Data"
            className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium"
          />
        </label>
        {role === "admin" && (
          <label className="space-y-1 text-xs font-bold text-slate-600">
            Mentor penanggung jawab
            <select value={mentorId} onChange={(event) => setMentorId(event.target.value)} className="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium">
              <option value="">Hanya admin</option>
              {mentors.map((mentor) => <option key={mentor.id} value={mentor.id}>{mentor.full_name || mentor.name}</option>)}
            </select>
          </label>
        )}
        <button disabled={busy === "create"} className="h-10 self-end rounded-xl bg-violet-600 px-4 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-50">
          {busy === "create" ? "Menyimpan..." : "+ Tambah kelompok"}
        </button>
      </form>

      {error && <p role="alert" className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}
      {message && <p role="status" className="rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{message}</p>}

      {refreshing && <div className="h-0.5 animate-pulse bg-violet-400" />}

      <section className="space-y-3">
        {groups.length ? groups.map((group) => {
          const memberIds = new Set((group.group_members || []).map((member) => String(member.user_id)));
          const candidates = activeInterns.filter((intern) => !memberIds.has(String(intern.id)));

          return (
            <article key={group.id} className="overflow-hidden rounded-2xl border border-slate-100 bg-white">
              <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-4">
                <div>
                  <h2 className="text-sm font-extrabold text-slate-800">{group.name}</h2>
                  <p className="mt-1 text-[11px] text-slate-400">
                    {group.is_default ? "Kelompok utama" : "Kelompok tambahan"}
                    {group.mentor_id ? ` · ${mentors.find((mentor) => mentor.id === group.mentor_id)?.full_name || "Mentor"}` : ""}
                  </p>
                </div>
                <span className="rounded-full bg-violet-50 px-3 py-1 text-[10px] font-bold text-violet-700">
                  {(group.group_members || []).length} anggota
                </span>
              </header>

              <div className="space-y-3 p-4">
                <div className="flex flex-col gap-2 sm:flex-row">
                  <select
                    value={selectedMembers[group.id] || ""}
                    onChange={(event) => setSelectedMembers((current) => ({ ...current, [group.id]: event.target.value }))}
                    className="h-10 min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                  >
                    <option value="">Pilih pemagang aktif untuk ditambahkan</option>
                    {candidates.map((intern) => <option key={intern.id} value={intern.id}>{intern.full_name || intern.name}</option>)}
                  </select>
                  <button
                    disabled={!selectedMembers[group.id] || busy === `add-${group.id}`}
                    onClick={() => handleAddMember(group)}
                    className="h-10 rounded-xl border border-violet-200 px-4 text-xs font-bold text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {busy === `add-${group.id}` ? "Menambahkan..." : "Tambah anggota"}
                  </button>
                </div>

                {(group.group_members || []).length ? (
                  <div className="divide-y divide-slate-50 rounded-xl border border-slate-100">
                    {group.group_members.map((member) => {
                      const memberName = member.profiles?.full_name || member.profiles?.name || "Pemagang";
                      const removeKey = `remove-${group.id}-${member.user_id}`;
                      return (
                        <div key={member.user_id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                          <span className="flex min-w-0 items-center gap-2.5">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">
                              {memberName.slice(0, 1).toUpperCase()}
                            </span>
                            <span className="truncate text-sm font-medium text-slate-700">{memberName}</span>
                          </span>
                          <button
                            onClick={() => handleRemoveMember(group, member)}
                            disabled={busy === removeKey}
                            className="shrink-0 rounded-lg px-2.5 py-1.5 text-[11px] font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                          >
                            {busy === removeKey ? "Menghapus..." : "Hapus"}
                          </button>
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  <p className="rounded-xl bg-slate-50 px-3 py-5 text-center text-xs text-slate-400">Belum ada anggota di kelompok ini.</p>
                )}
              </div>
            </article>
          );
        }) : (
          <p className="rounded-2xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-400">
            Belum ada kelompok. Tambahkan kelompok baru di atas.
          </p>
        )}
      </section>
    </div>
  );
}
