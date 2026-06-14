<?php
// ============================================================
// VESTIA API — Category Controller
// ============================================================
class CategoryController {
    public static function index(): void {
        $db = getDB();

        // ✅ التحقق من أن lang قيمة مسموح بها فقط
        $lang = $_GET['lang'] ?? 'en';
        if (!in_array($lang, ['en', 'ar', 'fr'])) $lang = 'en';

        $stmt = $db->query('SELECT id, name, name_ar, name_fr, slug FROM categories ORDER BY sort_order ASC');
        $rows = $stmt->fetchAll();

        $categories = array_map(function($c) use ($lang) {
            $localizedName = match($lang) {
                'ar'    => $c['name_ar'] ?: $c['name'],
                'fr'    => $c['name_fr'] ?: $c['name'],
                default => $c['name'],
            };
            return [
                'id'   => (int)$c['id'],
                'name' => $localizedName,
                'slug' => $c['slug'],
            ];
        }, $rows);

        jsonSuccess(['categories' => $categories]);
    }
}
