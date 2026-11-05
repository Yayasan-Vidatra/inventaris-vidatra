import { useState } from 'react';

import PasswordInput from '../components/PasswordInput';
import { api, ApiError } from '../lib/api';
import { controlClass } from '../lib/assetFields';

const MIN_LENGTH = 12;

/**
 * "Ubah Kata Sandi" — every signed-in user changes their OWN password
 * (`PUT /api/me/password`). The backend is authoritative for every rule
 * (current password, min 12, confirmation, must differ); the checks here only
 * save a round trip. On success the server regenerates this session and ends
 * the user's other sessions; this page stays signed in.
 */
export default function ChangePassword() {
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [success, setSuccess] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const validate = () => {
    const errs = {};
    if (!currentPassword) errs.current_password = 'Kata sandi saat ini wajib diisi.';
    if (!password) errs.password = 'Kata sandi baru wajib diisi.';
    else if (password.length < MIN_LENGTH) errs.password = `Kata sandi baru minimal ${MIN_LENGTH} karakter.`;
    else if (currentPassword && password === currentPassword)
      errs.password = 'Kata sandi baru harus berbeda dari kata sandi saat ini.';
    if (!passwordConfirmation) errs.password_confirmation = 'Konfirmasi kata sandi baru wajib diisi.';
    else if (password && password !== passwordConfirmation)
      errs.password_confirmation = 'Konfirmasi kata sandi baru tidak cocok.';
    return errs;
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    setFormError('');
    setSuccess('');
    const clientErrors = validate();
    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(clientErrors);
      return;
    }
    setFieldErrors({});
    setSubmitting(true);

    try {
      const res = await api.put('/api/me/password', {
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      });
      setCurrentPassword('');
      setPassword('');
      setPasswordConfirmation('');
      setSuccess(
        res?.other_sessions_ended > 0
          ? 'Kata sandi berhasil diubah. Sesi di perangkat lain telah diakhiri.'
          : 'Kata sandi berhasil diubah.',
      );
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.status === 422) {
          const mapped = {};
          for (const [key, msgs] of Object.entries(e.errors ?? {})) {
            mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
          }
          setFieldErrors(mapped);
          setFormError('Kata sandi belum diubah. Periksa kembali isian Anda.');
        } else if (e.status === 429) {
          setFormError('Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.');
        } else if (e.status === 0) {
          setFormError(e.message);
        } else {
          setFormError('Kata sandi belum diubah karena terjadi kesalahan. Coba lagi.');
        }
      } else {
        setFormError('Terjadi kesalahan tak terduga. Coba lagi.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Ubah Kata Sandi</h1>
        <p className="mt-1 text-sm text-gray-500">
          Kata sandi baru minimal {MIN_LENGTH} karakter dan harus berbeda dari kata sandi saat ini.
        </p>
      </header>

      <form
        onSubmit={handleSubmit}
        noValidate
        className="max-w-md space-y-4 rounded-xl border border-gray-200 bg-surface p-6 shadow-sm"
      >
        {success && (
          <div
            role="status"
            className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800"
          >
            {success}
          </div>
        )}
        {formError && (
          <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
            {formError}
          </div>
        )}

        <Field id="current_password" label="Kata Sandi Saat Ini" error={fieldErrors.current_password}>
          <PasswordInput
            id="current_password"
            autoComplete="current-password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            disabled={submitting}
            aria-invalid={Boolean(fieldErrors.current_password)}
            aria-describedby={fieldErrors.current_password ? 'current_password-error' : undefined}
            className={controlClass(fieldErrors.current_password)}
          />
        </Field>

        <Field id="password" label="Kata Sandi Baru" error={fieldErrors.password}>
          <PasswordInput
            id="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            disabled={submitting}
            aria-invalid={Boolean(fieldErrors.password)}
            aria-describedby={fieldErrors.password ? 'password-error' : undefined}
            className={controlClass(fieldErrors.password)}
          />
        </Field>

        <Field id="password_confirmation" label="Konfirmasi Kata Sandi Baru" error={fieldErrors.password_confirmation}>
          <PasswordInput
            id="password_confirmation"
            autoComplete="new-password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            disabled={submitting}
            aria-invalid={Boolean(fieldErrors.password_confirmation)}
            aria-describedby={fieldErrors.password_confirmation ? 'password_confirmation-error' : undefined}
            className={controlClass(fieldErrors.password_confirmation)}
          />
        </Field>

        <button
          type="submit"
          disabled={submitting}
          className="w-full rounded-md bg-accent px-4 py-2 text-sm font-medium text-accent-fg transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
        >
          {submitting ? 'Menyimpan…' : 'Simpan Kata Sandi'}
        </button>
      </form>
    </div>
  );
}

function Field({ id, label, error, children }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-gray-700">
        {label}
      </label>
      {children}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
