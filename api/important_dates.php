<?php
// api/important_dates.php - returns important Hijri dates and attempts to map month
header('Content-Type: application/json; charset=utf-8');

$hijri_year = isset($_GET['hijri_year']) ? intval($_GET['hijri_year']) : null;
$hijri_month = isset($_GET['hijri_month']) ? intval($_GET['hijri_month']) : null;

// If not provided, query Aladhan gToH for today
if (!$hijri_year || !$hijri_month) {
  $today = date('d-m-Y');
  $url = "http://api.aladhan.com/v1/gToH/".urlencode($today);
  $resp = @file_get_contents($url);
  if ($resp) {
    $j = json_decode($resp, true);
    if (isset($j['data'])) {
      $h = $j['data'];
      $hijri_year = intval($h['hijri']['year']);
      $hijri_month = intval($h['hijri']['month']['number']);
    }
  }
  if (!$hijri_year || !$hijri_month) {
    $hijri_year = date('Y');
    $hijri_month = date('n');
  }
}

// Predefined important events (same mapping as your Python)
$monthly_events = [
  1 => [1=>["New Year"], 10=>["Ashura"], 11=>["Day after Ashura"]],
  3 => [12=>["Mawlid al-Nabi (Sunni)"], 17=>["Mawlid al-Nabi (Shia)"]],
  7 => [27=>["Isra and Mi'raj"]],
  8 => [15=>["Laylat al-Bara'ah"]],
  9 => [1=>["Start of Ramadan"], 21=>["Laylat al-Qadr (possible)"], 23=>["Laylat al-Qadr (possible)"], 25=>["Laylat al-Qadr (possible)"], 27=>["Laylat al-Qadr (most likely)"], 29=>["Laylat al-Qadr (possible)"]],
  10 => [1=>["Eid al-Fitr"]],
  12 => [8=>["Hajj begins"], 9=>["Day of Arafah"], 10=>["Eid al-Adha"], 11=>["Eid al-Adha (2nd day)"], 12=>["Eid al-Adha (3rd day)"], 13=>["Eid al-Adha (4th day)"]],
];

// Try to get matching days from Aladhan calendar (current gregorian month)
$calendar = [];
try {
  $greg_month = date('n');
  $greg_year = date('Y');
  $url = "http://api.aladhan.com/v1/calendar/{$greg_year}/{$greg_month}";
  $resp = @file_get_contents($url);
  if ($resp) {
    $j = json_decode($resp, true);
    if (isset($j['data']) && is_array($j['data'])) {
      foreach ($j['data'] as $dayData) {
        $h = $dayData['date']['hijri'] ?? null;
        $g = $dayData['date']['gregorian'] ?? null;
        if (!$h) continue;
        $monthNumber = intval($h['month']['number']);
        if ($monthNumber !== $hijri_month) continue;
        $day = intval($h['day']);
        $events = [];
        if (!empty($h['holidays'])) {
          foreach ($h['holidays'] as $holiday) $events[] = $holiday;
        }
        if (!empty($g['weekday']['en']) && strtolower($g['weekday']['en']) === 'friday') $events[] = 'Jummah';
        if ($events) $calendar[$day] = $events;
      }
    }
  }
} catch (Exception $e) {
  // ignore
}

// Merge events
$important = [];
if (isset($monthly_events[$hijri_month])) $important = $monthly_events[$hijri_month];
foreach ($calendar as $d => $evs) {
  if (!isset($important[$d])) $important[$d] = [];
  $important[$d] = array_values(array_unique(array_merge($important[$d], $evs)));
}

// Add astronomical approximations
if (!isset($important[1])) $important[1] = [];
if (!in_array('New Moon', $important[1])) $important[1][] = 'New Moon';
if (!isset($important[14])) $important[14] = [];
if (!in_array('Full Moon', $important[14])) $important[14][] = 'Full Moon';

echo json_encode([
  'success' => true,
  'hijri_year' => $hijri_year,
  'hijri_month' => $hijri_month,
  'events' => $important
]);
