-- ═══════════════════════════════════════════════════════════════
--  audaza-links — Schema Supabase
--  Rodar em: SQL Editor do projeto Supabase (Task Manager)
--  Tabelas: audazalinks_links, audazalinks_clicks
-- ═══════════════════════════════════════════════════════════════

-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
-- 1. TABELA DE LINKS
-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CREATE TABLE IF NOT EXISTS audazalinks_links (
  id            BIGSERIAL PRIMARY KEY,
  slug          TEXT NOT NULL,
  host          TEXT NOT NULL CHECK (host IN ('link.audaza.com', 'go.audaza.com', 'bio.audaza.com')),
  destination   TEXT NOT NULL,

  -- metadados
  title         TEXT,
  description   TEXT,
  tags          TEXT[],

  -- UTMs (opcional, anexados ao destino na hora do redirect)
  utm_source    TEXT,
  utm_medium    TEXT,
  utm_campaign  TEXT,
  utm_term      TEXT,
  utm_content   TEXT,

  -- recursos avançados (Fase 2)
  expires_at    TIMESTAMPTZ,
  password_hash TEXT,
  ab_variants   JSONB,  -- ex: [{"url":"...","weight":50},{"url":"...","weight":50}]

  -- controle
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  click_count   INTEGER NOT NULL DEFAULT 0,
  created_by    UUID REFERENCES auth.users(id) ON DELETE SET NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

  UNIQUE (host, slug)
);

CREATE INDEX IF NOT EXISTS idx_audazalinks_links_lookup
  ON audazalinks_links (host, slug)
  WHERE is_active = TRUE;

CREATE INDEX IF NOT EXISTS idx_audazalinks_links_created
  ON audazalinks_links (created_at DESC);

-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
-- 2. TABELA DE CLIQUES
-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CREATE TABLE IF NOT EXISTS audazalinks_clicks (
  id           BIGSERIAL PRIMARY KEY,
  link_id      BIGINT NOT NULL REFERENCES audazalinks_links(id) ON DELETE CASCADE,
  clicked_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

  -- localização (preenchido async)
  ip           INET,
  country      TEXT,
  country_code CHAR(2),
  region       TEXT,
  city         TEXT,

  -- dispositivo
  user_agent   TEXT,
  device_type  TEXT,   -- mobile | tablet | desktop | bot | unknown
  browser      TEXT,   -- Chrome, Safari, Firefox...
  os           TEXT,   -- Windows, macOS, iOS, Android...

  -- origem
  referer      TEXT,
  referer_host TEXT,   -- domínio extraído (ex: instagram.com)
  host_used    TEXT,   -- link.audaza.com / go.audaza.com / bio.audaza.com

  -- enriquecimento
  enriched     BOOLEAN NOT NULL DEFAULT FALSE  -- TRUE depois de geo/UA parser rodar
);

CREATE INDEX IF NOT EXISTS idx_audazalinks_clicks_link
  ON audazalinks_clicks (link_id, clicked_at DESC);

CREATE INDEX IF NOT EXISTS idx_audazalinks_clicks_at
  ON audazalinks_clicks (clicked_at DESC);

CREATE INDEX IF NOT EXISTS idx_audazalinks_clicks_unenriched
  ON audazalinks_clicks (id)
  WHERE enriched = FALSE;

-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
-- 3. TRIGGER: atualizar updated_at e click_count
-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CREATE OR REPLACE FUNCTION audazalinks_touch_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audazalinks_links_updated ON audazalinks_links;
CREATE TRIGGER trg_audazalinks_links_updated
  BEFORE UPDATE ON audazalinks_links
  FOR EACH ROW
  EXECUTE FUNCTION audazalinks_touch_updated_at();

CREATE OR REPLACE FUNCTION audazalinks_increment_click_count()
RETURNS TRIGGER AS $$
BEGIN
  UPDATE audazalinks_links
     SET click_count = click_count + 1
   WHERE id = NEW.link_id;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audazalinks_clicks_count ON audazalinks_clicks;
CREATE TRIGGER trg_audazalinks_clicks_count
  AFTER INSERT ON audazalinks_clicks
  FOR EACH ROW
  EXECUTE FUNCTION audazalinks_increment_click_count();

-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
-- 4. RPC: lookup_link — usada pelo redirector PHP (rápida e segura)
-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
CREATE OR REPLACE FUNCTION audazalinks_lookup(p_host TEXT, p_slug TEXT)
RETURNS TABLE (
  id            BIGINT,
  destination   TEXT,
  utm_source    TEXT,
  utm_medium    TEXT,
  utm_campaign  TEXT,
  utm_term      TEXT,
  utm_content   TEXT,
  expires_at    TIMESTAMPTZ,
  password_hash TEXT,
  ab_variants   JSONB
) AS $$
  SELECT id, destination, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
         expires_at, password_hash, ab_variants
    FROM audazalinks_links
   WHERE host = p_host
     AND slug = p_slug
     AND is_active = TRUE
   LIMIT 1;
$$ LANGUAGE sql STABLE SECURITY DEFINER;

-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
-- 5. ROW LEVEL SECURITY
-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ALTER TABLE audazalinks_links  ENABLE ROW LEVEL SECURITY;
ALTER TABLE audazalinks_clicks ENABLE ROW LEVEL SECURITY;

-- só usuário autenticado (admin Audaza) acessa pela dashboard
DROP POLICY IF EXISTS "audazalinks_links_authenticated_full" ON audazalinks_links;
CREATE POLICY "audazalinks_links_authenticated_full"
  ON audazalinks_links FOR ALL
  TO authenticated
  USING (TRUE)
  WITH CHECK (TRUE);

DROP POLICY IF EXISTS "audazalinks_clicks_authenticated_read" ON audazalinks_clicks;
CREATE POLICY "audazalinks_clicks_authenticated_read"
  ON audazalinks_clicks FOR SELECT
  TO authenticated
  USING (TRUE);

-- service_role (PHP redirector) bypassa RLS automaticamente. Sem policy pública.

-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
-- 6. VIEWS para dashboard (agregações rápidas)
-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

-- cliques por dia (últimos 30 dias)
CREATE OR REPLACE VIEW audazalinks_v_clicks_per_day AS
SELECT
  link_id,
  DATE(clicked_at AT TIME ZONE 'America/Cuiaba') AS day,
  COUNT(*) AS clicks
FROM audazalinks_clicks
WHERE clicked_at >= NOW() - INTERVAL '30 days'
GROUP BY link_id, day
ORDER BY day;

-- top países
CREATE OR REPLACE VIEW audazalinks_v_clicks_by_country AS
SELECT link_id, country, country_code, COUNT(*) AS clicks
FROM audazalinks_clicks
WHERE country IS NOT NULL
GROUP BY link_id, country, country_code;

-- top devices
CREATE OR REPLACE VIEW audazalinks_v_clicks_by_device AS
SELECT link_id, device_type, COUNT(*) AS clicks
FROM audazalinks_clicks
WHERE device_type IS NOT NULL
GROUP BY link_id, device_type;

-- top browsers
CREATE OR REPLACE VIEW audazalinks_v_clicks_by_browser AS
SELECT link_id, browser, COUNT(*) AS clicks
FROM audazalinks_clicks
WHERE browser IS NOT NULL
GROUP BY link_id, browser;

-- top referrers
CREATE OR REPLACE VIEW audazalinks_v_clicks_by_referer AS
SELECT link_id, referer_host, COUNT(*) AS clicks
FROM audazalinks_clicks
WHERE referer_host IS NOT NULL
GROUP BY link_id, referer_host;

-- horário de pico (hora do dia)
CREATE OR REPLACE VIEW audazalinks_v_clicks_by_hour AS
SELECT
  link_id,
  EXTRACT(HOUR FROM clicked_at AT TIME ZONE 'America/Cuiaba')::int AS hour,
  COUNT(*) AS clicks
FROM audazalinks_clicks
GROUP BY link_id, hour;

-- ═══════════════════════════════════════════════════════════════
-- FIM
-- ═══════════════════════════════════════════════════════════════
