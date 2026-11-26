"use client";

import React from "react";

export default function SharedGroupPanel({ groups = [], refreshing = false }) {
  const visibleGroups = groups.filter((group) => group.group_members?.length);

  return (
    <div className="space-y-6">
      <header className="border-b border-slate-100 pb-5">
        <p className="text-xs font-bold uppercase tracking-wider text-violet-600">Kelompok saya</p>
        <h1 className="mt-1 text-2xl font-extrabold tracking-tight text-slate-900">Anggota kelompok</h1>
        <p className="mt-1 text-sm text-slate-500">
          Nama pemagang dalam kelompok yang kamu ikuti.
        </p>
      </header>

      {refreshing && <div className="h-0.5 animate-pulse bg-violet-400" />}
      {visibleGroups.length ? visibleGroups.map((group) => {
        const members = (group.group_members || [])
          .map((member) => member.profiles)
          .filter((member) => member && member.status !== "rejected")
          .sort((first, second) =>
            (first.full_name || first.name || "").localeCompare(second.full_name || second.name || "", "id")
          );
        return (
          <section key={group.id} className="rounded-2xl border border-slate-100 bg-white p-5">
            <h2 className="text-sm font-extrabold text-slate-800">{group.name}</h2>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {members.map((member) => (
                <div key={member.id} className="flex min-w-0 items-center gap-3 rounded-xl bg-slate-50 px-3 py-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">
                    {(member.full_name || member.name || "?").slice(0, 1).toUpperCase()}
                  </span>
                  <p className="truncate text-sm font-semibold text-slate-700">{member.full_name || member.name}</p>
                </div>
              ))}
            </div>
          </section>
        );
      }) : (
        <p className="rounded-2xl border border-dashed border-slate-200 bg-white py-10 text-center text-xs text-slate-400">
          Kamu belum tergabung dalam kelompok.
        </p>
      )}
    </div>
  );
}
