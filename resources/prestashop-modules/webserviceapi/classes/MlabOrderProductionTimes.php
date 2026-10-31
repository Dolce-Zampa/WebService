<?php

class MlabOrderProductionTimes
{
    public static function format(array $items)
    {
        $html = '<div style="padding:14px 0;font-family:Arial,Helvetica,sans-serif;color:#41403E;"><strong>Tempi di produzione</strong>';
        $text = "\n\nTempi di produzione:\n";
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $seller = trim((string) ($item['seller'] ?? ''));
            $available = trim((string) ($item['available_now'] ?? ''));
            $days = preg_match('/\d+/', $available, $match) ? (int) $match[0] : 0;
            $time = $days > 0 ? $days . ($days === 1 ? ' giorno' : ' giorni') : 'non disponibile';
            $label = $name . ($seller !== '' ? ' — ' . $seller : '') . ': ' . $time;
            $html .= '<div style="padding-top:5px;">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            $text .= $label . "\n";
        }

        return array($html . '</div>', $text);
    }
}
