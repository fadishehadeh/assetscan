<?php
/**
 * Language helper
 * ─────────────────────────────────────────────────────────────────────────
 * Loads the $LANG array from lang/{lang}.php.
 * Priority: $_SESSION['lang']  →  settings.app_language (from DB)  →  'en'
 *
 * Exposes:
 *   __($key, ...$args): string   — translate a key, optional sprintf args
 *   is_rtl(): bool               — true when current lang is RTL (Arabic)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Determine active language ──────────────────────────────────────────
$_activeLang = 'en'; // hard default

// 1. Session override
if (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], ['en', 'ar'], true)) {
    $_activeLang = $_SESSION['lang'];
} else {
    // 2. DB setting — only if we have a PDO connection available
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->query("SELECT app_language FROM settings WHERE id = 1 LIMIT 1");
            $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($row && in_array($row['app_language'], ['en', 'ar'], true)) {
                $_activeLang = $row['app_language'];
            }
        } elseif (file_exists(__DIR__ . '/../config/db.php')) {
            // Try loading DB if not already loaded
            require_once __DIR__ . '/../config/db.php';
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->query("SELECT app_language FROM settings WHERE id = 1 LIMIT 1");
                $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($row && in_array($row['app_language'], ['en', 'ar'], true)) {
                    $_activeLang = $row['app_language'];
                }
            }
        }
    } catch (Throwable $e) {
        // Fall back to 'en' silently
    }
}

// Store resolved lang in session for consistency across request
$_SESSION['lang'] = $_activeLang;

// ── Load language file ─────────────────────────────────────────────────
$_langFile = __DIR__ . '/../lang/' . $_activeLang . '.php';

if (!file_exists($_langFile)) {
    $_langFile = __DIR__ . '/../lang/en.php';
}

$LANG = require $_langFile;

if (!is_array($LANG)) {
    $LANG = [];
}

// ── RTL languages ──────────────────────────────────────────────────────
$_RTL_LANGS = ['ar', 'he', 'fa', 'ur'];

// ── Helper functions ───────────────────────────────────────────────────

/**
 * Translate a language key.
 * Falls back to the key itself if not found.
 * Accepts optional sprintf arguments.
 *
 * Usage:
 *   echo __('action_save');            // → "Save"  or  "حفظ"
 *   echo __('msg_warranty_expiring', 30);  // → "Warranty expires in 30 days."
 */
function __($key, ...$args): string
{
    global $LANG;
    $str = $LANG[$key] ?? $key;
    return $args ? vsprintf($str, $args) : $str;
}

/**
 * Returns true when the current language is right-to-left.
 */
function is_rtl(): bool
{
    global $_activeLang, $_RTL_LANGS;
    return in_array($_activeLang, $_RTL_LANGS, true);
}

/**
 * Returns the current active language code ('en' | 'ar' | …).
 */
function current_lang(): string
{
    global $_activeLang;
    return $_activeLang;
}
