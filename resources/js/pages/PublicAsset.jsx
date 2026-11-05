import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';

import { api, ApiError } from '../lib/api';

/**
 * Public, read-only asset page reached by scanning a label QR (`/q/:token`).
 * No login and no app chrome: one asset, the backend's allowlisted fields only
 * (`GET /api/public/assets/{token}`), and no action of any kind.
 */
export default function PublicAsset() {
  const { token } = useParams();
  const [asset, setAsset] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | not_found | error

  useEffect(() => {
    let alive = true;
    setPhase('loading');
    api
      .get(`/api/public/assets/${encodeURIComponent(token ?? '')}`)
      .then((res) => {
        if (!alive) return;
        setAsset(res?.data ?? null);
        setPhase(res?.data ? 'ready' : 'not_found');
      })
      .catch((e) => {
        if (!alive) return;
        setPhase(e instanceof ApiError && e.status === 404 ? 'not_found' : 'error');
      });
    return () => {
      alive = false;
    };
  }, [token]);

  return (
    <div className="min-h-screen bg-page px-4 pt-10 pb-[72px] text-gray-900">
      <div className="mx-auto w-full max-w-lg">
        <p className="mb-4 text-center text-sm font-semibold tracking-tight text-gray-500">Inventaris Vidatra</p>

        {phase === 'loading' && (
          <div className="rounded-xl border border-gray-200 bg-surface p-6 text-center text-sm text-gray-500 shadow-sm">
            Memuat data aset…
          </div>
        )}

        {phase === 'not_found' && (
          <div role="alert" className="rounded-xl border border-gray-200 bg-surface p-6 text-center shadow-sm">
            <h1 className="text-lg font-semibold">Aset tidak ditemukan</h1>
            <p className="mt-1 text-sm text-gray-500">Kode QR ini tidak valid atau asetnya tidak tersedia.</p>
          </div>
        )}

        {phase === 'error' && (
          <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-6 text-center text-sm text-red-800">
            Data aset tidak dapat dimuat saat ini. Coba lagi beberapa saat lagi.
          </div>
        )}

        {phase === 'ready' && asset && (
          <article className="overflow-hidden rounded-xl border border-gray-200 bg-surface shadow-sm">
            <header className="border-b border-gray-200 p-6">
              <h1 className="text-xl font-semibold tracking-tight">{asset.name ?? 'Aset'}</h1>
              <p className="mt-1 font-mono text-sm text-gray-600">{asset.asset_code}</p>
              <span
                className={[
                  'mt-3 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium',
                  asset.status === 'written_off' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800',
                ].join(' ')}
              >
                {asset.status_label}
              </span>
            </header>

            {asset.photos?.length > 0 && (
              <div className="grid grid-cols-3 gap-2 border-b border-gray-200 p-4">
                {asset.photos.map((photo) => (
                  <img
                    key={photo.slot}
                    src={photo.url}
                    alt={`Foto aset ${photo.slot}`}
                    loading="lazy"
                    className="aspect-square w-full rounded-lg object-cover"
                  />
                ))}
              </div>
            )}

            <dl className="divide-y divide-gray-100 px-6">
              <Row label="Kategori" value={asset.category} />
              <Row label="Lokasi" value={asset.location} />
              <Row label="Ruangan" value={asset.room} />
              <Row label="Kondisi" value={asset.condition_label} />
            </dl>
          </article>
        )}
      </div>
    </div>
  );
}

function Row({ label, value }) {
  return (
    <div className="flex justify-between gap-4 py-3 text-sm">
      <dt className="text-gray-500">{label}</dt>
      <dd className="text-right font-medium">{value || '—'}</dd>
    </div>
  );
}
