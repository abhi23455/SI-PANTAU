"use client";

import React, { useEffect, useState } from "react";
import { getActiveUser, getProfile } from "../../../backend/auth";
import { getAllUsers } from "../../../backend/admin";
import { getUserGroups } from "../../../backend/groups";
import GroupManagementPanel from "./GroupManagementPanel";
import SharedGroupPanel from "./SharedGroupPanel";

export default function TeamPage() {
  const [role, setRole] = useState("");
  const [groups, setGroups] = useState([]);
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let active = true;

    async function loadGroups(silent = false) {
      if (silent) setRefreshing(true);
      else setLoading(true);

      try {
        const account = await getActiveUser();
        if (!account) throw new Error("Sesi login tidak ditemukan. Silakan masuk kembali.");

        const [profile, availableGroups] = await Promise.all([
          getProfile(account.id),
          getUserGroups(),
        ]);
        const manager = ["admin", "mentor"].includes(profile.role);
        const directory = manager ? await getAllUsers({ forceRefresh: true }) : [];

        if (active) {
          setRole(profile.role);
          setGroups((availableGroups || []).filter((group) => !group.is_deleted));
          setUsers(directory || []);
          setError("");
        }
      } catch (loadError) {
        if (active) setError(loadError.message || "Gagal memuat kelompok.");
      } finally {
        if (active) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    }

    loadGroups();
    const interval = setInterval(() => loadGroups(true), 60000);

    return () => {
      active = false;
      clearInterval(interval);
    };
  }, [retry]);

  const refreshGroups = () => setRetry((current) => current + 1);

  if (loading) {
    return <div className="py-16 text-center text-sm font-medium text-slate-400">Memuat kelompok...</div>;
  }

  if (error && !groups.length) {
    return (
      <div className="rounded-2xl border border-rose-100 bg-rose-50 p-5 text-sm text-rose-700">
        <p>{error}</p>
        <button onClick={refreshGroups} className="mt-3 font-bold underline">Coba lagi</button>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {error && <p role="alert" className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</p>}
      {role === "admin" || role === "mentor" ? (
        <GroupManagementPanel
          groups={groups}
          users={users}
          mentors={users.filter((user) => user.role === "mentor" && user.status === "active")}
          role={role}
          refreshing={refreshing}
          onRefresh={refreshGroups}
        />
      ) : (
        <SharedGroupPanel groups={groups} refreshing={refreshing} />
      )}
    </div>
  );
}
