<?php
// api/rss.php - simple RSS aggregator (SimpleXML)
header('Content-Type: application/json; charset=utf-8');

$feeds = [
  "https://www.aljazeera.com/xml/rss/all.xml",
  // Add more RSS feed URLs here
];

$max_age_days = 2;
$limit = 12;
$articles = [];

foreach ($feeds as $feedUrl) {
  try {
    $raw = @file_get_contents($feedUrl);
    if ($raw === false) continue;
    $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) continue;

    // items
    $items = [];
    if (isset($xml->channel->item)) $items = $xml->channel->item;
    elseif (isset($xml->entry)) $items = $xml->entry;

    foreach ($items as $entry) {
      $title = (string)($entry->title ?? '');
      $link = '';
      if (isset($entry->link['href'])) $link = (string)$entry->link['href'];
      if (!$link && isset($entry->link)) $link = (string)$entry->link;
      if (!$link && isset($entry->guid)) $link = (string)$entry->guid;

      $description = (string)($entry->description ?? $entry->summary ?? '');
      $published_raw = (string)($entry->pubDate ?? $entry->published ?? '');
      $published_ts = strtotime($published_raw);
      if ($published_ts === false) $published_ts = time();
      $age_days = (time() - $published_ts) / 86400;
      if ($age_days > $max_age_days) continue;

      $image = null;
      // media:content
      $media = $entry->children('media', true);
      if ($media && isset($media->content)) {
        foreach ($media->content as $mc) {
          $attrs = $mc->attributes();
          if (isset($attrs['url'])) { $image = (string)$attrs['url']; break; }
        }
      }
      // enclosure
      if (!$image && isset($entry->enclosure)) {
        $attrs = $entry->enclosure->attributes();
        if (isset($attrs['url'])) $image = (string)$attrs['url'];
      }
      // <img> in description
      if (!$image && preg_match('/<img.*?src=["\'](.*?)["\']/', $description, $m)) $image = $m[1];

      $articles[] = [
        'title' => $title,
        'description' => trim(strip_tags($description)),
        'url' => $link,
        'image' => $image,
        'published' => date('Y-m-d H:i', $published_ts)
      ];
    }

  } catch (Exception $e) {
    // ignore
  }
}

usort($articles, function($a,$b){ return strtotime($b['published']) - strtotime($a['published']); });
$articles = array_slice($articles, 0, $limit);
echo json_encode(['success'=>true, 'articles'=>$articles]);
