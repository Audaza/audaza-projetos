# audaza-links

Encurtador de URLs com tracking de cliques e dashboard de analytics.

- **Dashboard:** https://audaza.com/apps/links/
- **Domínios de redirect:** `link.audaza.com/{slug}`, `go.audaza.com/{slug}`, `bio.audaza.com/{slug}`

## Estrutura de arquivos

```
links/
├── index.html             ← Dashboard (HTML único)
├── supabase-schema.sql    ← Rodar UMA VEZ no SQL Editor do Supabase
├── README.md              ← Este arquivo
└── redirector/
    ├── index.php          ← Lógica do redirect (uploaded em cada subdomínio)
    ├── .htaccess          ← Rewrite rules
    ├── config.example.php ← Modelo (versionado)
    └── config.php         ← Credenciais REAIS (gitignored, upload manual)
```

## Setup — passo a passo

### 1️⃣ Supabase (uma vez)

1. Abrir https://supabase.com/dashboard/project/seykakvwlcjewjflkkdt
2. Menu lateral → **SQL Editor** → **New query**
3. Colar conteúdo de `supabase-schema.sql` → **Run**
4. Confirmar que apareceram tabelas em **Database → Tables**:
   - `audazalinks_links`
   - `audazalinks_clicks`

### 2️⃣ Criar usuário admin (uma vez)

1. No Supabase Dashboard: **Authentication → Users → Add user → Create new user**
2. E-mail: `audazadigital@gmail.com`
3. Senha: defina uma forte (anote)
4. **Auto Confirm User**: ✅ marcar
5. Clicar **Create user**

> Esse será o login do dashboard.

### 3️⃣ Criar subdomínios no Hostinger

No painel do Hostinger:

1. **Domínios → audaza.com → Subdomínios**
2. Criar `link` → cria `link.audaza.com` (gera SSL automático em alguns minutos)
3. Criar `go` → cria `go.audaza.com`
4. `bio` já existe (linkbio).

> Cada subdomínio cria a pasta `/domains/{nome}.audaza.com/public_html/`.

### 4️⃣ Subir o `config.php` em cada subdomínio (uma vez)

O arquivo `config.php` tem credenciais → não é versionado. Upload manual via FTP:

**Conta FTP** (ver `reference_hostinger_ftp.md` na memória):
- Host: `147.93.37.152` · Porta `21` · User: `u390595299`

Subir o arquivo `links/redirector/config.php` para:
- `/domains/link.audaza.com/public_html/config.php`
- `/domains/go.audaza.com/public_html/config.php`
- `/domains/bio.audaza.com/public_html/config.php`

**Como fazer (FileZilla):**
1. Conectar com as credenciais acima
2. Navegar até `domains/link.audaza.com/public_html/`
3. Arrastar `config.php` local pra lá
4. Repetir para `go.audaza.com` e `bio.audaza.com`

### 5️⃣ Commit + push (deploy automático)

```bash
cd "/Users/audazadigital/Documents/Audaza - Claude/audaza-projetos"
git add .
git commit -m "feat: audaza-links — encurtador + tracking"
git push origin main
```

Dois workflows vão rodar:
- **Deploy FTP para Hostinger** → sobe `links/index.html` em `audaza.com/apps/links/`
- **Deploy redirector audaza-links** → sobe `index.php` + `.htaccess` em cada subdomínio

> Acompanhar em https://github.com/Audaza/audaza-projetos/actions

### 6️⃣ Testar

#### a) Dashboard
1. Abrir https://audaza.com/apps/links/
2. Login com `audazadigital@gmail.com` + senha definida no passo 2
3. Criar um link de teste (destino: `https://google.com`, deixe slug vazio)
4. Aparece a URL curta tipo `link.audaza.com/x7K9p2`

#### b) Redirect
1. Abrir essa URL curta no navegador → deve redirecionar pro Google
2. Voltar ao dashboard, abrir o detalhe do link
3. Em alguns segundos o clique aparece nos gráficos

#### c) Conflito com biolinks (importante!)
1. Acessar `bio.audaza.com/{slug-de-um-biolink-existente}` → ainda funciona normal (a página do biolink)
2. Acessar `bio.audaza.com/qualquer-coisa-aleatoria` → cai no PHP → 404 ou redireciona se tiver cadastrado

## Como o sistema funciona

```
1. Usuário clica em link.audaza.com/x7K9p2
2. Apache (Hostinger) → .htaccess → index.php
3. PHP pega slug ("x7K9p2") + host ("link.audaza.com")
4. PHP chama Supabase RPC: audazalinks_lookup(host, slug)
5. Supabase retorna o destino + UTMs
6. PHP redireciona 302 IMEDIATAMENTE
7. Depois do redirect, PHP loga o clique no Supabase (assíncrono via fastcgi_finish_request)
```

Latência total: ~80-150ms.

## Enriquecimento de dados (geo, browser parser)

Versão atual: PHP captura `device_type` (mobile/tablet/desktop/bot), IP, user-agent, referrer.

**Pendente (Fase 2):** rodar job no n8n a cada 5min que pega cliques com `enriched=false` e:
- Resolve país/cidade via API de geo (ipapi.co, ipinfo.io)
- Parser do user-agent → extrai browser + OS
- Marca `enriched=true`

> Enquanto isso, países e navegadores ficam vazios no dashboard.

## Fase 2 — TODO

- [ ] Job n8n de enriquecimento (geo + UA parser)
- [ ] Senha por link
- [ ] A/B test (split traffic) — DB já preparado, só faltam UI
- [ ] Visão "todos os cliques" agregada
- [ ] Export CSV
- [ ] Notificação quando link bate X cliques
