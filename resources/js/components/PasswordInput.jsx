import { useState } from 'react';

/**
 * Password field with a show/hide toggle. Hidden by default; the toggle only
 * switches the SAME input's `type` between `password` and `text`, so the typed
 * value is never lost. The toggle is a real <button type="button"> (focusable,
 * Enter/Space, never submits the form) with an Indonesian aria-label and
 * `aria-pressed`, and it points at the input via `aria-controls`.
 *
 * Every other prop goes straight to the <input>, so callers keep their own
 * styling, autocomplete and validation attributes.
 */
export default function PasswordInput({ id, className = '', disabled = false, ...inputProps }) {
  const [visible, setVisible] = useState(false);
  const label = visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi';

  return (
    <div className="relative">
      <input
        {...inputProps}
        id={id}
        type={visible ? 'text' : 'password'}
        disabled={disabled}
        className={`${className} pr-10`}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        disabled={disabled}
        aria-label={label}
        aria-pressed={visible}
        aria-controls={id}
        title={label}
        className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-gray-500 hover:text-gray-900 focus-visible:ring-2 focus-visible:ring-accent focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
      >
        {visible ? <EyeOffIcon /> : <EyeIcon />}
      </button>
    </div>
  );
}

function EyeIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2"
      strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  );
}

function EyeOffIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2"
      strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.5 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94" />
      <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19" />
      <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" />
      <line x1="2" y1="2" x2="22" y2="22" />
    </svg>
  );
}
