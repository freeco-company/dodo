/**
 * Programmatic API helpers — used by specs that want to skip the
 * onboarding UI flow and jump straight into the authenticated app.
 *
 * Mirrors the contract in routes/api.php. All requests follow the
 * Laravel envelope shape ({ data, message?, errors? }) returned by
 * the backend after the ADR-008 alignment.
 */

const BACKEND = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

export type RegisteredUser = {
  token: string;
  userId: number | string;
  email: string;
  name: string;
};

/**
 * POST /api/auth/register — creates a fresh user with a unique email
 * and returns the sanctum token. The token is what config.js persists
 * to localStorage under `dodo_token`.
 */
export async function registerUser(opts: {
  name?: string;
  height_cm?: number;
  current_weight_kg?: number;
  target_weight_kg?: number;
  avatar_animal?: string;
  activity_level?: string;
  gender?: string;
} = {}): Promise<RegisteredUser> {
  const stamp = Date.now() + Math.floor(Math.random() * 1e4);
  const email = `e2e+${stamp}@dodo.local`;
  const name = opts.name ?? `e2e-${stamp}`;
  const body = {
    email,
    password: 'password123',
    name,
    height_cm: opts.height_cm ?? 165,
    current_weight_kg: opts.current_weight_kg ?? 65,
    target_weight_kg: opts.target_weight_kg ?? 60,
    avatar_animal: opts.avatar_animal ?? 'cat',
    activity_level: opts.activity_level ?? 'sedentary',
    gender: opts.gender ?? 'female',
  };

  const r = await fetch(`${BACKEND}/api/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  });
  if (!r.ok) {
    const text = await r.text();
    throw new Error(`registerUser failed (${r.status}): ${text}`);
  }
  const json = await r.json() as any;
  // Some routes wrap in { data }, others return flat — be tolerant.
  const data = json.data ?? json;
  const token = data.token ?? data.access_token ?? json.token;
  const userId = (data.user && (data.user.id ?? data.user.uuid)) ?? data.user_id ?? data.id;
  if (!token) throw new Error(`registerUser: no token in response: ${JSON.stringify(json)}`);
  return { token, userId, email, name };
}

/**
 * Authenticated helper for arbitrary endpoint hits. Returns parsed JSON.
 */
export async function api(
  token: string,
  method: string,
  path: string,
  body?: unknown,
): Promise<any> {
  const r = await fetch(`${BACKEND}${path.startsWith('/api') ? path : `/api${path}`}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  let parsed: any = null;
  try { parsed = text ? JSON.parse(text) : null; } catch { parsed = text; }
  return { status: r.status, body: parsed };
}
