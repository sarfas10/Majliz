<?php
// api/adhan.php - fetch today's adhan times via Aladhan API
header('Content-Type: application/json; charset=utf-8');

$city = isset($_GET['city']) ? $_GET['city'] : 'Calicut';
$country = isset($_GET['country']) ? $_GET['country'] : 'India';
$method = isset($_GET['method']) ? intval($_GET['method']) : 2;

$today = date('d-m-Y');
$url = "http://api.aladhan.com/v1/timingsByCity/{$today}";
$params = http_build_query(['city'=>$city,'country'=>$country,'method'=>$method]);

$ch = curl_init("{$url}?{$params}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
  echo json_encode(['success'=>false,'message'=>'Request failed']);
  exit;
}

$data = json_decode($response, true);
if ($http_code === 200 && isset($data['code']) && $data['code'] === 200 && isset($data['data']['timings'])) {
  $t = $data['data']['timings'];
  $result = [
    'success' => true,
    'timings' => [
      'Fajr' => $t['Fajr'] ?? '',
      'Dhuhr' => $t['Dhuhr'] ?? '',
      'Asr' => $t['Asr'] ?? '',
      'Maghrib' => $t['Maghrib'] ?? '',
      'Isha' => $t['Isha'] ?? ''
    ]
  ];
  echo json_encode($result);
  exit;
}

echo json_encode(['success'=>false,'message'=>'No timings','raw'=>$data]);
