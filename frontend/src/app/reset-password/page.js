"use client";

import React, { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiRequest } from "../../backend/client";

export default function ResetPasswordPage() {
  const router = useRouter();
  const [token, setToken] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    setToken(params.get("token") || "");
    setEmail(params.get("email") || "");
  }, []);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError("");

    if (!token || !email) {
      setError("Tautan reset password tidak valid atau sudah kedaluwarsa.");
      return;
    }
    if (password.length < 8) {
      setError("Password minimal 8 karakter.");
      return;
    }
    if (password !== confirmPassword) {
      setError("Password dan konfirmasi password tidak cocok.");
      return;
    }

    setLoading(true);
    try {
      await apiRequest("/auth/password/reset", {
        method: "POST",
        auth: false,
        body: {
          token,
          email,
          password,
          password_confirmation: confirmPassword,
        },
      });
      setSuccess(true);
      setTimeout(() => router.push("/"), 2500);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-100/50 flex flex-col items-center justify-center p-4">
      <div className="w-full max-w-[480px] bg-white p-8 sm:p-10 flex flex-col rounded-[2.5rem] shadow-xl border border-slate-100/50">
        <div className="text-center mb-8">
          <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight mb-2">
            Password Baru
          </h1>
          <p className="text-sm font-medium text-slate-500">
            Silakan buat password baru yang aman untuk akun Anda.
          </p>
        </div>

        {success ? (
          <div className="bg-emerald-50 border border-emerald-100 rounded-2xl p-6 text-center">
            <h3 className="text-emerald-700 font-extrabold text-lg">Berhasil!</h3>
            <p className="text-xs font-semibold text-emerald-600/80 mt-2">
              Password telah diperbarui. Mengarahkan ke halaman login...
            </p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="flex flex-col gap-5">
            {error && (
              <div role="alert" className="bg-rose-50 border border-rose-100 text-rose-600 px-4 py-3 rounded-xl text-sm font-semibold">
                {error}
              </div>
            )}

            {email && (
              <p className="text-sm text-slate-500 text-center">
                Reset password untuk <strong>{email}</strong>
              </p>
            )}

            <label className="flex flex-col gap-1.5 text-xs font-bold text-slate-700">
              Password Baru
              <input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                placeholder="Minimal 8 karakter"
                autoComplete="new-password"
                required
                minLength={8}
                className="w-full border border-slate-200 bg-slate-50/30 rounded-full px-5 py-3 text-sm text-slate-800 outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500"
              />
            </label>

            <label className="flex flex-col gap-1.5 text-xs font-bold text-slate-700">
              Konfirmasi Password Baru
              <input
                type="password"
                value={confirmPassword}
                onChange={(event) => setConfirmPassword(event.target.value)}
                placeholder="Ketik ulang password baru"
                autoComplete="new-password"
                required
                minLength={8}
                className="w-full border border-slate-200 bg-slate-50/30 rounded-full px-5 py-3 text-sm text-slate-800 outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500"
              />
            </label>

            <button
              type="submit"
              disabled={loading}
              className="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold py-3 px-6 rounded-full shadow-lg transition-all text-sm disabled:opacity-70"
            >
              {loading ? "Memproses..." : "Simpan Password Baru"}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
