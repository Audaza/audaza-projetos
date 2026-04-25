<?php
/**
 * audaza-links — Configuração
 *
 * Copie este arquivo para `config.php` (mesma pasta) e preencha com
 * suas credenciais do Supabase. NUNCA commitar config.php no git.
 */

// URL do projeto Supabase
define('SUPABASE_URL', 'https://SEU-PROJETO.supabase.co');

// Service role key (servidor — bypassa RLS).
// Pegar em: Supabase Dashboard → Settings → API → service_role secret
define('SUPABASE_SERVICE_KEY', 'sb_secret_...');
